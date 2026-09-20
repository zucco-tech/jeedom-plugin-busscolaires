<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/**
 * Le plugin porte les réglages et l'affichage ; le démon Python porte le
 * calcul (fiche horaire officielle, index GTFS, alertes, solutions de
 * repli). Le démon n'écoute que sur 127.0.0.1 : rien ne l'atteint
 * directement depuis l'extérieur.
 */
class busscolaires extends eqLogic
{
    const PORT_DEFAUT = 55810;

    /* Le seuil au-delà duquel un car qui n'est pas passé est déclaré
       perdu. Le service applique le même : ils doivent rester d'accord. */
    const RETARD_MAX_MIN = 15;

    /* Les plugins susceptibles de porter un emploi du temps. On les
       détecte, on ne les exige pas : sans eux, les heures réglées à la
       main font foi. */
    const PLUGINS_EMPLOI_DU_TEMPS = array('pronote');

    /* Les commandes créées pour chaque équipement.
       logicalId => [nom affiché, sous-type, unité, champ de /api/resume] */
    const COMMANDES = array(
        'sens' => array('Sens', 'string', '', 'sens'),
        'car' => array('Car', 'string', '', 'car'),
        'heure_car' => array('Passage du car', 'string', '', 'heure_car'),
        'heure_depart' => array('Départ à pied', 'string', '', 'heure_depart_a_pied'),
        'minutes_avant' => array('Avant de partir', 'numeric', 'min', 'minutes_avant_depart'),
        'arret' => array('Arrêt', 'string', '', 'arret'),
        'destination' => array('Destination', 'string', '', 'destination'),
        'heure_arrivee' => array('Arrivée', 'string', '', 'heure_arrivee'),
        'fin_cours' => array('Fin des cours', 'string', '', 'fin_cours'),
        'perturbations' => array('Perturbations', 'numeric', '', 'perturbations'),
        'jour_de_classe' => array('Jour de classe', 'binary', '', 'jour_de_classe'),
        'texte' => array('Résumé', 'string', '', 'texte'),
        /* Champ vide : cette valeur ne vient pas du résumé mais du
           plugin lui-même, qui sait si le lien est fait. */
        'source_horaires' => array('Origine des horaires', 'string', '', ''),
    );

    /* Les commandes du rôle « plan B » : la meilleure solution de repli.
       Elles n'existent que sur un équipement qui porte ce rôle. */
    /* Les noms sont préfixés : Jeedom les veut uniques par équipement,
       et « Arrivée » existe déjà pour le trajet. */
    const COMMANDES_PLANB = array(
        'solution_depart' => array('Repli : partir à', 'string'),
        'solution_arrivee' => array('Repli : arrivée', 'string'),
        'solution_duree' => array('Repli : durée', 'string'),
        'solution_resume' => array('Repli : par où', 'string'),
        'solutions_nb' => array('Solutions trouvées', 'numeric'),
    );

    /* Les réglages du trajet, tenus par Jeedom et poussés au démon.
       clé => valeur par défaut. Ce sont les seuls réglages : l'enfant
       n'y a pas accès, seul l'administrateur Jeedom les voit. */
    const REGLAGES = array(
        'ligne' => '',
        'arret_maison' => '',
        'arret_ecole' => '',
        'adresse' => '',
        'adresse_lat' => 0.0,
        'adresse_lon' => 0.0,
        'adresse_maison' => '',
        'maison_lat' => 0.0,
        'maison_lon' => 0.0,
        'marche_m_min' => 80,
        'correspondance_min' => 4,
        'preavis_min' => 10,
        'marche_max_min' => 15,
        'marge_depart_min' => 10,
        'sortie_defaut' => '16:05',
        'sortie_lundi' => '16:05',
        'sortie_mardi' => '16:05',
        'sortie_mercredi' => '12:00',
        'sortie_jeudi' => '16:05',
        'sortie_vendredi' => '16:05',
    );

    /* Bornes des réglages numériques, pour ne pas laisser saisir une
       vitesse de marche absurde. clé => [min, max] */
    const BORNES = array(
        'marche_m_min' => array(40, 140),
        'correspondance_min' => array(1, 30),
        'preavis_min' => array(1, 120),
        'marche_max_min' => array(2, 30),
        'marge_depart_min' => array(0, 30),
    );

    // ----------------------------------------------------------------
    // Dépendances
    // ----------------------------------------------------------------

    private static function dossierVenv()
    {
        return realpath(__DIR__ . '/../..') . '/resources/python_venv';
    }

    public static function dependancy_info()
    {
        $retour = array();
        $retour['log'] = 'busscolaires_update';
        $retour['progress_file'] = '/tmp/jeedom/busscolaires/dependency';
        $retour['state'] = 'ok';
        if (file_exists($retour['progress_file'])) {
            $retour['state'] = 'in_progress';
            /* Le coeur s'en sert pour repérer une installation
               bloquée au-delà de maxDependancyInstallTime. */
            $retour['duration'] = round(
                (time() - filemtime($retour['progress_file'])) / 60);
        } elseif (!file_exists(self::dossierVenv() . '/bin/python3')) {
            $retour['state'] = 'nok';
        } elseif (!self::modulesPresents()) {
            $retour['state'] = 'nok';
        }
        return $retour;
    }

    public static function dependancy_install()
    {
        log::remove('busscolaires_update');
        return array(
            'script' => realpath(__DIR__ . '/../..') . '/resources/install_apt.sh',
            'log' => log::getPathToLog('busscolaires_update'),
        );
    }

    /* Un venv peut exister tout en étant incomplet : on vérifie que les
       modules dont le démon a besoin s'importent vraiment. */
    private static function modulesPresents()
    {
        $python = self::dossierVenv() . '/bin/python3';
        if (!file_exists($python)) {
            return false;
        }
        $code = 'import fastapi, uvicorn, httpx, pywebpush, cryptography;'
              . ' import google.transit.gtfs_realtime_pb2';
        exec(escapeshellarg($python) . ' -c ' . escapeshellarg($code)
             . ' 2>&1', $sortie, $rc);
        return $rc === 0;
    }

    // ----------------------------------------------------------------
    // Démon
    // ----------------------------------------------------------------

    public static function port()
    {
        $p = (int) config::byKey('port', 'busscolaires', self::PORT_DEFAUT);
        return ($p > 1024 && $p < 65536) ? $p : self::PORT_DEFAUT;
    }

    private static function fichierPid()
    {
        return jeedom::getTmpFolder('busscolaires') . '/deamon.pid';
    }

    /* Le dossier de données : l'index GTFS, le profil projeté et les clés
       de notification. Il est reconstruit tout seul s'il disparaît. */
    public static function dossierDonnees()
    {
        $d = realpath(__DIR__ . '/../..') . '/data';
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    /**
     * Le processus existe-t-il, et est-ce bien notre démon ?
     *
     * On lit /proc plutôt que posix_getsid() : cette fonction renvoie un
     * identifiant de session, et un démon relancé depuis PHP dans un
     * conteneur se retrouve en session 0. Zéro est une session valide,
     * mais faux au sens booléen — le test naïf supprimait donc le fichier
     * de PID d'un démon parfaitement vivant.
     */
    private static function processusVivant($numero)
    {
        $numero = (int) $numero;
        if ($numero <= 0) {
            return false;
        }
        $cmdline = '/proc/' . $numero . '/cmdline';
        if (!file_exists($cmdline)) {
            return false;
        }
        /* Un numéro de processus se réemploie : on vérifie que c'est le
           nôtre et pas un inconnu qui l'a récupéré. Et on exige un
           interpréteur Python qui exécute le script : un shell ou un
           éditeur dont la ligne de commande cite demon.py n'est pas le
           démon, et deamon_stop() ne doit jamais le prendre pour lui. */
        $ligne = @file_get_contents($cmdline);
        if ($ligne === false || $ligne === '') {
            return false;
        }
        $args = explode("\0", rtrim($ligne, "\0"));
        return count($args) >= 2
            && strpos(basename($args[0]), 'python') === 0
            && substr($args[1], -strlen('/resources/card/demon.py')) === '/resources/card/demon.py';
    }

    public static function deamon_info()
    {
        $retour = array();
        $retour['log'] = 'busscolaires';
        $retour['state'] = 'nok';
        $retour['launchable'] = 'ok';

        $pid = self::fichierPid();
        if (file_exists($pid)) {
            $numero = trim(file_get_contents($pid));
            if (self::processusVivant($numero)) {
                $retour['state'] = 'ok';
            } elseif ($numero !== '') {
                /* Fichier orphelin d'un démon disparu : on nettoie. On ne
                   touche pas à un fichier vide, qui est peut-être en train
                   d'être écrit à l'instant même. */
                @unlink($pid);
            }
        }

        /* Le fichier de PID peut manquer alors que le démon tourne très
           bien. Répondre « mort » dans ce cas coûte cher : Jeedom demande
           un redémarrage, et notre deamon_start() commence par tuer le
           démon en place — on coupe donc un service en bonne santé. On va
           donc vérifier auprès du système avant de le déclarer mort, et
           on réécrit le fichier au passage. */
        if ($retour['state'] !== 'ok') {
            $vivant = self::pidParLigneDeCommande();
            if ($vivant > 0) {
                $retour['state'] = 'ok';
                @file_put_contents($pid, (string) $vivant);
                log::add('busscolaires', 'info',
                    __('Démon retrouvé par sa ligne de commande', __FILE__)
                    . ' (PID ' . $vivant . ') : ' . __('fichier de PID réécrit', __FILE__));
            }
        }

        if (self::dependancy_info()['state'] != 'ok') {
            $retour['launchable'] = 'nok';
            $retour['launchable_message'] = __('Dépendances non installées', __FILE__);
        }
        return $retour;
    }

    public static function deamon_start($_force = false)
    {
        /* Deuxième verrou, après celui de deamon_info() : on ne tue pas un
           démon qui répond. Démarrer ce qui tourne déjà n'apporte rien, et
           coûterait la minute où elle attend son car. Un vrai redémarrage
           demandé depuis l'interface passe, lui, par deamon_stop() : il
           n'arrive donc jamais ici avec un démon vivant. */
        if (!$_force && self::deamon_info()['state'] === 'ok' && self::joignable()) {
            log::add('busscolaires', 'info',
                __('Démon déjà en route : on n\'y touche pas', __FILE__));
            return true;
        }

        self::deamon_stop();
        $info = self::deamon_info();
        if ($info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__)
                . ' : ' . ($info['launchable_message'] ?? ''));
        }

        /* Les clés de notification survivent à une mise à jour du plugin
           grâce à leur copie dans la configuration Jeedom. */
        self::restaurerVapid();

        $chemin = realpath(__DIR__ . '/../..') . '/resources/card';
        $cmd = system::getCmdPython3('busscolaires') . escapeshellarg($chemin . '/demon.py');
        $cmd .= ' --port ' . self::port();
        $cmd .= ' --pid ' . escapeshellarg(self::fichierPid());
        $cmd .= ' --data ' . escapeshellarg(self::dossierDonnees());
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel('busscolaires'));

        /* Les communes à indexer sont un réglage, pas une constante : le
           réseau entier pèse des millions d'horaires, et chacun ne
           traverse que les siennes. Vide, le démon prend tout. */
        $communes = trim((string) config::byKey('communes', 'busscolaires', ''));
        $env = $communes !== ''
            ? 'COMMUNES=' . escapeshellarg($communes) . ' ' : '';

        log::add('busscolaires', 'info', __('Démarrage du démon', __FILE__) . ' : ' . $cmd);
        exec($env . $cmd . ' >> ' . log::getPathToLog('busscolaires_daemon') . ' 2>&1 &');

        for ($i = 0; $i < 30; $i++) {
            if (self::deamon_info()['state'] == 'ok') {
                break;
            }
            sleep(1);
        }
        if ($i >= 30) {
            log::add('busscolaires', 'error',
                __('Impossible de démarrer le démon, consultez les logs', __FILE__),
                'unableStartDeamon');
            return false;
        }

        /* Le démon attend l'API : on laisse uvicorn finir de se lier
           avant de lui parler. */
        for ($i = 0; $i < 20; $i++) {
            if (self::joignable()) {
                break;
            }
            sleep(1);
        }

        message::removeAll('busscolaires', 'unableStartDeamon');
        self::sauverVapid();
        self::verifierIndex();
        log::add('busscolaires', 'info', __('Démon démarré', __FILE__));
        return true;
    }

    /**
     * Le démon, retrouvé par ce qu'il exécute.
     *
     * Le fichier de PID est une commodité, pas une preuve : il peut être
     * effacé, vidé ou écrit à moitié. La ligne de commande, elle, décrit
     * ce qui tourne vraiment.
     *
     * @return int le PID, ou 0 si aucun démon ne tourne
     */
    public static function pidParLigneDeCommande()
    {
        $tous = self::pidsParLigneDeCommande();
        return count($tous) ? $tous[0] : 0;
    }

    /**
     * Tous les démons en vie, vérifiés un par un : pgrep -f ne sert qu'à
     * dégrossir, il renvoie aussi les shells qui citent le chemin.
     *
     * @return int[]
     */
    private static function pidsParLigneDeCommande()
    {
        $trouves = array();
        $sortie = array();
        @exec('pgrep -f ' . escapeshellarg('busscolaires/resources/card/demon.py')
            . ' 2>/dev/null', $sortie);
        foreach ($sortie as $ligne) {
            $numero = trim($ligne);
            if ($numero !== '' && ctype_digit($numero)
                    && self::processusVivant($numero)) {
                $trouves[] = (int) $numero;
            }
        }
        return $trouves;
    }

    public static function deamon_stop()
    {
        $pid = self::fichierPid();
        if (file_exists($pid)) {
            $numero = trim(file_get_contents($pid));
            if (self::processusVivant($numero)) {
                system::kill((int) $numero);
            }
            @unlink($pid);
        }
        /* Un démon orphelin, dont le fichier de PID a disparu, tient
           encore le port : on le retrouve par sa ligne de commande. Pas de
           system::kill() sur un motif : il abat tout processus dont la
           ligne de commande contient le motif, shells et éditeurs compris.
           On ne vise que des numéros vérifiés. */
        foreach (self::pidsParLigneDeCommande() as $numero) {
            posix_kill($numero, 15);
        }
        for ($i = 0; $i < 10 && count(self::pidsParLigneDeCommande()) > 0; $i++) {
            usleep(300000);
        }
        foreach (self::pidsParLigneDeCommande() as $numero) {
            posix_kill($numero, 9);
        }
    }

    // ----------------------------------------------------------------
    // Dialogue avec le démon
    // ----------------------------------------------------------------

    /**
     * Appelle l'API du démon sur la boucle locale.
     * Lève une exception si le démon ne répond pas ou répond mal.
     */
    public static function appeler($chemin, $methode = 'GET', $corps = null,
                                   $timeout = 20)
    {
        $url = 'http://127.0.0.1:' . self::port() . '/' . ltrim($chemin, '/');
        $ch = curl_init($url);
        $entetes = array('Accept: application/json');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $methode,
        ));
        if ($corps !== null) {
            $entetes[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corps));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $entetes);

        $reponse = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erreur = curl_error($ch);
        curl_close($ch);

        if ($reponse === false) {
            throw new Exception(__('Démon injoignable : ', __FILE__) . $erreur);
        }
        $donnees = json_decode($reponse, true);
        if ($code >= 400) {
            $detail = is_array($donnees) ? ($donnees['detail'] ?? $reponse) : $reponse;
            throw new Exception(__('Le démon a répondu ', __FILE__) . $code
                . ' : ' . (is_string($detail) ? $detail : json_encode($detail)));
        }
        if (!is_array($donnees)) {
            throw new Exception(__('Réponse illisible du démon', __FILE__));
        }
        return $donnees;
    }

    public static function joignable()
    {
        try {
            self::appeler('api/etat', 'GET', null, 5);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ----------------------------------------------------------------
    // Le trajet — porté par l'équipement, pas par le plugin
    //
    // Un équipement, un enfant, un trajet. C'est ce qui permet deux
    // enfants, deux écoles ou deux lignes sur le même Jeedom. Le service
    // ne mémorise rien : chaque appel lui joint le trajet concerné.
    // ----------------------------------------------------------------

    /**
     * Qui se sert de cet emploi du temps ?
     *
     * Destinée aux plugins scolaires : elle leur permet d'annoncer chez
     * eux que leur emploi du temps est lu, sans rien savoir du bus. Le
     * sens de la question est le bon — c'est celui qui lit qui répond,
     * pas celui qui est lu qui doit tenir un registre.
     *
     * @param  int   $_eqLogicId  l'équipement portant l'emploi du temps
     * @return array les noms des équipements qui s'en servent
     */
    public static function utilisateursDe($_eqLogicId)
    {
        $out = array();
        foreach (self::byType('busscolaires', true) as $eq) {
            /* Un équipement qui reprend le trajet d'un autre ne compte
               pas deux fois : seul celui qui porte le réglage répond. */
            if ($eq->getConfiguration('trajet_de', '') !== '') {
                continue;
            }
            $src = $eq->equipementEmploiDuTemps();
            if (is_object($src) && $src->getId() == $_eqLogicId) {
                $out[] = $eq->getName();
            }
        }
        return $out;
    }

    /**
     * L'équipement d'un compte Jeedom.
     *
     * Sa fille a son propre compte sur la maison connectée. Plutôt que
     * de lui confier un lien secret de plus, on reconnaît son compte :
     * l'adulte le désigne dans la configuration de l'équipement, et la
     * page s'ouvre pour elle sans rien d'autre à retenir.
     *
     * Rien n'est deviné : sans désignation explicite, personne n'est
     * reconnu. Un prénom qui ressemble n'est pas une preuve d'identité.
     *
     * @param  object|null $_user  le compte connecté
     * @return object|null l'équipement qui lui revient, ou null
     */
    public static function parCompte($_user)
    {
        if (!is_object($_user)) {
            return null;
        }
        foreach (self::byType('busscolaires', true) as $eq) {
            if ((string) $eq->getConfiguration('compte_enfant', '')
                    !== (string) $_user->getId()) {
                continue;
            }
            /* Le compte doit aussi avoir le droit de voir l'équipement :
               une désignation ne contourne pas les droits de Jeedom. */
            if ($eq->hasRight('r', $_user)) {
                return $eq;
            }
        }
        return null;
    }

    /**
     * L'équipement à montrer à un administrateur qui veut voir la page.
     *
     * Un adulte administrateur voit déjà tout dans Jeedom : lui refuser
     * l'aperçu de la page de son enfant ne protège rien, et l'oblige à
     * jongler entre deux comptes pour vérifier ce qu'elle a sous les yeux.
     *
     * @param  mixed $_id  un équipement précis, ou null pour le seul qui
     *                     désigne un compte
     * @return object|null
     */
    public static function pourApercu($_id = null)
    {
        if ($_id !== null && $_id !== '') {
            $eq = eqLogic::byId($_id);
            return (is_object($eq) && $eq->getEqType_name() == 'busscolaires')
                ? $eq : null;
        }
        $candidats = array();
        foreach (self::byType('busscolaires', true) as $eq) {
            if ((string) $eq->getConfiguration('compte_enfant', '') !== '') {
                $candidats[] = $eq;
            }
        }
        return count($candidats) === 1 ? $candidats[0] : null;
    }

    /**
     * Les enfants que Jeedom connaît, par leur emploi du temps.
     *
     * Un équipement de plugin scolaire vaut un enfant : c'est la notion
     * qui parle à l'utilisateur, bien plus que « source d'emploi du
     * temps ». On les liste pour que le bus demande simplement « lequel ».
     */
    public static function enfantsConnus()
    {
        $out = array();
        foreach (self::PLUGINS_EMPLOI_DU_TEMPS as $id) {
            if (!class_exists($id)) {
                continue;
            }
            foreach (eqLogic::byType($id, true) as $e) {
                if (is_object($e->getCmd(null, 'timetable_week_html'))) {
                    $out[$e->getId()] = $e;
                }
            }
        }
        return $out;
    }

    /** Le nom de l'enfant rattaché, ou une chaîne vide. */
    public function nomEnfant()
    {
        $e = $this->equipementEmploiDuTemps();
        return is_object($e) ? $e->getName() : '';
    }

    /**
     * L'équipement qui porte l'emploi du temps de cet enfant.
     *
     * On ne fait rien remplir : le plugin d'emploi du temps est détecté
     * comme z2m détecte mqtt2, et l'équipement est retrouvé tout seul.
     * Un réglage explicite ne sert qu'à départager plusieurs enfants, ou
     * à refuser le lien.
     *
     * Le lien se rétablit donc de lui-même : supprimer un widget, en
     * recréer un, changer d'objet — rien de tout cela ne le casse.
     */
    public function equipementEmploiDuTemps()
    {
        /* Un équipement qui reprend le trajet d'un autre suit aussi son
           emploi du temps. */
        $parent = $this->getConfiguration('trajet_de', '');
        if ($parent !== '' && $parent != $this->getId()) {
            $autre = eqLogic::byId($parent);
            if (is_object($autre) && $autre->getEqType_name() == 'busscolaires'
                    && $autre->getConfiguration('trajet_de', '') === '') {
                return $autre->equipementEmploiDuTemps();
            }
        }

        $choix = (string) $this->getConfiguration('pronote_eqlogic', '');
        if ($choix === 'aucun') {
            return null;          // refus explicite
        }
        if ($choix !== '') {
            $force = eqLogic::byId($choix);
            return (is_object($force) && $force->getIsEnable()) ? $force : null;
        }

        /* Détection, à la manière de z2m avec mqtt2 : on ne configure
           rien, on regarde ce qui est là. */
        $candidats = array_values(self::enfantsConnus());
        if (!$candidats) {
            return null;
        }
        if (count($candidats) === 1) {
            return $candidats[0];
        }

        /* Plusieurs enfants : on ne devine pas.
           Rapprocher par le nom serait tentant — « Car de X » trouve
           l'enfant nommé « X » — mais c'est fragile deux fois : renommer
           l'équipement casserait le lien sans prévenir, et un prénom peut
           en contenir un autre. On demande une fois, et le choix est gardé
           par identifiant : il survit à tous les renommages. */
        return null;
    }

    /**
     * Pourquoi aucun emploi du temps n'est retenu.
     *
     * Distingue le silence — aucun plugin scolaire — de l'attente d'un
     * choix, qui elle demande une action.
     */
    public function etatEmploiDuTemps()
    {
        if ($this->getConfiguration('pronote_eqlogic', '') === 'aucun') {
            return 'refuse';
        }
        if (is_object($this->equipementEmploiDuTemps())) {
            return 'lie';
        }
        $n = count(self::enfantsConnus());
        if ($n === 0) {
            return 'aucun_plugin';
        }
        return 'a_choisir';        // plusieurs enfants, aucun désigné
    }

    /**
     * Les heures de fin de cours lues chez Pronote, ou null.
     *
     * Le lien est facultatif : sans équipement désigné, les heures
     * réglées à la main font foi. C'est délibéré — on peut vouloir s'en
     * passer, et un emploi du temps qui ne se synchronise plus ne doit
     * pas faire dérailler le car.
     */
    public function heuresPronote()
    {
        /* Un équipement qui reprend le trajet d'un autre reprend aussi
           son emploi du temps : sinon le Plan B annoncerait « réglé à la
           main » pendant que le Quai lit Pronote. */
        $source = $this->getConfiguration('trajet_de', '');
        if ($source !== '' && $source != $this->getId()) {
            $autre = eqLogic::byId($source);
            if (is_object($autre) && $autre->getEqType_name() == 'busscolaires'
                    && $autre->getConfiguration('trajet_de', '') === '') {
                return $autre->heuresPronote();
            }
        }

        $source = $this->equipementEmploiDuTemps();
        if (!is_object($source)) {
            return null;
        }
        /* L'emploi du temps de la semaine suffit : il porte les sept
           jours à venir, chacun nommé. */
        $cmd = $source->getCmd(null, 'timetable_week_html');
        if (!is_object($cmd)) {
            return null;
        }
        $lu = self::finsParDate((string) $cmd->execCmd());
        if (!$lu['jours']) {
            return null;
        }
        return array('heures' => $lu['jours'], 'dates' => $lu['dates'],
                     'annules' => $lu['annules'],
                     'lu' => $cmd->getCollectDate(),
                     'source' => $source->getName());
    }

    /**
     * Extrait l'heure de fin de chaque journée d'un emploi du temps.
     *
     * Le plugin Pronote rend ses cours en HTML mais chaque créneau porte
     * ses bornes en clair — data-start et data-end, au format HHMM. On
     * lit donc des attributs, pas du texte : rien à deviner, et le jour
     * finit au plus grand data-end.
     */
    public static function heuresDepuisEdt($html)
    {
        return self::finsParDate($html)['jours'];
    }

    /**
     * Les heures de fin de journée, date par date.
     *
     * L'emploi du temps est balisé jour par jour avec sa date exacte —
     * un <ul data-date="2026-09-11"> — et chaque cours porte ses bornes
     * en attributs. On lit donc des dates réelles, pas des noms de jours :
     * un vendredi de cette semaine n'est pas celui de la suivante.
     *
     * Les cours annulés sont écartés : professeur absent, grève, sortie
     * scolaire — c'est justement là que l'heure de sortie change, et
     * prendre le dernier cours sans regarder s'il a lieu la manquerait.
     */
    public static function finsParDate($html)
    {
        $vide = array('dates' => array(), 'jours' => array(), 'annules' => 0);
        if (trim((string) $html) === '') {
            return $vide;
        }
        $semaine = array('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi',
                         'samedi', 'dimanche');
        $dates = array();
        $jours = array();
        $annules = 0;

        if (!preg_match_all('#<ul[^>]*data-date="(\d{4}-\d{2}-\d{2})"[^>]*>(.*?)</ul>#s',
                            $html, $blocs, PREG_SET_ORDER)) {
            return $vide;
        }
        foreach ($blocs as $b) {
            $date = $b[1];
            $fin = '';
            if (preg_match_all('#<li([^>]*)>#', $b[2], $items, PREG_SET_ORDER)) {
                foreach ($items as $li) {
                    $attrs = $li[1];
                    if (strpos($attrs, 'cancelled') !== false) {
                        $annules++;
                        continue;   // ce cours n'a pas lieu
                    }
                    if (preg_match('/data-end="(\d{4})"/', $attrs, $m)
                            && $m[1] > $fin) {
                        $fin = $m[1];
                    }
                }
            }
            if ($fin === '') {
                continue;           // jour vide, ou entièrement annulé
            }
            $heure = substr($fin, 0, 2) . ':' . substr($fin, 2, 2);
            $dates[$date] = $heure;
            /* Le premier passage d'un jour de la semaine est le plus
               proche : c'est celui qui compte. */
            $nom = $semaine[(int) date('N', strtotime($date)) - 1];
            if (!isset($jours[$nom])) {
                $jours[$nom] = $heure;
            }
        }
        return array('dates' => $dates, 'jours' => $jours, 'annules' => $annules);
    }


    /** Le trajet de cet équipement, valeurs par défaut comprises. */
    public function trajet()
    {
        /* Un équipement peut reprendre le trajet d'un autre : c'est ce
           qui permet de poser plusieurs widgets pour le même enfant sans
           saisir deux fois ses arrêts et ses horaires. */
        $source = $this->getConfiguration('trajet_de', '');
        if ($source !== '' && $source != $this->getId()) {
            $autre = eqLogic::byId($source);
            if (is_object($autre) && $autre->getEqType_name() == 'busscolaires'
                    && $autre->getConfiguration('trajet_de', '') === '') {
                return $autre->trajet();
            }
        }

        $t = array();
        foreach (self::REGLAGES as $cle => $defaut) {
            $v = $this->getConfiguration($cle, '');
            if ($v === '' || $v === null) {
                $v = $defaut;
            }
            if (is_int($defaut)) {
                $v = (int) $v;
                if (isset(self::BORNES[$cle])) {
                    list($min, $max) = self::BORNES[$cle];
                    $v = max($min, min($max, $v));
                }
            } elseif (is_float($defaut)) {
                $v = (float) $v;
            }
            $t[$cle] = $v;
        }

        /* Pronote, quand le lien est fait : l'emploi du temps réel prime
           sur les heures réglées à la main. Un jour absent de l'emploi du
           temps garde son réglage. */
        $edt = $this->heuresPronote();
        if ($edt) {
            foreach ($edt['heures'] as $jour => $heure) {
                if (isset($t['sortie_' . $jour])) {
                    $t['sortie_' . $jour] = $heure;
                }
            }
        }

        /* La maison : si l'équipement ne la précise pas, on se fie aux
           coordonnées du domicile réglées dans Jeedom. C'est la même
           maison, autant ne pas la saisir deux fois. */
        if (empty($t['maison_lat']) || empty($t['maison_lon'])) {
            $lat = (float) config::byKey('info::latitude');
            $lon = (float) config::byKey('info::longitude');
            if ($lat && $lon) {
                $t['maison_lat'] = $lat;
                $t['maison_lon'] = $lon;
                if ($t['adresse_maison'] === '') {
                    $t['adresse_maison'] = trim(config::byKey('info::address') . ' '
                        . config::byKey('info::city'));
                }
            }
        }

        /* Une fin de cours poussée pour aujourd'hui — un dernier cours
           qui saute — prime sur le réglage hebdomadaire. */
        $poussee = $this->getConfiguration('sortie_poussee', '');
        if (is_array($poussee) && ($poussee['date'] ?? '') === date('Y-m-d')
                && ($poussee['heure'] ?? '') !== '') {
            $jours = array('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi',
                           'samedi', 'dimanche');
            $cle = 'sortie_' . $jours[(int) date('N') - 1];
            if (isset($t[$cle])) {
                $t[$cle] = $poussee['heure'];
            }
            $t['sortie_defaut'] = $poussee['heure'];
        }
        return $t;
    }

    /** Le trajet sous forme de paramètres d'URL, pour le service. */
    public function parametresTrajet()
    {
        return http_build_query($this->trajet());
    }

    /* L'index GTFS est un cache : s'il manque, on le reconstruit sans
       rien demander à personne. */
    public static function verifierIndex()
    {
        try {
            $etat = self::appeler('api/etat', 'GET', null, 10);
            if (empty($etat['index_pret'])) {
                log::add('busscolaires', 'info',
                    __('Index GTFS absent : reconstruction lancée', __FILE__));
                self::appeler('api/index/reconstruire', 'POST', array(), 10);
            }
        } catch (Exception $e) {
            log::add('busscolaires', 'debug',
                'index non vérifié : ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // Clés de notification, sauvegardées hors du dossier du plugin
    // ----------------------------------------------------------------

    private static function cheminVapid()
    {
        return self::dossierDonnees() . '/vapid.json';
    }

    public static function sauverVapid()
    {
        $f = self::cheminVapid();
        if (file_exists($f)) {
            $contenu = file_get_contents($f);
            if ($contenu !== false && json_decode($contenu, true) !== null) {
                config::save('vapid', $contenu, 'busscolaires');
            }
        }
    }

    public static function restaurerVapid()
    {
        $f = self::cheminVapid();
        if (file_exists($f)) {
            return;
        }
        $sauvegarde = config::byKey('vapid', 'busscolaires', '');
        if ($sauvegarde != '' && json_decode($sauvegarde, true) !== null) {
            file_put_contents($f, $sauvegarde);
            chmod($f, 0600);
            log::add('busscolaires', 'info',
                __('Clés de notification restaurées', __FILE__));
        }
    }

    // ----------------------------------------------------------------
    // Lien de l'enfant — un par équipement, donc un par enfant
    // ----------------------------------------------------------------

    /* Rien n'est exposé tant que l'adulte n'a pas créé le lien. */
    public function cleEnfant()
    {
        return (string) $this->getConfiguration('cle_enfant', '');
    }

    public function genererCle()
    {
        $cle = bin2hex(random_bytes(16));
        $this->setConfiguration('cle_enfant', $cle);
        $this->save();
        log::add('busscolaires', 'info',
            __('Nouveau lien enfant créé pour ', __FILE__) . $this->getName());
        return $cle;
    }

    public function revoquerCle()
    {
        $this->setConfiguration('cle_enfant', '');
        $this->save();
        log::add('busscolaires', 'info',
            __('Lien enfant révoqué pour ', __FILE__) . $this->getName());
    }

    /**
     * L'équipement auquel appartient cette clé, ou null.
     *
     * Comparaison à temps constant sur chaque équipement : la page est
     * publique, la clé ne doit pas pouvoir se deviner octet par octet.
     */
    public static function parCle($fournie)
    {
        if (!is_string($fournie) || $fournie === '') {
            return null;
        }
        foreach (self::byType('busscolaires') as $eqLogic) {
            $attendue = $eqLogic->cleEnfant();
            if ($attendue !== '' && hash_equals($attendue, $fournie)) {
                return $eqLogic;
            }
        }
        return null;
    }

    // ----------------------------------------------------------------
    // Cron
    // ----------------------------------------------------------------

    public static function pull()
    {
        if (self::deamon_info()['state'] != 'ok') {
            return;
        }
        foreach (self::byType('busscolaires', true) as $eqLogic) {
            try {
                $eqLogic->rafraichir();
            } catch (Exception $e) {
                log::add('busscolaires', 'error',
                    $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    // ----------------------------------------------------------------
    // Équipement
    // ----------------------------------------------------------------

    public function rafraichir()
    {
        /* Le trajet voyage avec la demande : le service ne garde rien,
           et chaque équipement obtient son propre calcul. */
        $chemin = 'api/resume?' . $this->parametresTrajet();
        $sens = $this->getConfiguration('sens', 'auto');
        if ($sens === 'aller' || $sens === 'retour') {
            $chemin .= '&sens=' . urlencode($sens);
        }
        $donnees = self::appeler($chemin);

        foreach (self::COMMANDES as $logicalId => $def) {
            $champ = $def[3];
            if (!array_key_exists($champ, $donnees)) {
                continue;
            }
            $valeur = $donnees[$champ];
            if ($def[1] === 'binary') {
                $valeur = $valeur ? 1 : 0;
            } elseif ($valeur === null) {
                $valeur = '';
            }
            $this->checkAndUpdateCmd($logicalId, $valeur);
        }
        $edt = $this->heuresPronote();
        $this->checkAndUpdateCmd('source_horaires', $edt
            ? __('emploi du temps', __FILE__) . ' — ' . $edt['source']
            : __('réglage manuel', __FILE__));
        $this->setCache('edt_source', $edt ? $edt['source'] : '');
        $this->setCache('edt_lu', $edt ? $edt['lu'] : '');

        /* Deux informations qui ne méritent pas une commande, mais que
           le widget affiche : d'où viennent les horaires, et le temps de
           marche jusqu'à l'arrêt. */
        $this->setCache('fiche_officielle', !empty($donnees['fiche_officielle']));
        $this->setCache('marche_min', (int) ($donnees['marche_min'] ?? 0));
        $this->setCache('avance_min', (int) ($donnees['avance_min'] ?? 0));
        /* Le car du matin qui n'est jamais venu : le quai le dit, le
           repli propose la suite. */
        $this->setCache('car_manque', !empty($donnees['car_manque']));
        $this->setCache('heure_car_manque', (string) ($donnees['heure_car_manque'] ?? ''));
        $this->setCache('sens_manque', (string) ($donnees['sens_manque'] ?? ''));
        $this->setCache('car_suivant', (string) ($donnees['car_suivant'] ?? ''));
        $this->setCache('car_saute', (string) ($donnees['car_saute'] ?? ''));

        $role = $this->getConfiguration('role', 'quai');
        if ($role === 'planb') {
            $this->chercherSolutions($donnees);
        } elseif ($role === 'carte') {
            $this->chercherTrace();
        } elseif ($role === 'libre') {
            $this->chercherDepuisPosition();
        }

        $this->refreshWidget();
        return $donnees;
    }

    /* Un équipement neuf naît avec des valeurs plausibles plutôt qu'avec
       des champs vides : on règle ensuite ce qui diffère. */
    /**
     * Cherche comment rentrer autrement, et retient les solutions.
     *
     * On part de la fin des cours, ou de maintenant s'il est déjà plus
     * tard : proposer un trajet partant à midi quand il est seize heures
     * n'aiderait personne.
     */
    public function chercherSolutions($resume = null)
    {
        /* Le matin, on cherche à rejoindre l'école ; le soir, la maison.
           Et l'on part de maintenant, sauf le soir où l'on attend la fin
           des cours. */
        $manque = !empty($resume['car_manque']);
        $sens = $manque
            ? (($resume['sens_manque'] ?? 'aller') === 'retour' ? 'retour' : 'aller')
            : ((($resume['sens'] ?? '') === 'aller') ? 'aller' : 'retour');
        $depuis = date('H:i');
        $fin = $resume['fin_cours'] ?? '';
        if ($sens === 'retour' && !$manque && $fin !== '' && $fin > $depuis) {
            $depuis = $fin;
        }
        /* Un trajet commencé ne se rediscute pas. Sans cela, le calcul
           tourne toutes les cinq minutes et propose un autre bus alors
           qu'elle marche déjà vers le premier : le meilleur moyen de la
           perdre. On garde donc le plan en cours jusqu'à son arrivée. */
        $encours = $this->getCache('plan_en_cours', null);
        if (is_array($encours) && ($encours['date'] ?? '') === date('Y-m-d')
                && $depuis >= ($encours['depart'] ?? '99:99')
                && $depuis < ($encours['arrivee'] ?? '00:00')) {
            return $this->getCache('solutions', array());
        }

        try {
            $r = self::appeler('api/solutions?' . $this->parametresTrajet()
                . '&sens=' . $sens
                . '&heure=' . urlencode($depuis) . '&limite=2', 'GET', null, 60);
        } catch (Exception $e) {
            log::add('busscolaires', 'warning',
                $this->getName() . ' : ' . $e->getMessage());
            return array();
        }
        $trajets = $r['trajets'] ?? array();
        $premier = $trajets[0] ?? null;

        $this->checkAndUpdateCmd('solutions_nb', count($trajets));
        $this->checkAndUpdateCmd('solution_depart', $premier['depart'] ?? '');
        $this->checkAndUpdateCmd('solution_arrivee', $premier['arrivee'] ?? '');
        $this->checkAndUpdateCmd('solution_duree', $premier['duree'] ?? '');
        $this->checkAndUpdateCmd('solution_resume', $premier['resume'] ?? '');
        /* Le widget affiche le détail des trois premières : trop de
           choses pour des commandes, juste ce qu'il faut pour un cache. */
        $this->setCache('solutions', array_slice($trajets, 0, 2));
        $this->setCache('solutions_depuis', $depuis);
        $this->setCache('solutions_sens', $sens);
        $this->setCache('car_manque', $manque);
        $this->setCache('car_suivant', (string) ($resume['car_suivant'] ?? ''));
        $this->setCache('plan_en_cours', $premier
            ? array('date' => date('Y-m-d'), 'depart' => $premier['depart'],
                    'arrivee' => $premier['arrivee'])
            : null);
        return $trajets;
    }

    public function preInsert()
    {
        $this->setIsEnable(1);
        $this->setIsVisible(1);
        foreach (self::REGLAGES as $cle => $defaut) {
            if ($this->getConfiguration($cle, '') === '') {
                $this->setConfiguration($cle, $defaut);
            }
        }
        if ($this->getConfiguration('sens', '') === '') {
            $this->setConfiguration('sens', 'auto');
        }
    }

    /* Crée les commandes manquantes sans toucher à celles que
       l'utilisateur a déjà réglées. */
    public function postSave()
    {
        $ordre = 0;
        foreach (self::COMMANDES as $logicalId => $def) {
            $ordre++;
            if (is_object($this->getCmd(null, $logicalId))) {
                continue;
            }
            $cmd = new busscolairesCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setName(__($def[0], __FILE__));
            $cmd->setEqLogic_id($this->getId());
            $cmd->setType('info');
            $cmd->setSubType($def[1]);
            if ($def[2] !== '') {
                $cmd->setUnite($def[2]);
            }
            $cmd->setOrder($ordre);
            $cmd->setIsVisible(1);
            $cmd->save();
        }

        if ($this->getConfiguration('role', 'quai') === 'planb') {
            $ordre = 20;
            foreach (self::COMMANDES_PLANB as $logicalId => $def) {
                $ordre++;
                if (is_object($this->getCmd(null, $logicalId))) {
                    continue;
                }
                $cmd = new busscolairesCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setName(__($def[0], __FILE__));
                $cmd->setEqLogic_id($this->getId());
                $cmd->setType('info');
                $cmd->setSubType($def[1]);
                $cmd->setOrder($ordre);
                $cmd->setIsVisible(1);
                $cmd->save();
            }
        }

        if (!is_object($this->getCmd(null, 'definir_sortie'))) {
            $cmd = new busscolairesCmd();
            $cmd->setLogicalId('definir_sortie');
            $cmd->setName(__('Fin des cours du jour', __FILE__));
            $cmd->setEqLogic_id($this->getId());
            $cmd->setType('action');
            $cmd->setSubType('message');
            $cmd->setOrder(90);
            $cmd->setIsVisible(1);
            $cmd->save();
        }

        if (!is_object($this->getCmd(null, 'rafraichir'))) {
            $cmd = new busscolairesCmd();
            $cmd->setLogicalId('rafraichir');
            $cmd->setName(__('Rafraîchir', __FILE__));
            $cmd->setEqLogic_id($this->getId());
            $cmd->setType('action');
            $cmd->setSubType('other');
            $cmd->setOrder(0);
            $cmd->setIsVisible(0);
            $cmd->save();
        }
    }

    /**
     * Où elle se trouve, d'après Jeedom.
     *
     * Deux formes sont acceptées, parce que les plugins ne s'accordent
     * pas : une commande qui donne « latitude,longitude », ou des zones
     * de présence — le plugin mobile en pose une par lieu et range ses
     * coordonnées dans la configuration de la commande. Une zone active
     * vaut une position, à son rayon près.
     *
     * Renvoie array(lat, lon, libellé, précision en mètres) ou null.
     */
    public function positionActuelle()
    {
        $id = $this->getConfiguration('position_eqlogic', '');
        if ($id === '') {
            return null;
        }
        $source = eqLogic::byId($id);
        if (!is_object($source)) {
            return null;
        }
        $zone = null;
        foreach ($source->getCmd('info') as $cmd) {
            $conf = $cmd->getConfiguration();
            $lat = isset($conf['latitude']) ? (float) $conf['latitude'] : 0;
            $lon = isset($conf['longitude']) ? (float) $conf['longitude'] : 0;

            /* Une zone de présence : les coordonnées sont dans la
               configuration, l'état dit si elle s'y trouve. */
            if ($lat && $lon) {
                if ((string) $cmd->execCmd() === '1' || $cmd->execCmd() == 1) {
                    $rayon = (int) ($conf['radius'] ?? 150);
                    /* La plus petite zone active est la plus précise. */
                    if ($zone === null || $rayon < $zone[3]) {
                        $zone = array($lat, $lon, $cmd->getName(), $rayon);
                    }
                }
                continue;
            }

            /* Une commande qui donne directement des coordonnées. */
            $v = trim((string) $cmd->execCmd());
            if (preg_match('/^(-?\d+[.,]\d+)\s*[,;]\s*(-?\d+[.,]\d+)$/', $v, $m)) {
                return array((float) str_replace(',', '.', $m[1]),
                             (float) str_replace(',', '.', $m[2]),
                             $cmd->getName(), 30);
            }
        }
        return $zone;
    }

    /**
     * Comment rentrer d'où elle est.
     *
     * Sans position connue, on ne devine pas : le widget le dit, plutôt
     * que de proposer un trajet depuis un endroit où elle n'est pas.
     */
    public function chercherDepuisPosition()
    {
        $pos = $this->positionActuelle();
        $this->setCache('position', $pos);
        if (!$pos) {
            $this->setCache('libre', array());
            return array();
        }
        try {
            $r = self::appeler('api/solutions?' . $this->parametresTrajet()
                . '&lat=' . rawurlencode($pos[0]) . '&lon=' . rawurlencode($pos[1])
                . '&limite=3', 'GET', null, 60);
        } catch (Exception $e) {
            log::add('busscolaires', 'warning',
                $this->getName() . ' : ' . $e->getMessage());
            return array();
        }
        $this->setCache('libre', array_slice($r['trajets'] ?? array(), 0, 3));
        return $r['trajets'] ?? array();
    }

    /** Le widget « rentrer d'ici ». */
    public function htmlLibre($_version = 'dashboard')
    {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        $replace['#eqLogic_class#'] = 'eqLogic_layout_default';
        $replace['#calledFrom#'] = __CLASS__;

        $pos = $this->getCache('position', null);
        $trajets = $this->getCache('libre', array());
        if (!is_array($trajets)) {
            $trajets = array();
        }
        $trajet = $this->trajet();
        $replace['#sous_titre#'] = __('rentrer d\'ici', __FILE__)
            . ' · ' . date('H:i');

        if (!is_array($pos)) {
            $replace['#etat#'] = 'perdu';
            $replace['#badge#'] = __('sans position', __FILE__);
            $replace['#badge_mou#'] = 'mou';
            $replace['#ou#'] = '<span>' . __('Position inconnue', __FILE__) . '</span>';
            $replace['#corps#'] = '<div class="rien">'
                . __('Aucune zone active. Choisissez la source de position dans les réglages de cet équipement — le téléphone de Jeedom fait l\'affaire.', __FILE__)
                . '</div>';
            $replace['#pied_gauche#'] = $trajet['ligne'];
            $replace['#pied_droite#'] = __('rien à proposer', __FILE__);
            return template_replace(self::texteSeul($replace),
                getTemplate('core', $version, 'busscolaires_libre', 'busscolaires'));
        }

        $replace['#etat#'] = 'ok';
        $replace['#badge#'] = count($trajets) . ' ' . __('options', __FILE__);
        $replace['#badge_mou#'] = count($trajets) ? '' : 'mou';
        $replace['#ou#'] = '<b>' . htmlspecialchars($pos[2], ENT_QUOTES) . '</b>'
            . ' <span>' . __('à', __FILE__) . ' ' . (int) $pos[3] . ' m près</span>';

        $corps = '';
        foreach ($trajets as $t) {
            $premiere = $t['etapes'][0];
            $corps .= '<div class="dep">'
                . '<span class="h">' . htmlspecialchars($t['depart'], ENT_QUOTES) . '</span>'
                . '<span class="l' . (!empty($premiere['scolaire']) ? ' sco' : '') . '">'
                . htmlspecialchars($premiere['ligne'], ENT_QUOTES) . '</span>'
                . '<span class="ou">'
                . htmlspecialchars(self::court($premiere['de'], 20), ENT_QUOTES);
            if (count($t['etapes']) > 1) {
                $corps .= ' + ' . htmlspecialchars($t['etapes'][1]['ligne'], ENT_QUOTES);
            }
            $corps .= '</span><span class="d">'
                . htmlspecialchars($t['arrivee'], ENT_QUOTES) . '</span></div>';
        }
        if ($corps === '') {
            $corps = '<div class="rien">'
                . __('Rien qui rentre depuis ici pour le moment.', __FILE__) . '</div>';
        }
        $replace['#corps#'] = $corps;
        $premier = $trajets[0] ?? null;
        $replace['#pied_gauche#'] = $premier
            ? __('à la maison à', __FILE__) . ' ' . $premier['arrivee']
            : $trajet['ligne'];
        $replace['#pied_droite#'] = $premier
            ? ($premier['marche_debut_min'] . ' ' . __('min à pied', __FILE__))
            : '';

        return template_replace(self::texteSeul($replace),
            getTemplate('core', $version, 'busscolaires_libre', 'busscolaires'));
    }

    /* Le tracé de la ligne, gardé en cache : il ne change qu'à la
       reconstruction de l'index. */
    public function chercherTrace()
    {
        $trajet = $this->trajet();
        $sien = array($trajet['arret_maison'], $trajet['arret_ecole']);

        /* On veut le tracé qui montre SON trajet, pas le plus long de la
           ligne : le sens retour dessert trente-deux arrêts mais ne passe
           pas par le sien. On essaie donc chaque sens et l'on garde celui
           qui contient ses deux arrêts. */
        /* Les circuits qu'elle emprunte réellement, si on les connaît :
           celui d'aujourd'hui, et son pendant dans l'autre sens. */
        $essais = array();
        $sien_circuit = '';
        $source = $this->getConfiguration('trajet_de', '');
        $porteur = ($source !== '') ? eqLogic::byId($source) : $this;
        if (is_object($porteur)) {
            $cmd = $porteur->getCmd(null, 'car');
            if (is_object($cmd)) {
                $sien_circuit = (string) $cmd->execCmd();
            }
        }
        if ($sien_circuit !== '') {
            $essais[] = 'parcours=' . urlencode($sien_circuit);
            /* 02A et 02R sont les deux moitiés d'un même circuit. */
            $jumeau = preg_replace_callback('/([AR])$/', function ($m) {
                return $m[1] === 'A' ? 'R' : 'A';
            }, $sien_circuit);
            if ($jumeau !== $sien_circuit) {
                $essais[] = 'parcours=' . urlencode($jumeau);
            }
        }
        $essais[] = 'sens=A';
        $essais[] = 'sens=R';
        $essais[] = '';

        $meilleur = array();
        $score = -1;
        foreach ($essais as $quoi) {
            try {
                $r = self::appeler('api/trace?ligne=' . urlencode($trajet['ligne'])
                    . ($quoi ? '&' . $quoi : ''), 'GET', null, 40);
            } catch (Exception $e) {
                continue;
            }
            $arrets = $r['arrets'] ?? array();
            $n = 0;
            foreach ($sien as $nom) {
                foreach ($arrets as $a) {
                    if (self::memeArret($a['arret'], $nom)) {
                        $n++;
                        break;
                    }
                }
            }
            /* À nombre d'arrêts reconnus égal, le tracé le plus complet. */
            $valeur = $n * 1000 + count($arrets);
            if ($valeur > $score) {
                $score = $valeur;
                $meilleur = $arrets;
                $this->setCache('trace_sens', $quoi ?: 'complet');
                $this->setCache('trace_reconnus', $n);
            }
            if ($n === count($sien)) {
                break;
            }
        }
        if (!$meilleur) {
            log::add('busscolaires', 'warning',
                $this->getName() . __(' : aucun tracé pour la ligne ', __FILE__)
                . $trajet['ligne']);
        }
        $this->setCache('trace', $meilleur);
        return $meilleur;
    }

    /**
     * La carte de la ligne.
     *
     * On dessine à partir des coordonnées réelles des arrêts, sans fond
     * cartographique : rien à télécharger, et cela marche sur un Jeedom
     * qui n'a pas accès à Internet.
     */
    public function htmlCarte($_version = 'dashboard')
    {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        $replace['#eqLogic_class#'] = 'eqLogic_layout_default';
        $replace['#calledFrom#'] = __CLASS__;

        $trajet = $this->trajet();
        $arrets = $this->getCache('trace', array());
        if (!is_array($arrets)) {
            $arrets = array();
        }
        $replace['#badge#'] = $trajet['ligne'];
        $replace['#sous_titre#'] = $trajet['arret_maison'] . ' → ' . $trajet['arret_ecole'];
        $replace['#svg#'] = self::dessiner($arrets,
            array($trajet['arret_maison'], $trajet['arret_ecole']));
        $replace['#pied_gauche#'] = count($arrets)
            ? count($arrets) . ' ' . __('arrêts', __FILE__)
            : __('tracé indisponible', __FILE__);
        $communes = array();
        foreach ($arrets as $a) {
            if (!empty($a['commune'])) {
                $communes[$a['commune']] = true;
            }
        }
        $replace['#pied_droite#'] = implode(', ', array_slice(array_keys($communes), 0, 3));

        return template_replace(self::texteSeul($replace),
            getTemplate('core', $version, 'busscolaires_carte', 'busscolaires'));
    }

    /**
     * Dessine un tracé en SVG à partir de coordonnées.
     *
     * Projection équirectangulaire : à cette échelle — quinze kilomètres —
     * elle ne déforme rien de perceptible, et elle tient en trois lignes.
     * La longitude est resserrée par le cosinus de la latitude, sans quoi
     * la Provence paraîtrait deux fois trop large.
     */
    public static function dessiner($arrets, $remarquables = array(),
                                    $largeur = 240, $hauteur = 150)
    {
        $points = array();
        foreach ($arrets as $a) {
            if (isset($a['lat'], $a['lon']) && $a['lat'] && $a['lon']) {
                $points[] = $a;
            }
        }
        if (count($points) < 2) {
            return '<svg viewBox="0 0 ' . $largeur . ' ' . $hauteur . '"></svg>';
        }

        $lats = array_column($points, 'lat');
        $lons = array_column($points, 'lon');
        $lat0 = (min($lats) + max($lats)) / 2;
        $k = cos($lat0 * M_PI / 180);

        $xs = array_map(function ($l) use ($k) { return $l * $k; }, $lons);
        $x0 = min($xs); $x1 = max($xs);
        $y0 = min($lats); $y1 = max($lats);
        $marge = 20;
        $echelle = min(($largeur - 2 * $marge) / max(1e-9, $x1 - $x0),
                       ($hauteur - 2 * $marge) / max(1e-9, $y1 - $y0));
        /* On centre ce qui reste, pour que le tracé ne colle pas au bord. */
        $dx = ($largeur - ($x1 - $x0) * $echelle) / 2;
        $dy = ($hauteur - ($y1 - $y0) * $echelle) / 2;

        $projete = function ($a) use ($k, $x0, $y1, $echelle, $dx, $dy) {
            return array(($a['lon'] * $k - $x0) * $echelle + $dx,
                         ($y1 - $a['lat']) * $echelle + $dy);
        };

        $chemin = '';
        foreach ($points as $i => $a) {
            list($x, $y) = $projete($a);
            $chemin .= ($i ? ' L' : 'M') . round($x, 1) . ' ' . round($y, 1);
        }

        $svg = '<svg viewBox="0 0 ' . $largeur . ' ' . $hauteur . '" role="img"'
             . ' aria-label="' . __('Tracé de la ligne', __FILE__) . '">'
             . '<path d="' . $chemin . '" fill="none" stroke="#e6a63f"'
             . ' stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>';

        $marques = '';
        foreach ($points as $a) {
            list($x, $y) = $projete($a);
            $vedette = false;
            foreach ($remarquables as $r) {
                if (self::memeArret($a['arret'], $r)) {
                    $vedette = true;
                    break;
                }
            }
            if ($vedette) {
                $marques .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1)
                    . '" r="4.6" fill="#e6a63f"/>'
                    . '<text x="' . round($x, 1) . '" y="' . round($y - 8, 1)
                    . '" fill="#e6a63f" font-family="Barlow,sans-serif"'
                    . ' font-size="9.5" font-weight="600" text-anchor="middle">'
                    . htmlspecialchars(self::court($a['arret'], 16), ENT_QUOTES)
                    . '</text>';
            } else {
                $svg .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1)
                    . '" r="2.1" fill="#39424c"/>';
            }
        }
        /* Les arrêts qui comptent se dessinent en dernier, pour passer
           par-dessus les autres. */
        return $svg . $marques . '</svg>';
    }

    /* Le tableau de bord choisit son gabarit selon le rôle. */
    public function toHtml($_version = 'dashboard')
    {
        switch ($this->getConfiguration('role', 'quai')) {
            case 'planb':
                return $this->htmlPlanB($_version);
            case 'carte':
                return $this->htmlCarte($_version);
            case 'libre':
                return $this->htmlLibre($_version);
            default:
                return $this->htmlQuai($_version);
        }
    }

    /**
     * Le widget des solutions de repli.
     *
     * Il ne sert pas qu'aux jours d'échec : le vendredi, où les cours
     * finissent à midi et où aucun car ne circule, c'est le seul utile.
     */
    public function htmlPlanB($_version = 'dashboard')
    {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        $replace['#eqLogic_class#'] = 'eqLogic_layout_default';
        $replace['#calledFrom#'] = __CLASS__;

        $valeur = function ($logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            return is_object($cmd) ? $cmd->execCmd() : null;
        };
        $trajet = $this->trajet();
        $jours = array(__('Lundi', __FILE__), __('Mardi', __FILE__),
                       __('Mercredi', __FILE__), __('Jeudi', __FILE__),
                       __('Vendredi', __FILE__), __('Samedi', __FILE__),
                       __('Dimanche', __FILE__));
        $fin = $valeur('fin_cours') ?: '';
        $origine = (string) $this->getCache('edt_source', '');
        $replace['#sous_titre#'] = $jours[(int) date('N') - 1]
            . ($fin ? ' · ' . __('sortie', __FILE__) . ' ' . $fin : '')
            . ($fin ? ' · ' . ($origine !== ''
                ? __('EDT', __FILE__) . ' ' . $origine
                : __('réglé à la main', __FILE__)) : '');

        $solutions = $this->getCache('solutions', array());
        if (!is_array($solutions)) {
            $solutions = array();
        }

        /* Le badge dit pourquoi ce widget parle aujourd'hui. */
        if (!$valeur('jour_de_classe')) {
            $replace['#badge#'] = __('pas de classe', __FILE__);
        } elseif ($this->getCache('car_manque', false)) {
            $replace['#badge#'] = __('car pas passé', __FILE__);
        } elseif (!$valeur('car')) {
            $replace['#badge#'] = __('pas de car', __FILE__);
        } else {
            $replace['#badge#'] = __('au cas où', __FILE__);
        }
        /* Le matin, on ne rentre pas : on va au lycée. */
        $versEcole = $this->getCache('solutions_sens', 'retour') === 'aller';

        /* Une seule solution détaillée : celle qu'on va prendre. Le
           reste tiendrait de l'hésitation plutôt que du choix. */
        $meilleure = $solutions[0] ?? null;
        $corps = '';
        if ($meilleure) {
            $corps .= '<div class="cap"><span class="gros">'
                . htmlspecialchars($meilleure['depart'], ENT_QUOTES) . '</span>'
                . '<span>' . __('il faut partir', __FILE__) . '</span></div>';

            $marcheFin = (int) ($meilleure['marche_fin_min'] ?? 0);
            $marcheDebut = (int) ($meilleure['marche_debut_min'] ?? 0);
            $corps .= '<div class="arrive">'
                . ($versEcole ? __('au lycée à', __FILE__) : __('à la maison à', __FILE__))
                . ' <b>' . htmlspecialchars($meilleure['arrivee'], ENT_QUOTES) . '</b>'
                . ' — ' . htmlspecialchars($meilleure['duree'], ENT_QUOTES) . '</div>';

            /* Ce que ça coûte vraiment, en une ligne : combien de bus,
               combien de changements, combien de marche. C'est ce qu'on
               veut savoir avant de lire le détail. */
            $nb = count($meilleure['etapes']);
            $corr = max(0, $nb - 1);
            $effort = array($nb . ' ' . ($nb > 1 ? __('bus', __FILE__) : __('bus', __FILE__)));
            $effort[] = $corr === 0
                ? __('sans changement', __FILE__)
                : ($corr . ' ' . ($corr > 1 ? __('changements', __FILE__)
                                            : __('changement', __FILE__))
                   . ' ' . __('à', __FILE__) . ' '
                   . self::court($meilleure['etapes'][0]['a'], 15));
            $pied = $marcheDebut + $marcheFin;
            if ($pied > 0) {
                $effort[] = $pied . ' ' . __('min à pied', __FILE__);
            }
            $corps .= '<div class="effort">'
                . htmlspecialchars(implode(' · ', $effort), ENT_QUOTES) . '</div>';

            $corps .= '<div class="rail">';
            if ($marcheDebut > 0) {
                $corps .= '<div class="et pied"><span class="ou">'
                    . $marcheDebut . ' ' . __('min à pied jusqu\'à', __FILE__) . ' '
                    . htmlspecialchars(self::court($meilleure['etapes'][0]['de'], 16), ENT_QUOTES)
                    . '</span><span class="h"></span></div>';
            }
            foreach ($meilleure['etapes'] as $e) {
                $sco = !empty($e['scolaire']) ? ' sco' : '';
                $corps .= '<div class="et' . $sco . '">'
                    . '<span><b class="' . trim($sco) . '">'
                    . htmlspecialchars($e['ligne'], ENT_QUOTES) . '</b>'
                    . '<span class="ou">'
                    . htmlspecialchars(self::court($e['a'], 17), ENT_QUOTES)
                    . '</span></span>'
                    . '<span class="h">' . htmlspecialchars($e['heure_depart'], ENT_QUOTES)
                    . '</span></div>';
            }
            if ($marcheFin > 0) {
                $corps .= '<div class="et pied"><span class="ou">'
                    . $marcheFin . ' ' . __('min à pied', __FILE__)
                    . '</span><span class="h">'
                    . htmlspecialchars($meilleure['arrivee'], ENT_QUOTES)
                    . '</span></div>';
            }
            $corps .= '</div>';

            /* L'alternative en une ligne : de quoi savoir qu'il y en a
               une, sans avoir à la comparer. */
            $suivante = $solutions[1] ?? null;
            if ($suivante) {
                $corps .= '<div class="sinon"><span>' . __('sinon', __FILE__) . '</span>'
                    . '<span><b>' . htmlspecialchars($suivante['depart'], ENT_QUOTES)
                    . '</b> ' . __('par', __FILE__) . ' '
                    . htmlspecialchars(implode(' + ', array_map(function ($e) {
                        return $e['ligne'];
                    }, $suivante['etapes'])), ENT_QUOTES)
                    . ', ' . __('arrivée', __FILE__) . ' '
                    . htmlspecialchars($suivante['arrivee'], ENT_QUOTES) . '</span></div>';
            }
        } else {
            $corps = '<div class="rien">'
                . __('Aucune solution pour l\'instant. Le service cherche à partir de la fin des cours.', __FILE__)
                . '</div>';
        }
        $html = $corps;

        $replace['#corps#'] = $html;
        $replace['#etat#'] = count($solutions) ? 'ok' : 'vide';

        $depuis = $this->getCache('solutions_depuis', '');
        $replace['#pied_gauche#'] = $depuis
            ? __('à partir de', __FILE__) . ' ' . $depuis : $trajet['ligne'];
        $n = count($solutions);
        $replace['#pied_droite#'] = $n
            ? $n . ' ' . ($n > 1 ? __('solutions', __FILE__) : __('solution', __FILE__))
            : __('rien pour l\'instant', __FILE__);

        return template_replace(self::texteSeul($replace),
            getTemplate('core', $version, 'busscolaires_planb', 'busscolaires'));
    }

    /**
     * Deux noms désignent-ils le même arrêt ?
     *
     * La fiche officielle dit « Bourse » là où le référentiel dit « Aix
     * Bourse » : comparer à l'identique laisserait l'arrêt de l'école
     * sans étiquette sur la carte.
     */
    public static function memeArret($a, $b)
    {
        $lisser = function ($x) {
            $x = mb_strtolower(trim((string) $x), 'UTF-8');
            $x = strtr($x, array('à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e',
                                 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
                                 'ô' => 'o', 'ö' => 'o', 'û' => 'u', 'ü' => 'u',
                                 'ù' => 'u', 'ç' => 'c', "'" => ' ', '-' => ' '));
            return preg_replace('/\s+/', ' ', $x);
        };
        $x = $lisser($a);
        $y = $lisser($b);
        if ($x === '' || $y === '') {
            return false;
        }
        return $x === $y || strpos($x, $y) !== false || strpos($y, $x) !== false;
    }

    /**
     * Les champs d'un gabarit qui ne portent que du texte.
     *
     * Les noms de ligne et d'arrêt viennent du GTFS du réseau : c'est une
     * source extérieure, et rien ne garantit qu'elle n'y glissera pas un
     * jour un chevron. Ces champs-là entrent donc comme du texte, jamais
     * comme du HTML. Ceux que nous construisons nous-mêmes — le corps, la
     * légende, le dessin — ne passent pas par ici.
     *
     * @param  array $_replace le tableau de remplacement
     * @return array le même, ses champs de texte échappés
     */
    private static function texteSeul($_replace)
    {
        $champs = array('#arret#', '#arrivee#', '#badge#', '#car#',
            '#car_heure#', '#depart#', '#destination#', '#destination_detail#',
            '#etat#', '#jour#', '#ligne#', '#origine_detail#', '#ou#',
            '#pied_droite#', '#pied_gauche#', '#sens#', '#source#',
            '#sous_titre#');
        foreach ($champs as $c) {
            if (isset($_replace[$c]) && is_string($_replace[$c])) {
                $_replace[$c] = htmlspecialchars($_replace[$c], ENT_QUOTES, 'UTF-8');
            }
        }
        return $_replace;
    }

    /* Les noms d'arrêt sont parfois longs ; le widget est étroit. */
    public static function court($nom, $max = 18)
    {
        $nom = self::nomArret($nom);
        return (mb_strlen($nom) <= $max) ? $nom : (mb_substr($nom, 0, $max - 1) . '…');
    }

    /* Le même quai s'appelle « P+R Krypton » sur une ligne et « P+R Krypton
       Q4 » sur l'autre : deux référentiels pour un seul endroit. Descendre
       à un nom et remonter à un autre n'aiderait personne. */
    public static function nomArret($nom)
    {
        $nom = trim((string) $nom);
        $nom = preg_replace('/^P\+R\s+/u', '', $nom);
        return preg_replace('/\s+Q\d+$/u', '', $nom);
    }

    /* On dit « la A » et « le 170 » : une lettre, ici, c'est une ligne
       urbaine, et tout le monde la dit au féminin. */
    public static function article($ligne)
    {
        return preg_match('/^[A-Z]$/', (string) $ligne)
            ? __('la', __FILE__) . ' ' : __('le', __FILE__) . ' ';
    }

    /* Le quai de départ : le compte à rebours, puis le trajet de haut
       en bas — la maison, l'arrêt, l'école. */
    public function htmlQuai($_version = 'dashboard')
    {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);

        /* Le coeur pose ces deux marqueurs dans son propre toHtml() ;
           en le remplaçant, c'est à nous de le faire. */
        $replace['#eqLogic_class#'] = 'eqLogic_layout_default';
        $replace['#calledFrom#'] = __CLASS__;

        $valeur = function ($logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            return is_object($cmd) ? $cmd->execCmd() : null;
        };
        $trajet = $this->trajet();
        $retour = ($valeur('sens') === 'retour');

        /* Les libellés passent par le remplacement : un gabarit de widget
           n'est pas traduit, un {{...}} y sortirait tel quel. */
        $replace['#l_montee#'] = __('montée dans le car', __FILE__);
        $replace['#l_perturbations#'] = __('perturbation(s) signalée(s)', __FILE__);
        $replace['#sens#'] = $retour ? __('Retour', __FILE__) : __('Aller', __FILE__);

        $jours = array(__('lundi', __FILE__), __('mardi', __FILE__),
                       __('mercredi', __FILE__), __('jeudi', __FILE__),
                       __('vendredi', __FILE__), __('samedi', __FILE__),
                       __('dimanche', __FILE__));
        $replace['#jour#'] = $jours[(int) date('N') - 1];

        /* Le trajet, de bout en bout. Au retour on part de l'école ; le
           matin, de la maison. */
        /* Le car du matin passé, la journée bascule sur le retour : si le
           car a été manqué, ce n'est pas le trajet du soir qu'il faut
           raconter, mais celui qu'elle n'a pas pu faire. */
        $manque = (bool) $this->getCache('car_manque', false);
        $soirManque = $manque
            && $this->getCache('sens_manque', 'aller') === 'retour';
        if ($manque) {
            $retour = $soirManque;
            $replace['#sens#'] = $soirManque
                ? __('Ce soir', __FILE__) : __('Ce matin', __FILE__);
        }
        $replace['#l_origine#'] = $retour ? __('École', __FILE__) : __('Maison', __FILE__);
        $replace['#origine_detail#'] = $retour
            ? $trajet['adresse']
            : ($trajet['adresse_maison'] ?: __('départ à pied', __FILE__));
        $replace['#depart#'] = $valeur('heure_depart') ?: '--:--';
        $replace['#arret#'] = $valeur('arret') ?: '--';
        $replace['#car_heure#'] = $valeur('heure_car') ?: '--:--';
        $replace['#destination#'] = $valeur('destination') ?: '--';
        $replace['#destination_detail#'] = $retour
            ? __('à la maison', __FILE__) : __('à l\'école', __FILE__);
        $replace['#arrivee#'] = $valeur('heure_arrivee') ?: '--:--';
        if ($manque) {
            $replace['#depart#'] = '--:--';
            $replace['#arret#'] = $soirManque
                ? $trajet['arret_ecole'] : $trajet['arret_maison'];
            $replace['#car_heure#'] = (string) $this->getCache('heure_car_manque', '')
                ?: '--:--';
            $replace['#destination#'] = $soirManque
                ? $trajet['arret_maison'] : $trajet['arret_ecole'];
            $replace['#arrivee#'] = '--:--';
        }
        $replace['#ligne#'] = $trajet['ligne'];
        $replace['#car#'] = $valeur('car') ?: $trajet['ligne'];
        if ($manque) {
            /* Le badge affichait la course du soir : ce matin, elle n'y
               est pour rien. */
            $replace['#car#'] = $trajet['ligne'];
        }

        /* L'étoile dit que les horaires viennent de la fiche officielle.
           Sans elle, ils viennent du GTFS, qui se trompe. */
        /* Au retour, l'heure qui décide du car est celle de la fin des
           cours : on dit laquelle, et d'où elle vient. */
        $source_edt = (string) $this->getCache('edt_source', '');
        $replace['#l_ligne#'] = __('Ligne', __FILE__);
        if ($retour && !$manque && $valeur('fin_cours')) {
            $replace['#l_ligne#'] = __('Sortie', __FILE__) . ' '
                . $valeur('fin_cours') . ' ·';
            $replace['#ligne#'] = $source_edt !== ''
                ? __('emploi du temps de', __FILE__) . ' ' . $source_edt
                : __('réglé à la main', __FILE__);
        }

        $officielle = (bool) $this->getCache('fiche_officielle', false);
        $replace['#etoile#'] = $officielle ? '★' : '';
        $replace['#source#'] = $officielle
            ? __('fiche officielle', __FILE__) : __('horaires GTFS', __FILE__);
        $replace['#badge_mou#'] = $valeur('car') ? '' : 'mou';

        /* Le compte à rebours, et ce qu'il faut en comprendre. */
        $minutes = $valeur('minutes_avant');
        $marche = (int) $this->getCache('marche_min', 0);
        $avance = (int) $this->getCache('avance_min', $marche);
        if ($manque) {
            /* Le car aurait dû être là. On ne fait pas attendre plus
               longtemps devant un compte à rebours terminé : une phrase,
               et la marche à suivre. */
            $replace['#reste#'] = '!';
            $replace['#reste_unite#'] = '';
            $suivant = (string) $this->getCache('car_suivant', '');
            $replace['#legende#'] = __('le car n\'est pas passé', __FILE__)
                . ' — <b>' . __('prends l\'autre route', __FILE__) . '</b>'
                . ($suivant !== ''
                   ? ' · ' . __('un autre car à', __FILE__) . ' ' . $suivant : '');
            $replace['#urgence#'] = 'passe';
        } elseif (($saute = (string) $this->getCache('car_saute', '')) !== ''
                  && $minutes !== null && $minutes !== '') {
            /* Le car d'avant n'est pas venu : le compte à rebours s'est
               allongé d'un coup, il faut dire pourquoi. */
            list($n, $u) = self::compteARebours((int) $minutes);
            $replace['#reste#'] = $n;
            $replace['#reste_unite#'] = $u;
            /* Tant que le retard tient du quart d'heure, on ne l'accuse
               de rien : elle est peut-être dedans. On dit seulement quel
               est le prochain. Passé ce délai, on le nomme. */
            $retard = (strtotime(date('Y-m-d ') . date('H:i'))
                       - strtotime(date('Y-m-d ') . $saute)) / 60;
            $replace['#legende#'] = ($retard >= self::RETARD_MAX_MIN
                ? __('le car de', __FILE__) . ' ' . $saute . ' '
                  . __('n\'est pas passé', __FILE__) . ' — <b>'
                  . __('le prochain est à', __FILE__) . ' ' . $valeur('heure_car') . '</b>'
                : '<b>' . __('le prochain car est à', __FILE__) . ' '
                  . $valeur('heure_car') . '</b>');
            $replace['#urgence#'] = $retard >= self::RETARD_MAX_MIN ? 'passe' : 'calme';
        } elseif ($minutes === null || $minutes === '' || !$valeur('car')) {
            $replace['#reste#'] = '—';
            $replace['#reste_unite#'] = '';
            $replace['#legende#'] = $valeur('jour_de_classe')
                ? __('plus de car aujourd\'hui', __FILE__)
                : __('pas un jour de classe', __FILE__);
            $replace['#urgence#'] = 'dort';
        } else {
            list($n, $u) = self::compteARebours((int) $minutes);
            $replace['#reste#'] = $n;
            $replace['#reste_unite#'] = $u;
            $replace['#legende#'] = ($minutes < 0)
                ? __('l\'heure est passée', __FILE__)
                : (__('avant de partir', __FILE__)
                   . ($avance > 0 ? ' — <b>' . $avance . ' min</b> '
                      . __('avant le car', __FILE__)
                      . ($marche > 0 && $marche < $avance
                         ? ', ' . $marche . ' ' . __('de marche', __FILE__) : '')
                      : ''));
            if ($minutes < 0) {
                $replace['#urgence#'] = 'passe';
            } elseif ($minutes <= 10) {
                $replace['#urgence#'] = 'bientot';
            } else {
                $replace['#urgence#'] = 'calme';
            }
        }

        $perturbations = (int) $valeur('perturbations');
        $replace['#perturbations#'] = $perturbations;
        $replace['#classe_perturbations#'] = $perturbations > 0 ? '' : 'hidden';

        return template_replace(self::texteSeul($replace),
            getTemplate('core', $version, 'busscolaires', 'busscolaires'));
    }

    /* « 1 h 05 » se lit d'un coup d'œil, « 65 min » demande un calcul.
       Renvoie le nombre et son unité, pour les afficher séparément. */
    public static function compteARebours($minutes)
    {
        $signe = $minutes < 0 ? '-' : '';
        $m = abs((int) $minutes);
        if ($m < 60) {
            return array($signe . $m, 'min');
        }
        return array($signe . intdiv($m, 60),
                     'h ' . str_pad($m % 60, 2, '0', STR_PAD_LEFT));
    }

    /* « 1 h 05 » plutôt que « 65 min » : lisible d'un coup d'œil. */
    public static function enHeures($minutes)
    {
        $signe = $minutes < 0 ? '-' : '';
        $minutes = abs((int) $minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $signe . $m . ' min';
        }
        return $signe . $h . ' h ' . str_pad($m, 2, '0', STR_PAD_LEFT);
    }
}

class busscolairesCmd extends cmd
{
    public function execute($_options = array())
    {
        if ($this->getType() !== 'action') {
            return;
        }
        if ($this->getLogicalId() === 'rafraichir') {
            $this->getEqLogic()->rafraichir();
            return;
        }

        /* « Fin des cours du jour » : un dernier cours qui saute, et le
           car du retour change. On accepte 16:05 comme 16h05. */
        if ($this->getLogicalId() === 'definir_sortie') {
            $message = trim($_options['message'] ?? '');
            if (!preg_match('/^([01]?\d|2[0-3])[:hH]([0-5]\d)$/', $message, $m)) {
                throw new Exception(__('Heure attendue au format 16:05, reçu : ', __FILE__)
                    . $message);
            }
            $heure = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            /* Gardée sur l'équipement : elle ne concerne que cet enfant,
               et le service, lui, ne mémorise rien. */
            $eqLogic = $this->getEqLogic();
            $eqLogic->setConfiguration('sortie_poussee',
                array('date' => date('Y-m-d'), 'heure' => $heure));
            $eqLogic->save();
            log::add('busscolaires', 'info',
                $eqLogic->getName() . __(' : fin des cours du jour à ', __FILE__) . $heure);
            $eqLogic->rafraichir();
            return;
        }
    }
}
