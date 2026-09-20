/* Configuration du plugin : uniquement le commun. Le trajet se règle sur
   chaque équipement, un par enfant. */

function busAjax(action, donnees, succes, echec) {
  $.ajax({
    type: 'POST',
    url: 'plugins/busscolaires/core/ajax/busscolaires.ajax.php',
    data: $.extend({ action: action }, donnees || {}),
    dataType: 'json',
    global: false,
    error: function (r, statut, err) {
      if (echec) { echec(statut + ' ' + err); }
      else { $('#div_alert').showAlert({ message: statut + ' ' + err, level: 'danger' }); }
    },
    success: function (data) {
      if (data.state != 'ok') {
        if (echec) { echec(data.result); }
        else { $('#div_alert').showAlert({ message: data.result, level: 'danger' }); }
        return;
      }
      if (succes) { succes(data.result); }
    }
  });
}

function busPuce(ok, texte) {
  return '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;'
       + 'background:' + (ok ? '#4a9c6d' : '#c0504d') + ';margin-right:7px;"></span>' + texte;
}

function busLireEtat() {
  var $z = $('#bus-etat');
  $z.html('<i class="fas fa-spinner fa-spin"></i> {{Lecture...}}');
  busAjax('etat', {}, function (r) {
    var h = '<div>' + busPuce(r.demon, r.demon ? '{{Démon en marche}}' : '{{Démon arrêté}}') + '</div>';
    h += '<div>' + busPuce(r.dependances, r.dependances
        ? '{{Dépendances installées}}' : '{{Dépendances manquantes}}') + '</div>';
    if (r.demon) {
      h += '<div>' + busPuce(r.arrets > 0, r.arrets > 0
          ? ('{{Réseau}} : ' + r.arrets + ' {{arrêts}}, ' + r.horaires + ' {{horaires}}')
          : '{{Index du réseau vide}}') + '</div>';
      if (r.lignes) {
        h += '<div>' + busPuce(true, r.lignes + ' {{lignes}}, {{dont}} '
            + r.scolaires + ' {{scolaires}}') + '</div>';
      }
      var f = [];
      for (var l in r.fiches) {
        f.push(l + ' (' + r.fiches[l].courses + ' {{courses}})');
      }
      h += '<div>' + busPuce(f.length > 0, f.length
          ? '{{Fiches officielles}} : ' + f.join(', ')
          : '{{Aucune fiche officielle}}') + '</div>';
      h += '<div>' + busPuce(true, '{{Perturbations en cours}} : ' + r.alertes) + '</div>';
    }
    /* Le lien scolaire : dit en clair s'il est fait, pour qui, et
       quand l'emploi du temps a été lu. */
    if (r.enfants && r.enfants.length) {
      h += '<hr style="margin:9px 0;">';
      r.enfants.forEach(function (e) {
        if (e.etat === 'a_choisir') {
          h += '<div>' + busPuce(false, e.nom
            + ' : <b>{{plusieurs enfants — choisissez lequel}}</b>') + '</div>'
            + '<div style="margin:-2px 0 4px 16px;font-size:11.5px;opacity:.7;">'
            + '{{dans les réglages de cet équipement, champ Enfant}}</div>';
        } else if (e.etat === 'aucun_plugin') {
          h += '<div>' + busPuce(true, e.nom
            + ' : {{heures réglées à la main}}') + '</div>';
        } else if (e.etat === 'refuse') {
          h += '<div>' + busPuce(true, e.nom + ' : {{heures réglées à la main}}') + '</div>';
        } else if (e.enfant) {
          h += '<div>' + busPuce(true, e.nom + ' : {{emploi du temps de}} <b>'
            + e.enfant + '</b>, ' + e.jours + ' {{jours lus}}') + '</div>'
            + '<div style="margin:-2px 0 4px 16px;font-size:11.5px;opacity:.7;">'
            + '{{lu le}} ' + (e.lu || '?')
            + (e.annules ? ' · <b>' + e.annules + ' {{cours annulé(s) écarté(s)}}</b>' : '')
            + '</div>';
        } else {
          h += '<div>' + busPuce(false, e.nom
            + ' : {{aucun emploi du temps trouvé}}') + '</div>';
        }
      });
    }

    if (r.index_en_cours) {
      h += '<div style="margin-top:6px;"><i class="fas fa-spinner fa-spin"></i> '
         + '{{Reconstruction de l\'index en cours}}</div>';
    }
    $z.html(h);
  }, function (m) {
    $z.html(busPuce(false, '{{Erreur}} : ') + '<span style="font-size:12px;">' + m + '</span>');
  });
}

$('#bt_busEtat').on('click', busLireEtat);

$('#bt_busIndex').on('click', function () {
  bootbox.confirm('{{Reconstruire l\'index du réseau ? Cela prend quelques minutes et ne fait rien perdre.}}',
    function (oui) {
      if (!oui) { return; }
      busAjax('reconstruire', {}, function () {
        $('#div_alert').showAlert({ message: '{{Reconstruction lancée.}}', level: 'success' });
        busLireEtat();
      });
    });
});

/* Import d'une nouvelle fiche PDF : une fois par an, à la rentrée. */
$('#bt_busFiche').on('click', function () {
  var champ = document.getElementById('bus-fiche-pdf');
  var ligne = $('#bus-fiche-ligne').value();
  if (!ligne) {
    $('#div_alert').showAlert({ message: '{{Indiquez la ligne concernée.}}', level: 'warning' });
    return;
  }
  if (!champ || !champ.files || champ.files.length === 0) {
    $('#div_alert').showAlert({ message: '{{Choisissez un fichier PDF.}}', level: 'warning' });
    return;
  }
  var $sortie = $('#bus-fiche-resultat');
  $sortie.show().text('{{Lecture de la fiche...}}');
  var formulaire = new FormData();
  formulaire.append('action', 'importerFiche');
  formulaire.append('ligne', ligne);
  formulaire.append('fiche', champ.files[0]);
  $.ajax({
    type: 'POST',
    url: 'plugins/busscolaires/core/ajax/busscolaires.ajax.php',
    data: formulaire, contentType: false, processData: false,
    dataType: 'json', global: false,
    error: function (r, statut, err) { $sortie.text(statut + ' ' + err); },
    success: function (data) {
      if (data.state != 'ok') { $sortie.text(data.result); return; }
      $sortie.text(data.result.journal);
      $('#div_alert').showAlert({ message: '{{Fiche importée.}}', level: 'success' });
      busLireEtat();
    }
  });
});

busLireEtat();
