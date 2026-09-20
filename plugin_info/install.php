<?php
/* Installation et mise à jour du plugin.
 *
 * Le plugin ne crée qu'un cron : les dépendances et le démon sont gérés
 * par le coeur de Jeedom, à partir des drapeaux de info.json.
 *
 * Pas de contrôle isConnect() ici : Jeedom inclut ce fichier par
 * require_once depuis la ligne de commande — activation, mise à jour,
 * cron — où aucune session n'existe. Un tel contrôle ferait échouer
 * l'inclusion, et les fonctions ci-dessous ne seraient jamais définies.
 */

function busscolaires_install()
{
    busscolaires_update();
}

function busscolaires_update()
{
    /* Toutes les 5 minutes : la fiche horaire ne bouge pas, mais le
       compte à rebours et les alertes, oui. */
    $cron = cron::byClassAndFunction('busscolaires', 'pull');
    if (!is_object($cron)) {
        $cron = new cron();
        $cron->setClass('busscolaires');
        $cron->setFunction('pull');
        $cron->setDeamon(0);
    }
    $cron->setEnable(1);
    $cron->setSchedule('*/5 * * * *');
    $cron->save();

    /* Le trajet vivait dans la configuration du plugin ; il appartient
       désormais à l'équipement, puisqu'il y a un équipement par enfant.
       On déménage une fois, sans rien écraser. */
    /* Les deux arrêts portaient le nom des communes d'origine du plugin.
       Ils s'appellent maintenant par leur rôle. On recopie une fois, sans
       rien écraser : une installation existante ne doit rien remarquer. */
    foreach (array('arret_fuveau' => 'arret_maison',
                   'arret_aix' => 'arret_ecole') as $avant => $apres) {
        foreach (eqLogic::byType('busscolaires') as $eqLogic) {
            $ancien = $eqLogic->getConfiguration($avant, '');
            if ($ancien !== '' && $eqLogic->getConfiguration($apres, '') === '') {
                $eqLogic->setConfiguration($apres, $ancien)->save();
                log::add('busscolaires', 'info', __('Arrêt repris :', __FILE__)
                    . ' ' . $avant . ' → ' . $apres . ' (' . $eqLogic->getName() . ')');
            }
        }
        $global = config::byKey($avant, 'busscolaires', '');
        if ($global !== '' && config::byKey($apres, 'busscolaires', '') === '') {
            config::save($apres, $global, 'busscolaires');
        }
    }

    $aDemenager = false;
    foreach (busscolaires::REGLAGES as $cle => $rien) {
        if (config::byKey($cle, 'busscolaires', '') !== '') {
            $aDemenager = true;
            break;
        }
    }
    if ($aDemenager) {
        foreach (eqLogic::byType('busscolaires') as $eqLogic) {
            $touche = false;
            foreach (busscolaires::REGLAGES as $cle => $rien) {
                $ancien = config::byKey($cle, 'busscolaires', '');
                if ($ancien !== '' && $eqLogic->getConfiguration($cle, '') === '') {
                    $eqLogic->setConfiguration($cle, $ancien);
                    $touche = true;
                }
            }
            /* Le lien de l'enfant aussi : il désigne un enfant. */
            $ancienneCle = config::byKey('cle_enfant', 'busscolaires', '');
            if ($ancienneCle !== '' && $eqLogic->getConfiguration('cle_enfant', '') === '') {
                $eqLogic->setConfiguration('cle_enfant', $ancienneCle);
                $touche = true;
            }
            if ($touche) {
                $eqLogic->save();
                log::add('busscolaires', 'info',
                    __('Trajet déplacé sur l\'équipement ', __FILE__) . $eqLogic->getName());
            }
        }
        foreach (busscolaires::REGLAGES as $cle => $rien) {
            config::remove($cle, 'busscolaires');
        }
        config::remove('cle_enfant', 'busscolaires');
    }

    /* Une mise à jour remplace le dossier du plugin : les réglages, qui
       vivent dans la configuration Jeedom, sont réappliqués au démon dès
       qu'il redémarre. deamon_start() s'en charge, rien à faire ici. */
}

function busscolaires_remove()
{
    $cron = cron::byClassAndFunction('busscolaires', 'pull');
    if (is_object($cron)) {
        $cron->remove();
    }
    try {
        busscolaires::deamon_stop();
    } catch (Exception $e) {
        // Le démon n'était pas lancé : rien à faire.
    }
}
