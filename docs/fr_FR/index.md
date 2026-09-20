# Bus scolaires — plugin Jeedom

Affiche l'heure du car scolaire et, surtout, **l'heure à laquelle il
faut partir** de la maison ou de l'école pour l'attraper. Quand le car
est manqué, propose la solution de repli.

La règle qui gouverne tout le reste : **le car scolaire passe avant le
réseau**, même s'il faut l'attendre une heure — c'est celui qui dépose
devant chez soi. Le plugin ne cherche un autre chemin que lorsqu'il n'y a
plus de car du tout : le vendredi midi, ou quand le dernier est passé
sans elle.

Le plugin sert aussi une **page dédiée à l'enfant**, faite pour un
téléphone : pas de compte, pas de réglage, juste le car et l'heure de
partir. Seul l'adulte, administrateur de Jeedom, règle quoi que ce soit.

## Comment c'est fait

Le plugin porte les réglages et l'affichage. Un **démon Python** porte
le calcul, sur `127.0.0.1` uniquement :

- il lit la **fiche horaire officielle** de la ligne, livrée avec le
  plugin, qui fait autorité ;
- il indexe le **GTFS** du réseau pour le calendrier scolaire, les
  coordonnées des arrêts et les lignes de repli ;
- il lit les **alertes trafic** GTFS-RT en direct ;
- il choisit le car et calcule les solutions de repli.

Pourquoi la fiche officielle plutôt que le GTFS ? Parce que le GTFS se
trompe. Sur la ligne testée, il manquait quatre retours du mercredi midi
et annonçait six courses qui ne circulent pas. La fiche prime.

Rien n'écoute sur le réseau : le démon est sur la boucle locale, et la
page de l'enfant passe par un relais qui n'accepte qu'une liste fermée
de lectures.

Le démon est déclaré à Jeedom, qui le surveille et le relance seul. Deux
précautions valent d'être connues, parce qu'elles ont coûté une panne :
un fichier de PID manquant n'est **pas** une preuve de mort — le plugin
demande au système ce qui tourne vraiment — et l'on ne tue jamais un
démon qui répond, même si on nous demande de le démarrer.

## Ce que le plugin expose

Douze commandes info par équipement :

| Commande | Type | Contenu |
|---|---|---|
| `sens` | texte | `aller` ou `retour` |
| `car` | texte | circuit retenu |
| `heure_car` | texte | passage du car à l'arrêt |
| `heure_depart` | texte | heure de départ à pied, marche comprise |
| `minutes_avant` | numérique | minutes restantes avant de partir |
| `arret` | texte | arrêt de montée |
| `destination` | texte | arrêt de descente |
| `heure_arrivee` | texte | arrivée à cet arrêt |
| `fin_cours` | texte | fin des cours du jour |
| `perturbations` | numérique | nombre d'alertes en cours |
| `jour_de_classe` | binaire | 1 si le car circule aujourd'hui |
| `texte` | texte | résumé en une phrase |

Et deux commandes action :

- `rafraichir` — force une lecture immédiate ;
- `definir_sortie` — pousse la fin des cours du jour, au format `16:05`,
  quand un dernier cours saute. Utilisable en scénario.

## Où se règle quoi

C'est la question qui compte, et la réponse tient en une ligne :
**un équipement par enfant, et le trajet vit sur l'équipement.**

| Sur l'équipement | Dans la configuration du plugin |
|---|---|
| La ligne, les deux arrêts | Le port du démon |
| L'adresse de la maison et de l'école | L'état du service, l'index du réseau |
| Marche, correspondance, préavis | L'import d'une fiche horaire PDF |
| Les heures de fin de cours | |
| Le sens suivi | |
| Le lien de la page enfant | |

Deux enfants, deux écoles, deux lignes : deux équipements, et rien à
partager. Le démon, lui, ne mémorise aucun trajet — chaque appel lui
joint celui dont il s'agit. C'est un calculateur, pas un dossier.

## Installation

Le plugin a des dépendances (un environnement Python isolé) et un démon.
Les deux sont gérés par Jeedom.

1. Installer le plugin, puis l'**activer**.
2. Onglet **Dépendances** → *Relancer*. Cela crée
   `resources/python_venv` et installe `poppler-utils`.
3. Onglet **Démon** → *Démarrer*.
4. **Configuration** du plugin : les arrêts, l'adresse de l'école, les
   heures de fin de cours.
5. **Ajouter** un équipement, laisser le sens en *Automatique*,
   sauvegarder, puis *Lire maintenant*.

Au premier démarrage, l'index GTFS n'existe pas : le plugin le
construit tout seul. Comptez deux minutes, et environ **200 Mo** dans
`data/`. C'est un cache : le supprimer ne fait rien perdre.

## La page de l'enfant

Un écran qui ne dit **qu'une seule chose à la fois** : un état coloré, un
verbe en grand, l'étape en cours. Jamais une soustraction à poser, jamais
deux ordres en même temps — « Reste à la maison, tu pars à 06:46 », puis
« Pars maintenant », puis « Descends à l'arrêt du lycée ». Quand le car ne
vient pas, l'écran bascule tout seul sur la route de secours, étape par
étape, sans qu'elle ait rien à déclarer. Elle garde le dernier écran
connu quand le réseau manque, et le service coupé, elle lit « Attends un
peu — ça revient tout seul ».

Deux portes y mènent, au choix.

**Son compte Jeedom.** Sur l'équipement, champ *Son compte Jeedom* :
l'adulte désigne le compte de l'enfant, et la page s'ouvre pour elle sans
rien à retenir. Rien n'est deviné — sans désignation, personne n'est
reconnu — et les comptes administrateurs ne sont pas proposés. Un
administrateur, lui, peut toujours regarder la page pour vérifier ce
qu'elle a sous les yeux.

Attention : une session Jeedom expire (une heure par défaut sur certaines
installations), et le cookie de session vaut pour **une seule adresse** —
celle du réseau local et celle de l'accès extérieur sont deux mondes
distincts. Pour un téléphone qu'on ne veut plus toucher, préférez le lien.

**Le lien secret.** Sur **l'équipement de l'enfant**, bouton
*Créer le lien*. Chaque enfant
a le sien : le lien ne donne accès qu'à son trajet, et le trafiquer ne
donne rien — l'équipement impose le sien par-dessus tout paramètre
d'URL. Tant que
vous ne l'avez pas fait, la page n'existe pas — elle répond 404.

Le lien porte une clé de 128 bits. À la première ouverture, le
téléphone la range dans un cookie et l'adresse se nettoie : la clé ne
traîne ni dans l'historique, ni dans les journaux du serveur. C'est
aussi ce qui permet à l'application, une fois installée sur l'écran
d'accueil, de s'ouvrir sans le lien.

Son cookie vit **400 jours** et n'a rien à voir avec la session Jeedom :
il ne se déconnecte pas, ne demande pas de mot de passe et survit aux
expirations. C'est ce qu'il faut sur un téléphone.

*Créer un nouveau lien* annule le précédent. *Révoquer* ferme la page.

Pour que la page soit joignable depuis l'école, il faut que Jeedom soit
accessible de l'extérieur — le service **DNS Jeedom** suffit, et son
certificat reconnu est ce qui permet à l'application de fonctionner hors
ligne et de recevoir des notifications.

## Pour les autres plugins

Un plugin qui porte un emploi du temps peut savoir qui le lit :

```php
if (class_exists('busscolaires')) {
    $lecteurs = busscolaires::utilisateursDe($eqLogic->getId());
    // => array('Car de l\'aîné')
}
```

C'est celui qui lit qui répond : le plugin scolaire n'a aucun registre à
tenir, et il continue de fonctionner à l'identique sans ce plugin-ci.

Dans l'autre sens, ce plugin détecte tout seul un emploi du temps
installé — il cherche un équipement exposant une commande
`timetable_week_html`, dont les journées sont balisées
`<ul data-date="AAAA-MM-JJ">` et les cours `<li data-end="HHMM">`, les
cours annulés portant `class="cancelled"`.

## Exemples de scénarios

**Annonce le matin** — déclencheur : `minutes_avant` change.

```
Si [Trajet] minutes_avant <= 10 et [Trajet] jour_de_classe == 1
   Alors dire « Le car passe à #heure_car#, il faut partir »
```

**Cours annulé** — action, quand vous l'apprenez :

```
[Trajet] definir_sortie avec le message « 15:00 »
```

**Perturbation** — déclencheur : `perturbations` change.

```
Si [Trajet] perturbations > 0
   Alors notifier avec #texte#
```

## Fiche horaire d'une nouvelle année

Dans la configuration du plugin, *Importer* le PDF officiel. La lecture
est géométrique : elle suit les colonnes du tableau, pas le texte brut.
`poppler-utils` est nécessaire — il est installé avec les dépendances.

## Version mobile

Deux surfaces, chacune à sa place : le **widget de tableau de bord**
pour l'adulte, responsive, avec le compte à rebours en heures et
minutes et un code couleur selon l'urgence ; la **page de l'enfant**,
installable sur l'écran d'accueil et utilisable hors ligne.

## Licence

GPL-3.0.
