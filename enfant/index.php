<?php
/* La page de l'enfant.
 *
 * Publique par construction — c'est le but : pas de compte, pas de mot
 * de passe, un lien qu'on ouvre. Ce que l'adulte crée dans la
 * configuration du plugin, et qu'il peut révoquer.
 *
 * Le lien porte la clé une seule fois. On la range aussitôt dans un
 * cookie HttpOnly et on renvoie vers l'adresse propre : la clé ne reste
 * ni dans l'historique du téléphone, ni dans les journaux du serveur
 * au-delà de cette première visite. C'est aussi ce qui permet à
 * l'application installée de s'ouvrir sans le lien.
 *
 * Aucun réglage n'est joignable d'ici : le relais api.php ne connaît
 * qu'une liste de lectures.
 */

require_once __DIR__ . '/../../../core/php/core.inc.php';

const COOKIE_ENFANT = 'busscolaires_enfant';

$cle = isset($_GET['k']) ? (string) $_GET['k'] : '';
$depuisLien = ($cle !== '');
if (!$depuisLien && isset($_COOKIE[COOKIE_ENFANT])) {
    $cle = (string) $_COOKIE[COOKIE_ENFANT];
}

$eqLogic = busscolaires::parCle($cle);
/* Pas de lien ? Peut-être est-elle simplement connectée à Jeedom avec son
   propre compte. On ne reconnaît que celui que l'adulte a désigné sur
   l'équipement — et les droits de Jeedom s'appliquent quand même. */
if (!is_object($eqLogic)) {
    /* La session de Jeedom ne s'ouvre pas avec un session_start() nu : en
       HTTPS elle porte un autre nom (__Host-PHPSESSID). C'est ce fichier
       du cœur qui sait la retrouver — sans lui, elle serait connectée et
       la page lui répondrait qu'elle n'existe pas. */
    include_file('core', 'authentification', 'php');
    $eqLogic = busscolaires::parCompte(isConnect() ? $_SESSION['user'] : null);
    /* Un administrateur peut regarder la page de son enfant sans changer
       de compte : il voit déjà tout dans Jeedom, lui refuser l'aperçu ne
       protégerait rien. */
    if (!is_object($eqLogic) && isConnect('admin')) {
        $eqLogic = busscolaires::pourApercu($_GET['eq'] ?? null);
    }
    $depuisLien = false;
}
if (!is_object($eqLogic)) {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8">'
       . '<title>Page introuvable</title>'
       . '<body style="font:16px system-ui;margin:3rem auto;max-width:30rem;'
       . 'color:#44525f"><h1 style="font-size:1.3rem;color:#151c24">'
       . 'Cette page n\'existe pas</h1>'
       . '<p>Le lien est peut-être périmé, ou ce compte n\'a pas été '
       . 'désigné. Demandez-en un nouveau.</p>';
    exit;
}

/* La clé vient d'arriver par le lien : on la range et on nettoie l'URL. */
if ($depuisLien) {
    $securise = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(COOKIE_ENFANT, $cle, array(
        'expires' => time() + 400 * 24 * 3600,
        'path' => dirname($_SERVER['SCRIPT_NAME']) . '/',
        'secure' => $securise,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    header('Location: ' . basename($_SERVER['SCRIPT_NAME']));
    exit;
}

$racine = realpath(__DIR__ . '/..');
$ecran = $racine . '/resources/web/enfant.tpl';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#12161b">
<meta name="description" content="Le car scolaire entre la maison et le lycée : horaires officiels, repli s'il est manqué, et alerte avant de partir.">
<link rel="manifest" href="manifest.php">
<link rel="apple-touch-icon" href="icones/apple-touch-icon.png">
<link rel="icon" href="icones/icone-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Son car">
<style>html{background:#12161b}</style>
</head>
<body>
<?php
/* Le nom de l'équipement est le seul texte injecté : le reste de la page
   ne dépend d'aucun réglage, et ne peut donc pas être détourné. */
echo str_replace('#nom#',
    htmlspecialchars($eqLogic->getName(), ENT_QUOTES, 'UTF-8'),
    file_get_contents($ecran));
?>
</body>
</html>
