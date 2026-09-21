# Bus scolaires

Le plugin affiche l'heure du car scolaire, et surtout **l'heure à
laquelle il faut partir** de la maison ou de l'école pour l'attraper. La
marche jusqu'à l'arrêt est comptée, et l'on part avec de l'avance plutôt
qu'à la seconde près.

Si le car est manqué, ou si aucun car ne circule ce jour-là, le plugin
cherche un **trajet de remplacement** sur le réseau urbain. Il le
propose étape par étape : où marcher, quel bus prendre, où descendre.

Une règle décide de tout le reste : **le car scolaire passe avant le
réseau**, même s'il faut l'attendre une heure, parce que c'est lui qui
dépose devant la maison. Le plugin ne cherche un autre chemin que lorsqu'il
n'y a plus de car du tout : le vendredi midi, par exemple, ou quand le
dernier est passé.

Tout est exposé en commandes utilisables en scénario, en quatre widgets,
et en une **page dédiée à l'enfant**, faite pour un téléphone, où il n'y a
rien à régler.

## Réseau couvert

> **Le plugin ne fonctionne aujourd'hui que sur le réseau de la Métropole
> d'Aix-Marseille-Provence.** Ailleurs, il s'installe mais ne trouve aucun
> horaire.

Il lit trois sources publiques, toutes publiées par la Métropole :

| Source | Contenu | Rôle dans le plugin |
|---|---|---|
| GTFS **Pays d'Aix Mobilité** | lignes interurbaines et scolaires | calendrier scolaire, arrêts, horaires de secours |
| GTFS **Aix en Bus** | réseau urbain d'Aix-en-Provence | trajets de remplacement |
| Alertes **GTFS-RT** | perturbations en cours | commande `perturbations` |

Les deux flux GTFS sont diffusés sur
[transport.data.gouv.fr](https://transport.data.gouv.fr), jeu « Réseaux
urbains de la Métropole Aix-Marseille-Provence », sous **Licence Ouverte
2.0** (Etalab). Les adresses de téléchargement contiennent une clé : c'est
la Métropole qui la publie avec le lien, ce n'est pas un secret.

Pour les horaires du car scolaire lui-même, le plugin s'appuie en priorité
sur la **fiche horaire officielle** de la ligne (un PDF que vous importez).
Pourquoi ? Parce que le GTFS se trompe : sur la ligne qui a servi à écrire
le plugin, il oubliait des retours du mercredi midi et annonçait des
courses qui ne circulent pas. La fiche fait foi.

## Prérequis

- Jeedom **4.4** ou plus récent, sur Debian. Testé en PHP 8.2 et 8.4.
- Un accès à Internet depuis la box, pour télécharger le réseau et les
  alertes.
- Environ **200 Mo** de disque pour l'index du réseau, et une centaine de
  Mo de mémoire pour le démon.
- Pour que la page de l'enfant marche hors de la maison : un accès à
  Jeedom depuis l'extérieur (le service DNS Jeedom suffit).

## Installation

1. Installez le plugin, puis **activez-le**.
2. Onglet **Dépendances** → *Relancer*. Le plugin installe par le
   gestionnaire de paquets `python3`, `python3-venv`, `python3-dev` et
   `poppler-utils` (pour lire les fiches PDF), puis crée son propre
   environnement Python isolé dans `resources/python_venv` : ses
   bibliothèques n'y touchent pas le reste du système.
3. Onglet **Démon** → *Démarrer*. Jeedom le surveille et le relance seul
   s'il s'arrête.
4. Dans la **configuration du plugin**, renseignez les communes à indexer
   et importez la fiche horaire de votre ligne (voir plus bas).
5. **Ajoutez un équipement** par enfant.

Au premier démarrage, l'index du réseau n'existe pas : le plugin le
construit tout seul. Comptez deux ou trois minutes. C'est un cache : le
supprimer ne fait rien perdre, il se reconstruit.

## Configuration du plugin

Trois réglages, communs à tous les enfants.

**Port du démon** — `55810` par défaut. Le démon n'écoute que sur la
machine elle-même (`127.0.0.1`). À changer uniquement si ce port est déjà
pris.

**Communes à indexer** — les communes traversées par vos trajets, séparées
par des virgules, par exemple :

```
Ma commune, La ville du lycée, Le village entre les deux
```

Le réseau complet compte près de deux millions d'horaires. N'indexer que
les communes utiles rend l'index bien plus léger et les calculs plus
rapides. Laissé vide, tout le réseau est indexé. Après un changement,
**reconstruisez l'index** (bouton *Reconstruire l'index*).

**Fiche horaire officielle** — indiquez le numéro de la ligne scolaire,
choisissez le PDF publié par le réseau, puis *Importer*. La lecture suit
les colonnes du tableau, pas le texte brut. À refaire **à chaque
rentrée**, ou quand le réseau publie une nouvelle fiche.

## Ajouter un enfant

Un équipement par enfant, et **son trajet vit sur l'équipement**. Deux
enfants, deux écoles, deux lignes : deux équipements, et rien à partager.

| Réglage | Exemple fictif | À quoi il sert |
|---|---|---|
| Ligne | `1234` | la ligne du car scolaire |
| Arrêt côté maison | `Place du Village` | où l'enfant monte le matin |
| Arrêt côté école | `Gare routière` | où l'enfant descend |
| Adresse de l'école | `12 rue de l'École` | la marche de l'arrêt à l'école |
| Coordonnées de l'école | latitude, longitude | pour compter cette marche |
| Heures de sortie | par jour de la semaine | quel car prendre le soir |
| Vitesse de marche | 80 m/min | le temps pour rejoindre l'arrêt |
| Avance au départ | 10 min | partir avant le car, pas pile à l'heure |
| Marche acceptée | 15 min | jusqu'où chercher un arrêt de repli |

Les **coordonnées de la maison** viennent de celles de votre Jeedom
(*Réglages → Système → Configuration*). Inutile de les saisir deux fois.

Le **sens** se choisit tout seul : l'aller le matin, le retour l'après-midi.
Vous pouvez le forcer pour un scénario qui ne s'intéresse qu'à l'un des
deux.

### Plusieurs widgets pour un même enfant

Chaque équipement a un **rôle**, qui décide de son widget :

| Rôle | Ce qu'il montre |
|---|---|
| **Quai** | le trajet du jour : l'heure de partir, le car, l'arrivée |
| **Plan B** | le trajet de remplacement quand le car manque |
| **Carte** | le tracé de la ligne et la position des arrêts |
| **Libre** | comment rentrer depuis là où se trouve l'enfant |

Pour poser plusieurs widgets sans ressaisir le trajet, créez un
équipement par rôle et réglez *Reprendre le trajet de* sur le premier.

### Heures de fin de cours

Réglées à la main, jour par jour. Si un plugin d'**emploi du temps** est
installé, le plugin le détecte tout seul et prend les vraies heures de fin
de cours, cours annulés compris. Le lien est affiché dans l'équipement, et
vous pouvez le couper.

## La page de l'enfant

Un écran qui ne dit **qu'une seule chose à la fois** : un état en couleur,
un verbe en grand, l'étape en cours. « Reste à la maison, tu pars à 07:10 »,
puis « Pars maintenant », puis « Tu descends à l'arrêt du lycée ». Jamais
une soustraction à poser, jamais deux consignes en même temps.

Quand le car ne vient pas, l'écran bascule tout seul sur le trajet de
remplacement, sans que l'enfant ait rien à déclarer. Sans réseau, il garde
le dernier écran connu. Si le service est coupé, il affiche « Attends un
peu — ça revient tout seul » et réessaie toutes les dix secondes.

Deux façons d'y accéder, au choix.

**Son compte Jeedom.** Dans l'équipement, champ *Son compte Jeedom* :
désignez le compte de l'enfant. Connecté avec ce compte, il ouvre sa page
sans rien d'autre à retenir. Rien n'est deviné : sans désignation, personne
n'est reconnu, et les comptes administrateurs ne sont pas proposés. Un
administrateur peut toujours regarder la page pour vérifier ce qu'elle
affiche.

Une session Jeedom finit par expirer, et elle ne vaut que pour **une
adresse** : l'adresse locale et l'adresse extérieure sont deux sessions
distinctes. Pour un téléphone qu'on ne veut plus toucher, préférez le lien.

**Le lien secret.** Dans l'équipement, bouton *Créer le lien*. La page
s'ouvre alors sans compte ni mot de passe, et ne donne accès qu'au trajet
de cet enfant. À la première ouverture, le téléphone range la clé dans un
cookie valable **400 jours** et l'adresse se nettoie : la clé ne traîne ni
dans l'historique, ni dans les journaux. *Créer un nouveau lien* annule
l'ancien, *Révoquer* ferme la page.

La page se contente de **lire** : aucun réglage n'y est joignable, quelle
que soit la façon d'y entrer.

## Commandes

Treize commandes info par équipement :

| Commande | Type | Contenu |
|---|---|---|
| `sens` | texte | `aller` ou `retour` |
| `car` | texte | le circuit retenu |
| `heure_car` | texte | le passage du car à l'arrêt |
| `heure_depart` | texte | l'heure de départ à pied |
| `minutes_avant` | numérique | les minutes avant de partir |
| `arret` | texte | l'arrêt de montée |
| `destination` | texte | l'arrêt de descente |
| `heure_arrivee` | texte | l'arrivée à cet arrêt |
| `fin_cours` | texte | la fin des cours du jour |
| `perturbations` | numérique | le nombre d'alertes en cours |
| `jour_de_classe` | binaire | 1 si le car circule aujourd'hui |
| `texte` | texte | le résumé en une phrase |
| `source_horaires` | texte | d'où viennent les heures de sortie |

Cinq de plus sur un équipement **Plan B** :

| Commande | Contenu |
|---|---|
| `solution_depart` | l'heure à laquelle partir |
| `solution_arrivee` | l'heure d'arrivée |
| `solution_duree` | la durée du trajet |
| `solution_resume` | les lignes empruntées |
| `solutions_nb` | le nombre de trajets trouvés |

Et deux commandes action :

- `rafraichir` — force une lecture immédiate ;
- `definir_sortie` — pousse l'heure de fin des cours du jour, au format
  `16:05`, quand un dernier cours saute. Utilisable en scénario.

## Exemples de scénarios

**Annonce du matin** — déclencheur : `minutes_avant` change.

```
Si [Trajet] minutes_avant <= 10 et [Trajet] jour_de_classe == 1
   Alors dire « Le car passe à #heure_car#, il faut partir »
```

**Dernier cours annulé** — action, quand vous l'apprenez :

```
[Trajet] definir_sortie avec le message « 15:00 »
```

**Perturbation** — déclencheur : `perturbations` change.

```
Si [Trajet] perturbations > 0
   Alors notifier avec #texte#
```

## Jours de classe, vacances et jours fériés

Le plugin ne tient aucun calendrier scolaire lui-même. Il lit celui du
**réseau** : une ligne scolaire ne circule que les jours de classe, et le
GTFS le dit, vacances et jours fériés compris. Un jour sans car, la
commande `jour_de_classe` vaut 0 et les écrans l'annoncent simplement.

Les demi-journées, le mercredi ou le vendredi midi selon les
établissements, se règlent par les heures de sortie. S'il n'existe aucun
car à cette heure-là, le plugin propose directement le trajet de
remplacement.

## Ce que le plugin fait quand le car ne vient pas

Le réseau ne publie pas la position des cars scolaires en temps réel. Le
plugin ne peut donc pas savoir qu'un car est en retard : il attend.

- **Un quart d'heure après l'heure du car**, il cesse d'attendre. Le
  matin, il cherche un trajet vers l'école ; le soir, s'il reste un car
  plus tard, il l'annonce, sinon il cherche un trajet vers la maison.
- Pendant ce quart d'heure, il ne dit pas que le car n'est pas venu :
  l'enfant est peut-être dedans.
- **Un trajet commencé ne change plus** : une fois l'enfant parti, le
  plugin ne lui propose pas un autre bus en cours de route.
- Une heure et demie après l'heure du car, le plugin cesse de proposer
  quoi que ce soit pour ce trajet : la journée est entamée.

## Dépannage

**La page de l'enfant répond « Cette page n'existe pas ».** Le compte
connecté n'est pas celui désigné sur l'équipement, ou la session a expiré.
Reconnectez-vous avec le bon compte, ou utilisez le lien secret.

**La page affiche « Attends un peu ».** Le démon est arrêté. Jeedom le
relance seul, en général en moins d'une minute. S'il reste arrêté,
regardez l'onglet *Démon* et le journal `busscolaires_daemon`.

**Aucun horaire, `jour_de_classe` toujours à 0.** L'index n'est pas encore
construit (attendez quelques minutes après le premier démarrage), ou vos
arrêts ne sont pas dans les communes indexées. Vérifiez la liste des
communes et reconstruisez l'index.

**Un horaire faux.** La fiche officielle fait foi : réimportez celle de
l'année en cours. Si l'erreur vient d'un jour sans car, c'est le calendrier
du réseau qui en décide.

**Un trajet de remplacement étrange.** Le plugin préfère une
correspondance de moins à un quart d'heure de marche, et une ligne
fréquente à une ligne rare. Augmentez la *marche acceptée* pour lui laisser
plus de choix.

## Limites connues

- **Un seul réseau** : la Métropole d'Aix-Marseille-Provence. Les sources
  sont celles de ce réseau.
- **La lecture des fiches PDF** est calibrée sur la mise en page des fiches
  de ce réseau. Une fiche au format très différent peut ne pas être lue.
- **Pas de position des cars en temps réel** : le réseau ne la publie pas
  pour les lignes scolaires. Seules les alertes le sont.
- **Une ligne scolaire par équipement.** Un enfant qui change de ligne
  selon les jours demande deux équipements.
- Hors de la maison, **la page de l'enfant a besoin d'un accès extérieur**
  à Jeedom.

## Pour les développeurs de plugins

Un plugin qui porte un emploi du temps peut savoir qui le lit :

```php
if (class_exists('busscolaires')) {
    $lecteurs = busscolaires::utilisateursDe($eqLogic->getId());
    // => array('Trajet de l\'aîné')
}
```

C'est celui qui lit qui répond : le plugin d'emploi du temps n'a aucun
registre à tenir, et fonctionne à l'identique sans celui-ci.

Dans l'autre sens, ce plugin détecte seul un emploi du temps installé. Il
cherche un équipement exposant une commande `timetable_week_html`, dont les
journées sont balisées `<ul data-date="AAAA-MM-JJ">` et les cours
`<li data-end="HHMM">`, les cours annulés portant `class="cancelled"`.

## Licence

Le plugin est distribué sous licence **GPL-3.0**. Les données de transport
sont celles de la Métropole d'Aix-Marseille-Provence, sous Licence Ouverte
2.0.
