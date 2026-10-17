# Changelog bêta

Ce qui est en essai sur la branche `beta` et n'est pas encore passé en
`stable`.

## 2.42

- « Démon injoignable » quand le flux d'alertes de la Métropole ne répond
  plus. Le plugin attendait ce flux jusqu'à vingt secondes, à l'intérieur
  de la requête qui lit l'heure du car — et Jeedom abandonne justement au
  bout de vingt secondes. Une panne chez eux devenait donc une panne chez
  nous. L'attente tombe à quatre secondes, les dernières alertes connues
  sont resservies telles quelles, et le flux est laissé au repos cinq
  minutes avant d'être redemandé. Le temps réel SIRI reçoit la même
  prudence.

## 2.41

- La documentation est complète : réseau couvert et origine des données,
  prérequis, configuration des nouveaux réglages, ajout d'un enfant, liste
  exacte des commandes, jours de classe et vacances, ce que fait le plugin
  quand le car ne vient pas, dépannage et limites connues.

## 2.40

- Plus aucune trace des communes d'origine dans le code.

## 2.39

- Le formulaire ne propose plus en exemple les coordonnées d'une école
  réelle.

## 2.38

- Première version publique : ligne, arrêts, adresse et communes à
  indexer deviennent de vrais réglages, sans valeur préremplie ; les noms
  venus du réseau sont posés comme du texte ; licence et langue déclarées.

**À vérifier avant la bascule en stable** : sur un vrai jour de classe,
l'heure de départ, le car du matin et l'arrivée, puis le retour du soir ;
la page de l'enfant ; et, si possible, un car manqué.
