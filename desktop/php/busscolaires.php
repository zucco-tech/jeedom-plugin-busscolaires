<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

/* Variables attendues par le gabarit de page de Jeedom. include_file()
   ne les fournit pas — seules ses propres locales sont en portée — donc
   chaque plugin les déclare lui-même. Sans « eqType », le script commun
   plugin.template.js ne saurait pas à quel type adresser la sauvegarde
   d'un équipement. */
$plugin = plugin::byId('busscolaires');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
  <div class="col-xs-12 eqLogicThumbnailDisplay">
    <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
    <div class="eqLogicThumbnailContainer">
      <div class="cursor eqLogicAction logoPrimary" data-action="gotoPluginConf">
        <i class="fas fa-wrench"></i>
        <br><span>{{Configuration}}</span>
      </div>
      <div class="cursor eqLogicAction logoSecondary" data-action="add">
        <i class="fas fa-plus-circle"></i>
        <br><span>{{Ajouter}}</span>
      </div>
    </div>

    <legend><i class="fas fa-bus"></i> {{Mes trajets}}</legend>
    <div class="eqLogicThumbnailContainer">
      <?php
      foreach ($eqLogics as $eqLogic) {
          $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
          echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
          echo '<img src="plugins/busscolaires/plugin_info/busscolaires_icon.png" height="105" width="95"/>';
          echo '<br>';
          echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
          echo '</div>';
      }
      ?>
    </div>
  </div>

  <div class="col-xs-12 eqLogic" style="display: none;">
    <div class="input-group pull-right" style="display:inline-flex">
      <span class="input-group-btn">
        <a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure">
          <i class="fa fa-cogs"></i> {{Configuration avancée}}
        </a>
        <a class="btn btn-default btn-sm eqLogicAction" data-action="copy">
          <i class="fa fa-files-o"></i> {{Dupliquer}}
        </a>
        <a class="btn btn-sm btn-success eqLogicAction" data-action="save">
          <i class="fas fa-check-circle"></i> {{Sauvegarder}}
        </a>
        <a class="btn btn-danger btn-sm eqLogicAction roundedRight" data-action="remove">
          <i class="fas fa-minus-circle"></i> {{Supprimer}}
        </a>
      </span>
    </div>

    <ul class="nav nav-tabs" role="tablist">
      <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
      <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fa fa-tachometer-alt"></i> {{Équipement}}</a></li>
      <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fa fa-list-alt"></i> {{Commandes}}</a></li>
    </ul>

    <div class="tab-content">
      <div role="tabpanel" class="tab-pane active" id="eqlogictab">
        <br>
        <div class="col-lg-6">
          <form class="form-horizontal">
            <fieldset>
              <legend><i class="fas fa-info"></i> {{Général}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Nom}}</label>
                <div class="col-sm-6">
                  <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;"/>
                  <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Trajet de l'aîné}}"/>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                <div class="col-sm-6">
                  <select class="eqLogicAttr form-control" data-l1key="object_id">
                    <option value="">{{Aucun}}</option>
                    <?php
                    foreach (jeeObject::buildTree(null, false) as $object) {
                        echo '<option value="' . $object->getId() . '">' . $object->getHumanName() . '</option>';
                    }
                    ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Catégorie}}</label>
                <div class="col-sm-8">
                  <?php
                  foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                      echo '<label class="checkbox-inline">';
                      echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '"/>' . $value['name'];
                      echo '</label>';
                  }
                  ?>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Activer}}</label>
                <div class="col-sm-8">
                  <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Visible}}</label>
                <div class="col-sm-8">
                  <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>
                </div>
              </div>
            </fieldset>
          </form>
        </div>

        <div class="col-lg-6">
          <form class="form-horizontal">
            <fieldset>
              <legend><i class="fas fa-desktop"></i> {{Ce que montre ce widget}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Rôle}}</label>
                <div class="col-sm-8">
                  <select class="eqLogicAttr form-control" id="bus-role"
                          data-l1key="configuration" data-l2key="role">
                    <option value="quai">{{Quai — le trajet du jour}}</option>
                    <option value="planb">{{Plan B — les solutions de repli}}</option>
                    <option value="carte">{{Carte — le tracé de la ligne}}</option>
                    <option value="libre">{{Libre — rentrer d'où elle est}}</option>
                  </select>
                  <span class="help-block">
                    {{Le vendredi, quand les cours finissent à midi et qu'aucun car ne circule, le Plan B est le seul widget utile.}}
                  </span>
                </div>
              </div>
              <div class="form-group expertModeVisible">
                <label class="col-sm-4 control-label">{{Source de position}}
                  <sup><i class="fas fa-question-circle tooltips"
                          title="{{Pour le rôle « Libre ». Un équipement dont Jeedom connaît la position : le téléphone du plugin mobile, dont les zones portent leurs coordonnées, ou tout équipement exposant « latitude,longitude ».}}"></i></sup>
                </label>
                <div class="col-sm-8">
                  <select class="eqLogicAttr form-control" data-l1key="configuration"
                          data-l2key="position_eqlogic">
                    <option value="">{{Aucune}}</option>
                    <?php
                    /* On ne propose que ce qui peut réellement situer :
                       un équipement dont au moins une commande porte des
                       coordonnées, ou dont le nom parle de position. */
                    foreach (eqLogic::all() as $candidat) {
                        if ($candidat->getEqType_name() === 'busscolaires') {
                            continue;
                        }
                        $situe = false;
                        foreach ($candidat->getCmd('info') as $c) {
                            $conf = $c->getConfiguration();
                            if (!empty($conf['latitude']) && !empty($conf['longitude'])) {
                                $situe = true;
                                break;
                            }
                        }
                        if (!$situe) {
                            continue;
                        }
                        echo '<option value="' . $candidat->getId() . '">'
                           . __('Forcer', __FILE__) . ' : '
                           . htmlspecialchars($candidat->getHumanName(), ENT_QUOTES)
                           . '</option>';
                    }
                    ?>
                  </select>
                  <span class="help-block">
                    {{Les zones du plugin mobile suffisent : « à la maison », « au lycée ». Elles situent à leur rayon près, ce qui suffit pour trouver l'arrêt le plus proche.}}
                  </span>
                </div>
              </div>

              <div class="form-group expertModeVisible">
                <label class="col-sm-4 control-label">{{Reprendre le trajet de}}
                  <sup><i class="fas fa-question-circle tooltips"
                          title="{{Pour poser plusieurs widgets pour le même enfant sans saisir deux fois ses arrêts et ses horaires.}}"></i></sup>
                </label>
                <div class="col-sm-8">
                  <select class="eqLogicAttr form-control" data-l1key="configuration"
                          data-l2key="trajet_de">
                    <option value="">{{Réglé ici même}}</option>
                    <?php
                    foreach ($eqLogics as $autre) {
                        if ($autre->getConfiguration('trajet_de', '') !== '') {
                            continue;
                        }
                        echo '<option value="' . $autre->getId() . '">'
                           . htmlspecialchars($autre->getName(), ENT_QUOTES) . '</option>';
                    }
                    ?>
                  </select>
                </div>
              </div>
            </fieldset>

            <fieldset>
              <legend><i class="fas fa-route"></i> {{Le trajet}}</legend>
              <p class="help-block" style="margin-left:15px;">
                {{Trois réglages suffisent. Le reste — l'adresse de l'école, la vitesse de marche, le sens suivi — se devine ou ne se touche jamais : activez le mode expert de Jeedom pour les voir.}}
              </p>

              <div class="form-group">
                <label class="col-sm-4 control-label">{{Ligne}}</label>
                <div class="col-sm-8">
                  <select class="eqLogicAttr form-control" id="bus-ligne"
                          data-l1key="configuration" data-l2key="ligne">
                    <option value="">{{Chargement...}}</option>
                  </select>
                  <span class="help-block" id="bus-ligne-detail"></span>
                </div>
              </div>

              <div class="form-group">
                <label class="col-sm-4 control-label">{{Arrêt près de la maison}}</label>
                <div class="col-sm-8">
                  <select class="eqLogicAttr form-control" id="bus-arret-maison"
                          data-l1key="configuration" data-l2key="arret_maison">
                  </select>
                </div>
              </div>

              <div class="form-group">
                <label class="col-sm-4 control-label">{{Arrêt près de l'école}}</label>
                <div class="col-sm-8">
                  <select class="eqLogicAttr form-control" id="bus-arret-ecole"
                          data-l1key="configuration" data-l2key="arret_ecole">
                  </select>
                </div>
              </div>

              <div class="form-group expertModeVisible">
                <label class="col-sm-4 control-label">{{Sens suivi}}
                  <sup><i class="fas fa-question-circle tooltips"
                          title="{{Automatique suit la journée : l'aller tant qu'il reste un car vers l'école, le retour ensuite.}}"></i></sup>
                </label>
                <div class="col-sm-8">
                  <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="sens">
                    <option value="auto">{{Automatique}}</option>
                    <option value="aller">{{Aller, vers l'école}}</option>
                    <option value="retour">{{Retour, vers la maison}}</option>
                  </select>
                </div>
              </div>
            </fieldset>

            <fieldset class="expertModeVisible">
              <legend><i class="fas fa-home"></i> {{La maison}}</legend>
              <p class="help-block" style="margin-left:15px;">
                {{Sert à calculer le temps de marche jusqu'à l'arrêt le matin — sans elle, l'heure de départ à pied vaut celle du car. Laissez vide pour utiliser les coordonnées du domicile réglées dans Jeedom.}}
              </p>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Adresse}}</label>
                <div class="col-sm-8">
                  <input class="eqLogicAttr form-control" data-l1key="configuration"
                         data-l2key="adresse_maison" id="bus-adresse-maison"
                         placeholder="{{celle de Jeedom}}"/>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Coordonnées}}</label>
                <div class="col-sm-4">
                  <input class="eqLogicAttr form-control" data-l1key="configuration"
                         data-l2key="maison_lat" placeholder="{{latitude}}"/>
                </div>
                <div class="col-sm-4">
                  <input class="eqLogicAttr form-control" data-l1key="configuration"
                         data-l2key="maison_lon" placeholder="{{longitude}}"/>
                </div>
              </div>
              <div class="form-group">
                <div class="col-sm-offset-4 col-sm-8">
                  <span class="help-block" id="bus-maison-jeedom"></span>
                </div>
              </div>
            </fieldset>

            <fieldset class="expertModeVisible">
              <legend><i class="fas fa-school"></i> {{L'école}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Adresse}}</label>
                <div class="col-sm-8">
                  <input class="eqLogicAttr form-control" data-l1key="configuration"
                         data-l2key="adresse"/>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Coordonnées}}
                  <sup><i class="fas fa-question-circle tooltips"
                          title="{{Servent à calculer le temps de marche entre l'école et l'arrêt. Latitude, puis longitude.}}"></i></sup>
                </label>
                <div class="col-sm-4">
                  <input class="eqLogicAttr form-control" data-l1key="configuration"
                         data-l2key="adresse_lat" placeholder="{{latitude}}"/>
                </div>
                <div class="col-sm-4">
                  <input class="eqLogicAttr form-control" data-l1key="configuration"
                         data-l2key="adresse_lon" placeholder="{{longitude}}"/>
                </div>
              </div>
            </fieldset>

            <fieldset class="expertModeVisible">
              <legend><i class="fas fa-walking"></i> {{Marche et correspondances}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Vitesse de marche}}</label>
                <div class="col-sm-4">
                  <div class="input-group">
                    <input class="eqLogicAttr form-control" data-l1key="configuration"
                           data-l2key="marche_m_min" placeholder="80"/>
                    <span class="input-group-addon">m/min</span>
                  </div>
                </div>
                <div class="col-sm-4"><span class="help-block">{{Entre 40 et 140}}</span></div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Marge de correspondance}}</label>
                <div class="col-sm-4">
                  <div class="input-group">
                    <input class="eqLogicAttr form-control" data-l1key="configuration"
                           data-l2key="correspondance_min" placeholder="4"/>
                    <span class="input-group-addon">min</span>
                  </div>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Partir avant le car}}
                  <sup><i class="fas fa-question-circle tooltips"
                          title="{{De combien de minutes on quitte la maison avant le passage du car. Arriver pile à l'heure est ce qui stresse le plus. Le temps de marche l'emporte s'il est plus long.}}"></i></sup>
                </label>
                <div class="col-sm-4">
                  <div class="input-group">
                    <input class="eqLogicAttr form-control" data-l1key="configuration"
                           data-l2key="marge_depart_min" placeholder="10"/>
                    <span class="input-group-addon">min</span>
                  </div>
                </div>
                <div class="col-sm-4"><span class="help-block">{{De l'avance, pas du calcul}}</span></div>
              </div>

              <div class="form-group">
                <label class="col-sm-4 control-label">{{Marche acceptée}}
                  <sup><i class="fas fa-question-circle tooltips"
                          title="{{Jusqu'où on accepte de marcher pour rejoindre un arrêt. Un quart d'heure de marche vaut mieux qu'une correspondance de plus.}}"></i></sup>
                </label>
                <div class="col-sm-4">
                  <div class="input-group">
                    <input class="eqLogicAttr form-control" data-l1key="configuration"
                           data-l2key="marche_max_min" placeholder="15"/>
                    <span class="input-group-addon">min</span>
                  </div>
                </div>
                <div class="col-sm-4"><span class="help-block">{{Entre 2 et 30}}</span></div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Préavis avant de partir}}</label>
                <div class="col-sm-4">
                  <div class="input-group">
                    <input class="eqLogicAttr form-control" data-l1key="configuration"
                           data-l2key="preavis_min" placeholder="10"/>
                    <span class="input-group-addon">min</span>
                  </div>
                </div>
              </div>
            </fieldset>

            <fieldset>
              <legend><i class="far fa-clock"></i> {{Fin des cours}}</legend>
              <p class="help-block" style="margin-left:15px;">
                {{Le car du retour est choisi après le dernier cours de la journée. Ces heures peuvent être lues sur un emploi du temps plutôt que réglées ici. Pour un jour précis — un cours qui saute — utilisez la commande « Fin des cours du jour », depuis le tableau de bord ou un scénario.}}
              </p>

              <div class="form-group">
                <label class="col-sm-4 control-label">{{Enfant}}
                  <sup><i class="fas fa-question-circle tooltips"
                          title="{{L'enfant dont ce trajet suit l'emploi du temps. Les heures de fin de cours en découlent, et il n'y a plus rien à saisir ci-dessous.}}"></i></sup>
                </label>
                <div class="col-sm-8">
                  <?php
                  $enfants = busscolaires::enfantsConnus();
                  ?>
                  <select class="eqLogicAttr form-control" id="bus-pronote"
                          data-l1key="configuration" data-l2key="pronote_eqlogic">
                    <option value=""><?php
                      echo (count($enfants) === 1)
                        ? __('Automatique', __FILE__) . ' — '
                          . htmlspecialchars(reset($enfants)->getName(), ENT_QUOTES)
                        : __('Automatique', __FILE__);
                    ?></option>
                    <option value="aucun">{{Aucun — heures réglées ici}}</option>
                    <?php
                    foreach ($enfants as $id => $enfant) {
                        echo '<option value="' . $id . '">'
                           . htmlspecialchars($enfant->getName(), ENT_QUOTES)
                           . '</option>';
                    }
                    if (!count($enfants)) {
                        echo '<option value="" disabled>'
                           . __('aucun emploi du temps détecté', __FILE__) . '</option>';
                    }
                    ?>
                  </select>
                  <span class="help-block">
                    <?php if (count($enfants)) { ?>
                    {{Détecté tout seul, comme z2m trouve mqtt2. Le lien se rétablit de lui-même : supprimer puis recréer cet équipement ne le casse pas.}}
                    <?php } else { ?>
                    {{Aucun plugin d'emploi du temps installé : les heures ci-dessous font foi.}}
                    <?php } ?>
                  </span>
                  <div id="bus-pronote-lu" style="margin-top:9px;"></div>
                </div>
              </div>
              <?php
              $jours = array('lundi' => 'Lundi', 'mardi' => 'Mardi',
                             'mercredi' => 'Mercredi', 'jeudi' => 'Jeudi',
                             'vendredi' => 'Vendredi', 'defaut' => 'Les autres jours');
              foreach ($jours as $cle => $nom) { ?>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{<?php echo $nom; ?>}}</label>
                <div class="col-sm-4">
                  <input type="time" class="eqLogicAttr form-control"
                         data-l1key="configuration" data-l2key="sortie_<?php echo $cle; ?>"/>
                </div>
              </div>
              <?php } ?>
            </fieldset>

            <fieldset>
              <legend><i class="fas fa-child"></i> {{La page de l'enfant}}</legend>
              <p class="help-block" style="margin-left:15px;">
                {{Une page sans réglage, faite pour un téléphone : le car, l'heure de partir et la solution de repli. L'enfant ne peut rien y modifier — la page ne sait que lire.}}
              </p>
              <div class="form-group">
                <label class="col-sm-3 control-label">{{Son compte Jeedom}}</label>
                <div class="col-sm-6">
                  <select class="eqLogicAttr form-control" data-l1key="configuration"
                          data-l2key="compte_enfant">
                    <option value="">{{Aucun — la page s'ouvrira par le lien}}</option>
<?php
/* On ne propose pas les comptes administrateurs : reconnaître un enfant
   qui peut tout changer n'aurait aucun sens. */
foreach (user::all() as $u) {
    if ($u->getProfils() == 'admin' || !$u->getEnable()) {
        continue;
    }
    echo '<option value="' . $u->getId() . '">'
       . htmlspecialchars($u->getLogin(), ENT_QUOTES, 'UTF-8') . '</option>';
}
?>
                  </select>
                  <span class="help-block">
                    {{Connectée à Jeedom avec ce compte, elle ouvre sa page sans lien. Rien n'est deviné : sans ce choix, personne n'est reconnu.}}
                  </span>
                </div>
              </div>
              <p class="help-block" style="margin-left:15px;">
                {{Le lien secret reste utile pour un téléphone sans compte Jeedom. Tant qu'il n'est pas créé, la page n'existe pas pour lui.}}
              </p>
              <div class="form-group">
                <div class="col-sm-offset-1 col-sm-11" id="bus-lien-zone">
                  <em>{{Sauvegardez l'équipement, puis créez le lien.}}</em>
                </div>
              </div>
              <div class="form-group">
                <div class="col-sm-offset-1 col-sm-11">
                  <a class="btn btn-success btn-sm" id="bt_busCle">
                    <i class="fas fa-key"></i> {{Créer le lien}}
                  </a>
                  <a class="btn btn-danger btn-sm" id="bt_busRevoquer" style="display:none;">
                    <i class="fas fa-ban"></i> {{Révoquer}}
                  </a>
                </div>
              </div>
            </fieldset>

            <fieldset>
              <legend><i class="fas fa-vial"></i> {{Vérifier}}</legend>
              <div class="form-group">
                <div class="col-sm-offset-1 col-sm-11">
                  <a class="btn btn-default" id="bt_lireMaintenant">
                    <i class="fas fa-sync"></i> {{Lire maintenant}}
                  </a>
                  <span class="help-block">
                    {{Interroge le service avec le trajet affiché à l'écran, sans rien sauvegarder.}}
                  </span>
                  <pre id="resultatTest" style="display:none;max-height:260px;"></pre>
                </div>
              </div>
            </fieldset>
          </form>
        </div>
      </div>

      <div role="tabpanel" class="tab-pane" id="commandtab">
        <br>
        <table id="table_cmd" class="table table-bordered table-condensed">
          <thead>
            <tr>
              <th>{{Nom}}</th>
              <th>{{Type}}</th>
              <th>{{Valeur}}</th>
              <th>{{Options}}</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include_file('desktop', 'busscolaires', 'js', 'busscolaires'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
