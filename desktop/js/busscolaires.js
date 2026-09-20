/* Page d'un équipement : un enfant, un trajet.
   La ligne et les deux arrêts se choisissent dans des listes tirées du
   référentiel — on ne fait pas taper un nom d'arrêt au hasard, il ne
   tomberait sur rien. */

var busInventaire = null;   /* les lignes, chargées une seule fois */

function busAjaxEq(action, donnees, succes, echec) {
  $.ajax({
    type: 'POST',
    url: 'plugins/busscolaires/core/ajax/busscolaires.ajax.php',
    data: $.extend({ action: action }, donnees || {}),
    dataType: 'json',
    global: false,
    error: function (r, statut, err) {
      if (echec) { echec(statut + ' ' + err); }
    },
    success: function (data) {
      if (data.state != 'ok') { if (echec) { echec(data.result); } return; }
      if (succes) { succes(data.result); }
    }
  });
}

/* ------------------------------------------------------------------ */
/* La ligne                                                            */
/* ------------------------------------------------------------------ */

function busPeuplerLignes(choisie) {
  var $s = $('#bus-ligne');
  $s.empty();
  var groupes = [
    { titre: '{{Lignes scolaires}}', scolaire: true },
    { titre: '{{Autres lignes du réseau}}', scolaire: false }
  ];
  var vues = {};
  groupes.forEach(function (g) {
    var dedans = busInventaire.filter(function (l) { return !!l.scolaire === g.scolaire; });
    if (!dedans.length) { return; }
    var $g = $('<optgroup>').attr('label', g.titre + ' (' + dedans.length + ')');
    dedans.forEach(function (l) {
      vues[l.ligne] = true;
      $('<option>')
        .attr('value', l.ligne)
        .attr('data-communes', (l.communes || []).join(', '))
        .attr('data-courses', l.courses)
        .attr('data-fiche', l.fiche ? '1' : '0')
        .text(l.ligne + ' — ' + l.nom + (l.fiche ? '  ★ {{fiche officielle}}' : ''))
        .appendTo($g);
    });
    $s.append($g);
  });
  /* Une ligne réglée mais absente du référentiel ne doit pas disparaître
     du formulaire : on la garde visible plutôt que de la perdre. */
  if (choisie && !vues[choisie]) {
    $s.prepend($('<option>').attr('value', choisie)
      .text(choisie + ' — {{inconnue du référentiel}}'));
  }
  $s.val(choisie || '');
  busDetailLigne();
}

function busDetailLigne() {
  var o = $('#bus-ligne').find('option:selected');
  if (!o.length) { $('#bus-ligne-detail').text(''); return; }
  var courses = o.attr('data-courses');
  if (!courses) { $('#bus-ligne-detail').text(''); return; }
  var t = courses + ' {{courses au calendrier}}';
  var c = o.attr('data-communes');
  if (c) { t += ' · ' + c; }
  t += ' · ' + (o.attr('data-fiche') === '1'
    ? '{{horaires lus sur la fiche officielle}}'
    : '{{horaires du GTFS, à vérifier}}');
  $('#bus-ligne-detail').text(t);
}

function busChargerLignes(choisie, apres) {
  if (busInventaire) { busPeuplerLignes(choisie); if (apres) { apres(); } return; }
  $('#bus-ligne').empty().append($('<option>').text('{{Chargement...}}'));
  busAjaxEq('lignes', {}, function (r) {
    busInventaire = r;
    busPeuplerLignes(choisie);
    if (apres) { apres(); }
  }, function (m) {
    $('#bus-ligne').empty().append($('<option>').attr('value', choisie || '')
      .text(choisie || '{{service indisponible}}'));
    $('#bus-ligne-detail').text('{{Liste indisponible}} : ' + m);
    if (apres) { apres(); }
  });
}

/* ------------------------------------------------------------------ */
/* Les deux arrêts, groupés par commune                                */
/* ------------------------------------------------------------------ */

function busPeuplerArrets(arrets, maison, ecole) {
  [['#bus-arret-maison', maison], ['#bus-arret-ecole', ecole]].forEach(function (paire) {
    var $s = $(paire[0]);
    var voulu = paire[1];
    $s.empty();
    var commune = null, $g = null, vu = false;
    arrets.forEach(function (a) {
      if (a.commune !== commune) {
        commune = a.commune;
        $g = $('<optgroup>').attr('label', commune || '{{Sans commune}}');
        $s.append($g);
      }
      if (a.arret === voulu) { vu = true; }
      $('<option>').attr('value', a.arret).text(a.arret).appendTo($g);
    });
    if (voulu && !vu) {
      $s.prepend($('<option>').attr('value', voulu)
        .text(voulu + ' — {{non desservi par cette ligne}}'));
    }
    $s.val(voulu || '');
  });
}

function busChargerArrets(ligne, maison, ecole) {
  if (!ligne) { return; }
  busAjaxEq('arrets', { ligne: ligne }, function (r) {
    busPeuplerArrets(r, maison, ecole);
  }, function (m) {
    $('#div_alert').showAlert({ message: '{{Arrêts indisponibles}} : ' + m, level: 'warning' });
  });
}

/* Changer de ligne change les arrêts desservis : on recharge en gardant
   les choix courants s'ils tiennent encore. */
$('#bus-ligne').on('change', function () {
  busDetailLigne();
  busChargerArrets($(this).val(), $('#bus-arret-maison').val(), $('#bus-arret-ecole').val());
});

/* ------------------------------------------------------------------ */
/* Le lien de l'enfant                                                 */
/* ------------------------------------------------------------------ */

function busLienEnfant(_eqLogic) {
  var conf = (_eqLogic && _eqLogic.configuration) || {};
  if (!is_numeric(_eqLogic && _eqLogic.id)) {
    $('#bus-lien-zone').html('<em>{{Sauvegardez l\'équipement, puis créez le lien.}}</em>');
    $('#bt_busRevoquer').hide();
    return;
  }
  if (!conf.cle_enfant) {
    $('#bus-lien-zone').html('<em>{{Aucun lien pour l\'instant.}}</em>');
    $('#bt_busCle').html('<i class="fas fa-key"></i> {{Créer le lien}}');
    $('#bt_busRevoquer').hide();
    return;
  }
  busAjaxEq('lienEnfant', { id: _eqLogic.id }, function (r) {
    busAfficherLien(r.lien);
  });
}

function busAfficherLien(lien) {
  $('#bus-lien-zone').html(
    '<div class="input-group"><input class="form-control" id="bus-lien" readonly value="'
    + lien + '"/><span class="input-group-btn">'
    + '<a class="btn btn-default" id="bt_busCopier"><i class="fas fa-copy"></i></a>'
    + '</span></div>'
    + '<span class="help-block">{{À envoyer une seule fois : le téléphone le retient ensuite.}}</span>');
  $('#bt_busCle').html('<i class="fas fa-key"></i> {{Créer un nouveau lien}}');
  $('#bt_busRevoquer').show();
}

$('#bt_busCle').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value();
  if (!is_numeric(id)) {
    $('#div_alert').showAlert({ message: '{{Sauvegardez d\'abord l\'équipement.}}', level: 'warning' });
    return;
  }
  var existe = $('#bus-lien').length > 0;
  bootbox.confirm(existe
    ? '{{Créer un nouveau lien ? L\'ancien cessera aussitôt de fonctionner.}}'
    : '{{Créer le lien de la page enfant ?}}', function (oui) {
      if (!oui) { return; }
      busAjaxEq('genererCle', { id: id }, function (r) {
        busAfficherLien(r.lien);
        $('#div_alert').showAlert({ message: '{{Lien créé.}}', level: 'success' });
      }, function (m) { $('#div_alert').showAlert({ message: m, level: 'danger' }); });
    });
});

$('#bt_busRevoquer').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value();
  bootbox.confirm('{{Révoquer le lien ? La page de l\'enfant ne s\'ouvrira plus.}}', function (oui) {
    if (!oui) { return; }
    busAjaxEq('revoquerCle', { id: id }, function () {
      $('#bus-lien-zone').html('<em>{{Aucun lien pour l\'instant.}}</em>');
      $('#bt_busCle').html('<i class="fas fa-key"></i> {{Créer le lien}}');
      $('#bt_busRevoquer').hide();
      $('#div_alert').showAlert({ message: '{{Lien révoqué.}}', level: 'success' });
    }, function (m) { $('#div_alert').showAlert({ message: m, level: 'danger' }); });
  });
});

$(document).on('click', '#bt_busCopier', function () {
  var champ = document.getElementById('bus-lien');
  if (!champ) { return; }
  champ.select();
  try { document.execCommand('copy'); } catch (e) {}
  $('#div_alert').showAlert({ message: '{{Lien copié.}}', level: 'success' });
});

/* ------------------------------------------------------------------ */
/* Jeedom appelle ceci après avoir rempli les champs de l'équipement.  */
/* C'est le seul moment où l'on connaît les valeurs à sélectionner.    */
/* ------------------------------------------------------------------ */

function printEqLogic(_eqLogic) {
  var conf = (_eqLogic && _eqLogic.configuration) || {};
  busMaisonHeritee(conf);
  busEtatPronote(_eqLogic);
  busChargerLignes(conf.ligne || '', function () {
    busChargerArrets($('#bus-ligne').val(), conf.arret_maison || '', conf.arret_ecole || '');
  });
  busLienEnfant(_eqLogic);
}

/* Dire quelles coordonnées serviront réellement : celles saisies, ou
   celles du domicile réglé dans Jeedom. */
function busMaisonHeritee(conf) {
  var $z = $('#bus-maison-jeedom');
  if (!$z.length) { return; }
  if (conf.maison_lat && conf.maison_lon) {
    $z.text('{{Coordonnées propres à cet équipement.}}');
    return;
  }
  busAjaxEq('maisonJeedom', {}, function (r) {
    $z.text(r.lat && r.lon
      ? '{{Utilisera le domicile réglé dans Jeedom}} : ' + r.adresse
        + ' (' + r.lat + ', ' + r.lon + ')'
      : '{{Aucun domicile réglé dans Jeedom : le temps de marche du matin ne sera pas calculé.}}');
  }, function () { $z.text(''); });
}

/* Quand le lien est fait, les cinq champs ci-dessous ne servent plus :
   il faut le dire, sinon le formulaire ment. On les grise et on affiche
   au-dessus ce qui s'applique réellement. */
function busEtatPronote(_eqLogic) {
  var conf = (_eqLogic && _eqLogic.configuration) || {};
  var $champs = $('.eqLogicAttr[data-l2key^="sortie_"]');
  var $z = $('#bus-pronote-lu');
  if (conf.pronote_eqlogic === 'aucun') {
    $champs.prop('readonly', false).css('opacity', '');
    $z.html('<span class="label label-default">'
      + '{{Lien refusé : les heures ci-dessous font foi.}}</span>');
    return;
  }
  $champs.css('opacity', '.55').attr('title',
    '{{Remplacé par l\'emploi du temps tant que le lien est actif.}}');
  busAjaxEq('pronote', { id: _eqLogic.id, source: conf.pronote_eqlogic },
    function (r) {
      if (!r.heures || !Object.keys(r.heures).length) {
        $z.html('<div class="alert alert-warning" style="padding:8px 11px;margin:0;">'
          + '<b>{{Aucun enfant rattaché}}</b><br><small>'
          + '{{S\'il y a plusieurs enfants dans votre plugin scolaire, choisissez le vôtre ci-dessus : le plugin ne devine pas, pour ne pas se tromper de trajet. Sinon, les heures ci-dessous font foi.}}'
          + '</small></div>');
        $champs.css('opacity', '');
        return;
      }
      var l = [];
      ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi'].forEach(function (j) {
        if (r.heures[j]) { l.push(j.substr(0, 3) + ' ' + r.heures[j]); }
      });
      $z.html('<div class="alert alert-info" style="padding:8px 11px;margin:0;">'
        + '<b>{{Détecté — les heures viennent de l\'emploi du temps}}</b> : ' + r.source
        + '<br><span style="font-family:monospace;font-size:12px;">' + l.join(' · ')
        + '</span><br><small>{{Lu le}} ' + (r.lu || '?')
        + '. {{Les champs ci-dessous ne servent que si le lien est coupé.}}</small></div>');
    },
    function (m) {
      $z.html('<span class="label label-danger">' + m + '</span>');
      $champs.css('opacity', '');
    });
}

/* Ce que l'emploi du temps raconte, avant de s'y fier. On montre les
   deux colonnes — ce qui est réglé, ce qui est lu — parce que c'est la
   comparaison qui décide. */
$('#bus-pronote').on('change', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value();
  busEtatPronote({ id: id, configuration: { pronote_eqlogic: $(this).val() } });
});

$('#bt_busPronote').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value();
  var src = $('#bus-pronote').val();
  var $z = $('#bus-pronote-lu');
  if (!src) {
    $z.html('<span class="label label-default">{{Aucune source choisie : les heures réglées ici font foi.}}</span>');
    return;
  }
  $z.html('<i class="fas fa-spinner fa-spin"></i> {{Lecture...}}');
  busAjaxEq('pronote', { id: id, source: src }, function (r) {
    if (!r.heures || !Object.keys(r.heures).length) {
      $z.html('<span class="label label-warning">'
        + '{{Rien de lisible dans cet emploi du temps.}}</span>');
      return;
    }
    var t = '<table class="table table-condensed" style="margin-bottom:6px;">'
      + '<thead><tr><th>{{Jour}}</th><th>{{Réglé ici}}</th>'
      + '<th>{{Emploi du temps}}</th></tr></thead><tbody>';
    ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi'].forEach(function (j) {
      var lu = r.heures[j];
      var regle = r.regle[j] || '—';
      var diff = lu && lu !== regle;
      t += '<tr' + (diff ? ' class="warning"' : '') + '><td>' + j + '</td>'
        + '<td>' + regle + '</td><td><b>' + (lu || '—') + '</b>'
        + (diff ? ' <small>{{remplacera}}</small>' : '') + '</td></tr>';
    });
    t += '</tbody></table><span class="help-block">{{Lu le}} ' + (r.lu || '?')
      + ' {{sur}} ' + r.source + '</span>';
    $z.html(t);
  }, function (m) {
    $z.html('<span class="label label-danger">' + m + '</span>');
  });
});

/* ------------------------------------------------------------------ */
/* Le tableau des commandes                                            */
/* ------------------------------------------------------------------ */

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) { var _cmd = { configuration: {} }; }
  if (!isset(_cmd.configuration)) { _cmd.configuration = {}; }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
  tr += '<td class="hidden-xs">';
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="id" style="display:none;">';
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="name">';
  tr += '</td>';
  tr += '<td>';
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
  tr += '</td>';
  tr += '<td>';
  if (init(_cmd.type) == 'info') {
    tr += '<span class="cmdValue label label-info" style="font-size:1em;cursor:default;">' +
          (isset(_cmd.state) ? _cmd.state : '') + '</span>';
  }
  tr += '</td>';
  tr += '<td>';
  tr += '<label class="checkbox-inline">';
  tr += '<input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/> {{Afficher}}';
  tr += '</label>';
  tr += '<label class="checkbox-inline">';
  tr += '<input type="checkbox" class="cmdAttr" data-l1key="isHistorized"/> {{Historiser}}';
  tr += '</label>';
  tr += '<div style="margin-top:5px;">';
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction expertModeVisible" data-action="configure">' +
          '<i class="fa fa-cogs"></i></a> ';
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test">' +
          '<i class="fa fa-rss"></i> {{Tester}}</a>';
  }
  tr += '</div>';
  tr += '</td>';
  tr += '</tr>';

  $('#table_cmd tbody').append(tr);
  var tr = $('#table_cmd tbody tr').last();
  jeedom.eqLogic.builSelectCmd({
    id: $('.eqLogicAttr[data-l1key=id]').value(),
    filter: { type: 'info' },
    error: function (error) { $('#div_alert').showAlert({ message: error.message, level: 'danger' }); },
    success: function () {
      tr.setValues(_cmd, '.cmdAttr');
      jeedom.cmd.changeType(tr, init(_cmd.subType));
    }
  });
}

/* ------------------------------------------------------------------ */
/* « Lire maintenant » : le trajet affiché, sans rien sauvegarder      */
/* ------------------------------------------------------------------ */

$('#bt_lireMaintenant').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value();
  if (!is_numeric(id)) {
    $('#div_alert').showAlert({
      message: '{{Sauvegardez l\'équipement avant de le lire.}}', level: 'warning'
    });
    return;
  }
  var $sortie = $('#resultatTest');
  $sortie.show().text('{{Interrogation du service...}}');
  var trajet = {};
  $('.eqLogicAttr[data-l1key=configuration]').each(function () {
    var cle = $(this).attr('data-l2key');
    if (cle) { trajet[cle] = $(this).value(); }
  });
  busAjaxEq('lire', { id: id, trajet: JSON.stringify(trajet) }, function (r) {
    $sortie.text(JSON.stringify(r, null, 2));
    $('#div_alert').showAlert({ message: '{{Le service répond.}}', level: 'success' });
  }, function (m) { $sortie.text(m); });
});
