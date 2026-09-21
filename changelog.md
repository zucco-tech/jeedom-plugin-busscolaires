# Changelog

## 2.39

- Le formulaire d'équipement proposait en exemple les coordonnées d'une
  école bien réelle, restées du développement. Il demande maintenant
  « latitude » et « longitude », sans désigner l'école de personne.

## 2.38 — première version publique

Le plugin a vécu deux ans dans une seule maison avant d'être publié. Ce
qui suit résume ce qu'il sait faire et ce que cet usage quotidien a
appris ; les versions antérieures n'ont pas circulé.

**Ce qu'il fait**

- L'heure du car scolaire, et surtout **l'heure de partir** : la marche
  jusqu'à l'arrêt est comptée, et l'on part avec de l'avance plutôt qu'à
  la seconde près.
- Un **trajet de remplacement** par le réseau quand le car est manqué ou
  qu'aucun ne circule — le mercredi et le vendredi, souvent.
- Quatre widgets, une douzaine de commandes utilisables en scénario, et
  une **page pour l'enfant** sans réglage ni compte.
- Le lien avec un plugin d'emploi du temps, s'il y en a un : l'heure de
  fin des cours réelle prime sur les heures réglées à la main.

**Ce que l'usage a appris**

- **Le car scolaire passe avant le réseau**, même s'il faut l'attendre :
  c'est celui qui dépose devant la maison. On ne cherche ailleurs que
  lorsqu'il n'y a plus de car du tout.
- **Un trajet commencé ne se rediscute pas.** Le calcul tourne toutes les
  cinq minutes ; proposer un autre bus à quelqu'un qui marche déjà vers
  le premier est le meilleur moyen de le perdre.
- **La fiche officielle prime sur le GTFS**, qui se trompe sur les
  mercredis et les vacances.
- **Un quart d'heure de patience** avant de déclarer un car perdu : il
  peut être en retard, et l'enfant est peut-être dedans.
- **Une correspondance de moins vaut quinze minutes de marche.** Une
  ligne rare coûte plus cher qu'une ligne fréquente dans le classement.
- **Un seul nom par arrêt**, même quand deux référentiels lui en donnent
  deux : descendre à un nom et remonter à un autre n'aide personne.
- L'écran de l'enfant ne dit **qu'une chose à la fois**, et ne lui
  demande jamais de poser un calcul.

**Prudences apprises à la dure**

- Le fichier de PID n'est pas une preuve de mort, et l'on ne tue jamais
  un démon qui répond : sinon le plugin coupe son propre service.
- Les noms venus du GTFS entrent comme du texte, jamais comme du HTML.
