<?php
/* Actions des pages d'administration. Réservé aux administrateurs
   Jeedom : c'est ce qui garantit que l'enfant ne peut rien régler. */
try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /** L'équipement visé, ou une erreur claire. */
    function equipement($id)
    {
        $eqLogic = eqLogic::byId($id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'busscolaires') {
            throw new Exception(__('Équipement inconnu : ', __FILE__) . $id);
        }
        return $eqLogic;
    }

    /** L'adresse publique de ce Jeedom, celle qu'on peut envoyer. */
    function racinePublique()
    {
        $racine = rtrim(network::getNetworkAccess('external'), '/');
        if ($racine === '' || $racine === 'http://' || $racine === 'https://') {
            $racine = rtrim(network::getNetworkAccess('internal'), '/');
        }
        return $racine;
    }

    // ---- État global du service -----------------------------------

    if (init('action') == 'etat') {
        $reponse = array(
            'demon' => busscolaires::deamon_info()['state'] == 'ok',
            'dependances' => busscolaires::dependancy_info()['state'] == 'ok',
            'arrets' => 0, 'horaires' => 0, 'lignes' => 0, 'scolaires' => 0,
            'fiches' => array(), 'alertes' => 0, 'index_en_cours' => false,
            'enfants' => array(),
        );

        /* L'état du lien scolaire, équipement par équipement : c'est la
           seule façon de savoir d'un coup d'œil qu'il est en place. */
        foreach (eqLogic::byType('busscolaires', true) as $eq) {
            $edt = $eq->heuresPronote();
            $reponse['enfants'][] = array(
                'nom' => $eq->getName(),
                'enfant' => $edt['source'] ?? '',
                'lu' => $edt['lu'] ?? '',
                'jours' => $edt ? count($edt['heures']) : 0,
                'annules' => $edt['annules'] ?? 0,
                'etat' => $eq->etatEmploiDuTemps(),
            );
        }
        if ($reponse['demon']) {
            $etat = busscolaires::appeler('api/etat');
            $reponse['arrets'] = $etat['index']['stops'] ?? 0;
            $reponse['horaires'] = $etat['index']['stop_times'] ?? 0;
            $reponse['index_en_cours'] = !empty($etat['construction']['en_cours']);
            $reponse['alertes'] = $etat['sources']['alertes']['nombre'] ?? 0;
            $reponse['fiches'] = $etat['sources']['fiches_officielles']['detail'] ?? array();
            if (!empty($etat['index_pret'])) {
                $lignes = busscolaires::appeler('api/lignes', 'GET', null, 40);
                $reponse['lignes'] = count($lignes);
                foreach ($lignes as $l) {
                    if (!empty($l['scolaire'])) {
                        $reponse['scolaires']++;
                    }
                }
            }
        }
        ajax::success($reponse);
    }

    if (init('action') == 'reconstruire') {
        busscolaires::appeler('api/index/reconstruire', 'POST', array(), 15);
        ajax::success(true);
    }

    // ---- Référentiel, pour les listes déroulantes ------------------

    if (init('action') == 'lignes') {
        ajax::success(busscolaires::appeler('api/lignes', 'GET', null, 40));
    }

    /* Les arrêts d'une ligne, à plat et groupés par commune : les deux
       menus de l'équipement en ont besoin, et la séparation « Aix /
       ailleurs » du service ne vaut que pour un trajet particulier. */
    if (init('action') == 'arrets') {
        $ligne = init('ligne');
        if ($ligne == '') {
            throw new Exception(__('Aucune ligne indiquée.', __FILE__));
        }
        $d = busscolaires::appeler('api/arrets-ligne?ligne=' . urlencode($ligne),
                                   'GET', null, 30);
        $tous = array_merge($d['aix'] ?? array(), $d['ailleurs'] ?? array());
        $vus = array();
        $sortie = array();
        foreach ($tous as $a) {
            $cle = $a['commune'] . '|' . $a['arret'];
            if (isset($vus[$cle])) {
                continue;
            }
            $vus[$cle] = true;
            $sortie[] = array('arret' => $a['arret'], 'commune' => $a['commune']);
        }
        usort($sortie, function ($x, $y) {
            return array($x['commune'], $x['arret']) <=> array($y['commune'], $y['arret']);
        });
        ajax::success($sortie);
    }

    /* Le domicile que Jeedom connaît, pour le proposer par défaut. */
    if (init('action') == 'maisonJeedom') {
        ajax::success(array(
            'lat' => config::byKey('info::latitude'),
            'lon' => config::byKey('info::longitude'),
            'adresse' => trim(config::byKey('info::address') . ' '
                . config::byKey('info::city')),
        ));
    }

    // ---- Le lien de l'enfant, propre à un équipement ---------------

    if (init('action') == 'lienEnfant') {
        $eqLogic = equipement(init('id'));
        $cle = $eqLogic->cleEnfant();
        ajax::success(array('lien' => $cle === '' ? '' :
            racinePublique() . '/plugins/busscolaires/enfant/index.php?k=' . $cle));
    }

    if (init('action') == 'genererCle') {
        $eqLogic = equipement(init('id'));
        $cle = $eqLogic->genererCle();
        ajax::success(array('cle' => $cle,
            'lien' => racinePublique() . '/plugins/busscolaires/enfant/index.php?k=' . $cle));
    }

    if (init('action') == 'revoquerCle') {
        equipement(init('id'))->revoquerCle();
        ajax::success(true);
    }

    /* Ce que l'emploi du temps désigné donne, sans rien appliquer. */
    if (init('action') == 'pronote') {
        $eqLogic = equipement(init('id'));
        /* On applique le choix affiché sans sauvegarder : « » vaut
           automatique, « aucun » vaut refus. */
        $eqLogic->setConfiguration('pronote_eqlogic', init('source'));
        $lu = $eqLogic->heuresPronote();
        $regle = array();
        foreach (array('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi') as $j) {
            $regle[$j] = $eqLogic->getConfiguration('sortie_' . $j, '');
        }
        ajax::success(array(
            'heures' => $lu['heures'] ?? array(),
            'lu' => $lu['lu'] ?? '',
            'source' => $lu['source'] ?? '',
            'regle' => $regle,
        ));
    }

    // ---- Lecture immédiate depuis la page d'un équipement ----------

    if (init('action') == 'lire') {
        $eqLogic = equipement(init('id'));
        /* Le trajet affiché à l'écran est appliqué sans sauvegarder :
           on peut essayer avant d'enregistrer. */
        $trajet = json_decode(init('trajet'), true);
        if (is_array($trajet)) {
            foreach ($trajet as $cle => $valeur) {
                if (array_key_exists($cle, busscolaires::REGLAGES) || $cle === 'sens') {
                    $eqLogic->setConfiguration($cle, $valeur);
                }
            }
        }
        ajax::success($eqLogic->rafraichir());
    }

    // ---- Import d'une fiche horaire PDF, une fois par an -----------

    if (init('action') == 'importerFiche') {
        if (!isset($_FILES['fiche']) || $_FILES['fiche']['error'] != UPLOAD_ERR_OK) {
            throw new Exception(__('Aucun fichier reçu.', __FILE__));
        }
        if ($_FILES['fiche']['size'] > 8 * 1024 * 1024) {
            throw new Exception(__('Fichier trop volumineux (8 Mo maximum).', __FILE__));
        }
        $type = mime_content_type($_FILES['fiche']['tmp_name']);
        if ($type != 'application/pdf') {
            throw new Exception(__('Ce fichier n\'est pas un PDF : ', __FILE__) . $type);
        }
        $ligne = trim(init('ligne'));
        if (!preg_match('/^[A-Za-z0-9_-]{1,12}$/', $ligne)) {
            throw new Exception(__('Indiquez la ligne concernée.', __FILE__));
        }
        exec('which pdftotext', $rien, $rc);
        if ($rc !== 0) {
            throw new Exception(__("pdftotext n'est pas installé : relancez l'installation des dépendances.", __FILE__));
        }

        $base = realpath(__DIR__ . '/../..');
        $pdf = jeedom::getTmpFolder('busscolaires') . '/fiche.pdf';
        if (!move_uploaded_file($_FILES['fiche']['tmp_name'], $pdf)) {
            throw new Exception(__('Impossible de déposer le fichier.', __FILE__));
        }
        $sortie = busscolaires::dossierDonnees() . '/fiches/' . $ligne . '.json';
        @mkdir(dirname($sortie), 0775, true);

        $cmd = system::getCmdPython3('busscolaires')
            . escapeshellarg($base . '/resources/card/tools/parse_fiche.py')
            . ' ' . escapeshellarg($pdf) . ' ' . escapeshellarg($sortie) . ' 2>&1';
        exec($cmd, $journal, $rc);
        @unlink($pdf);
        $texte = implode("\n", $journal);
        if ($rc !== 0) {
            throw new Exception(__('Lecture de la fiche impossible :', __FILE__) . "\n" . $texte);
        }
        /* Le service garde la fiche en mémoire : il faut le relancer. */
        busscolaires::deamon_stop();
        busscolaires::deamon_start();
        ajax::success(array('journal' => $texte));
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
