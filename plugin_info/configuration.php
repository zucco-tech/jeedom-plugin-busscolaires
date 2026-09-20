<?php
/* Configuration du plugin : uniquement ce qui est commun à tous les
   trajets. Le trajet lui-même — ligne, arrêts, horaires de fin de cours,
   lien de l'enfant — appartient à chaque équipement, puisqu'il y a un
   équipement par enfant. */
if (!isConnect('admin')) {
    throw new Exception('401 - Accès non autorisé');
}
?>

<div class="row">
  <div class="col-lg-6">
    <form class="form-horizontal">
      <fieldset>
        <legend><i class="fas fa-server"></i> {{Le service}}</legend>
        <p class="help-block" style="margin-left:15px;">
          {{Un démon local fait tous les calculs : fiche horaire officielle, index du réseau, alertes. Il n'écoute que sur cette machine.}}
        </p>
        <div class="form-group">
          <label class="col-sm-5 control-label">{{Port du démon}}
            <sup><i class="fas fa-question-circle tooltips"
                    title="{{Sur 127.0.0.1 seulement. À changer uniquement si ce port est déjà pris.}}"></i></sup>
          </label>
          <div class="col-sm-3">
            <input class="configKey form-control" data-l1key="port" placeholder="55810"/>
          </div>
        </div>
        <div class="form-group">
          <label class="col-sm-5 control-label">{{Communes à indexer}}
            <sup><i class="fas fa-question-circle tooltips"
                    title="{{Séparées par des virgules. Le réseau entier pèse des millions d'horaires : n'indexer que les communes traversées rend l'index bien plus léger et les calculs plus rapides. Vide = tout le réseau.}}"></i></sup>
          </label>
          <div class="col-sm-6">
            <input class="configKey form-control" data-l1key="communes"
                   placeholder="{{Ma commune, La ville du lycée, les communes entre les deux}}"/>
          </div>
        </div>
        <div class="form-group">
          <div class="col-sm-offset-1 col-sm-10">
            <span class="help-block">
              {{Après un changement, reconstruisez l'index : le démon ne connaît que les communes indexées.}}
            </span>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend><i class="fas fa-file-pdf"></i> {{Fiche horaire officielle}}</legend>
        <p class="help-block" style="margin-left:15px;">
          {{Importez le PDF officiel de votre ligne : il fait autorité sur le GTFS, qui se trompe notamment sur les mercredis et les vacances. À refaire à chaque rentrée.}}
        </p>
        <div class="form-group">
          <label class="col-sm-5 control-label">{{Ligne concernée}}
            <sup><i class="fas fa-question-circle tooltips"
                    title="{{Le numéro de la ligne dont vous importez la fiche.}}"></i></sup>
          </label>
          <div class="col-sm-3">
            <input class="form-control" id="bus-fiche-ligne" placeholder="1800"/>
          </div>
        </div>
        <div class="form-group">
          <div class="col-sm-offset-1 col-sm-10">
            <input type="file" id="bus-fiche-pdf" accept="application/pdf"
                   style="display:inline-block;width:auto;"/>
            <a class="btn btn-default btn-sm" id="bt_busFiche">
              <i class="fas fa-upload"></i> {{Importer}}
            </a>
          </div>
        </div>
        <div class="form-group">
          <div class="col-sm-offset-1 col-sm-10">
            <pre id="bus-fiche-resultat" style="display:none;max-height:200px;"></pre>
          </div>
        </div>
      </fieldset>
    </form>
  </div>

  <div class="col-lg-6">
    <fieldset>
      <legend><i class="fas fa-heartbeat"></i> {{État du service}}</legend>
      <div id="bus-etat" class="well well-sm" style="min-height:120px;">
        <i class="fas fa-spinner fa-spin"></i> {{Lecture...}}
      </div>
      <div style="margin-bottom:15px;">
        <a class="btn btn-default btn-sm" id="bt_busEtat">
          <i class="fas fa-sync"></i> {{Actualiser}}
        </a>
        <a class="btn btn-warning btn-sm" id="bt_busIndex">
          <i class="fas fa-database"></i> {{Reconstruire l'index}}
        </a>
      </div>
      <p class="help-block">
        {{L'index du réseau est un cache : le reconstruire prend quelques minutes et ne fait rien perdre. Il se reconstruit tout seul s'il manque.}}
      </p>
    </fieldset>

    <fieldset>
      <legend><i class="fas fa-route"></i> {{Et le trajet ?}}</legend>
      <p class="help-block">
        {{Il se règle sur l'équipement, pas ici : la ligne, les deux arrêts, les heures de fin de cours et le lien de l'enfant. Un équipement par enfant — c'est ce qui permet deux enfants, deux écoles ou deux lignes sur le même Jeedom.}}
      </p>
    </fieldset>
  </div>
</div>

<?php include_file('desktop', 'configuration', 'js', 'busscolaires'); ?>
