<?php
/* Relais entre la page de l'enfant et le démon.
 *
 * Le démon n'écoute que sur 127.0.0.1. Ce fichier est le seul chemin
 * qui y mène depuis l'extérieur, et il ne laisse passer qu'une liste
 * fermée de lectures : aucun réglage, aucune reconstruction d'index,
 * aucune écriture d'horaire. C'est ce qui garantit que l'enfant ne peut
 * rien modifier même en connaissant le lien.
 */

require_once __DIR__ . '/../../../core/php/core.inc.php';

const COOKIE_ENFANT = 'busscolaires_enfant';

/* Routes autorisées. Tout le reste est refusé, sans exception ni
   caractère joker : une route non listée n'existe pas pour cette page. */
const LECTURES = array(
    'etat', 'profil', 'journee', 'resume', 'sortie', 'solutions',
    'arrets-ligne', 'alertes', 'departs', 'temps-reel/etat',
);
/* Les deux seules écritures : s'abonner aux alertes et s'en désabonner.
   Elles ne touchent qu'au téléphone qui les demande. */
const ECRITURES = array('abonner', 'desabonner');

function refuser($code, $message)
{
    header('HTTP/1.1 ' . $code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('detail' => $message));
    exit;
}

$cle = $_COOKIE[COOKIE_ENFANT] ?? ($_GET['k'] ?? '');
$eqLogic = busscolaires::parCle($cle);
if (!is_object($eqLogic)) {
    /* Même porte que la page : le compte Jeedom désigné vaut le lien.
       Les routes autorisées restent les mêmes — que l'on entre par l'un
       ou par l'autre, on ne peut toujours rien modifier. */
    /* Même remarque que dans index.php : c'est le cœur qui sait ouvrir
       la session, quel que soit le nom de son cookie. */
    include_file('core', 'authentification', 'php');
    $eqLogic = busscolaires::parCompte(isConnect() ? $_SESSION['user'] : null);
    if (!is_object($eqLogic) && isConnect('admin')) {
        $eqLogic = busscolaires::pourApercu($_GET['eq'] ?? null);
    }
}
if (!is_object($eqLogic)) {
    refuser('403 Forbidden', 'Lien invalide ou compte non reconnu.');
}

$route = isset($_GET['r']) ? trim((string) $_GET['r'], '/') : '';

/* La clé publique de notification est du texte, pas du JSON. */
if ($route === 'cle') {
    try {
        $ch = curl_init('http://127.0.0.1:' . busscolaires::port() . '/cle');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
        ));
        $texte = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($texte === false || $code >= 400) {
            refuser('502 Bad Gateway', 'Service indisponible.');
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $texte;
        exit;
    } catch (Exception $e) {
        refuser('502 Bad Gateway', 'Service indisponible.');
    }
}

$ecriture = in_array($route, ECRITURES, true);
if (!$ecriture && !in_array($route, LECTURES, true)) {
    refuser('404 Not Found', 'Route inconnue.');
}

/* Le relais force déjà la méthode — une lecture part toujours en GET,
   quoi qu'on lui envoie. On refuse tout de même explicitement : mieux
   vaut un 405 franc qu'un 200 qui laisse croire à une écriture. */
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($ecriture ? $methode !== 'POST' : !in_array($methode, array('GET', 'HEAD'), true)) {
    header('Allow: ' . ($ecriture ? 'POST' : 'GET, HEAD'));
    refuser('405 Method Not Allowed', 'Méthode refusée sur cette route.');
}

/* Les paramètres de requête sont recopiés, sauf ceux qui nous
   appartiennent — et surtout, le trajet de CET équipement est imposé
   par-dessus. Une page ne peut donc pas se faire calculer le trajet
   d'un autre enfant en trafiquant son URL. */
$parametres = $_GET;
unset($parametres['r'], $parametres['k']);
foreach (busscolaires::REGLAGES as $cleReglage => $rien) {
    unset($parametres[$cleReglage]);
}
$parametres = array_merge($parametres, $eqLogic->trajet());

$chemin = ($ecriture ? '' : 'api/') . $route;
if (!$ecriture && !empty($parametres)) {
    $chemin .= '?' . http_build_query($parametres);
}

$corps = null;
if ($ecriture) {
    $brut = file_get_contents('php://input');
    if (strlen($brut) > 8000) {
        refuser('413 Payload Too Large', 'Requête trop grande.');
    }
    $corps = json_decode($brut, true);
    if (!is_array($corps)) {
        refuser('400 Bad Request', 'Corps illisible.');
    }
}

try {
    $reponse = busscolaires::appeler($chemin, $ecriture ? 'POST' : 'GET', $corps, 25);
} catch (Exception $e) {
    /* On ne renvoie pas le détail interne à une page publique. */
    log::add('busscolaires', 'debug', 'relais enfant : ' . $e->getMessage());
    refuser('502 Bad Gateway', 'Service indisponible pour le moment.');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode($reponse);
