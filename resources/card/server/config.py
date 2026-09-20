"""Configuration de l'application. Tout est surchargeable par variables d'environnement."""
import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
DATA_DIR = Path(os.environ.get("DATA_DIR", BASE_DIR / "data"))
DB_PATH = DATA_DIR / "gtfs.sqlite"

# --- Source des horaires : GTFS officiel Pays d'Aix Mobilite (reseau PAM) ---
# Publie par la Metropole Aix-Marseille-Provence sur transport.data.gouv.fr
GTFS_URL = os.environ.get(
    "GTFS_URL",
    "https://app.mecatran.com/utw/ws/gtfsfeed/static/mamp-pam"
    "?apiKey=596e694f3330142c525b7d6b123a5b055f744058",
)
# Aix en Bus : la ligne A dessert le centre d'Aix depuis P+R Krypton, ou
# arrive le 170. C'est le repli quand le car scolaire est manque.
GTFS_URL_AIX = os.environ.get(
    "GTFS_URL_AIX",
    "https://app.mecatran.com/utw/ws/gtfsfeed/static/mamp-aix"
    "?apiKey=020c7c326b6c5e750b168064336e344c61213521",
)
FLUX = [
    {"nom": "Pays d'Aix Mobilite", "url": GTFS_URL},
    {"nom": "Aix en Bus", "url": GTFS_URL_AIX},
]
# Le GTFS complet pese ~10 Mo compresse / 1,7 M lignes d'horaires. Indexer
# le reseau entier marche, mais coute du disque et du temps : le plugin
# passe ici les communes traversees, reglees dans sa configuration. Liste
# vide = tout le reseau.
COMMUNES = [
    c.strip()
    for c in os.environ.get("COMMUNES", "").split(",")
    if c.strip()
]

# --- Temps reel : flux GTFS-RT Service Alerts de la Metropole ---
ALERTS_URL = os.environ.get(
    "ALERTS_URL", "https://api-mobilite.rbgl.fr/api/v1/mamp/getServiceAlerts"
)
ALERTS_TTL = int(os.environ.get("ALERTS_TTL", "60"))  # secondes

# --- Temps reel : SIRI StopMonitoring (passages a l'arret) ---
# Les centres operateurs ne publient pas encore leurs seuils : l'adaptateur
# existe et s'activera automatiquement le jour ou le flux s'ouvrira.
SIRI_BASE = os.environ.get("SIRI_BASE", "https://siri.lametropolemobilite.fr")
SIRI_ENDPOINTS = [
    e.strip()
    for e in os.environ.get(
        "SIRI_ENDPOINTS", "PAM_SUMA,PAM_TRANSDEV,PAM_SAP,PAM_REG_GAR"
    ).split(",")
    if e.strip()
]
SIRI_REQUESTOR_REF = os.environ.get("SIRI_REQUESTOR_REF", "bus-ecole")
SIRI_ENABLED = os.environ.get("SIRI_ENABLED", "1") not in ("0", "false", "no")


# --- Trajet suivi par defaut (modifiable dans l'interface) ---
# Le trajet suivi, et les reglages qui vont avec.
#
# Rien n'est prerempli : la ligne, les deux arrets et l'adresse de l'ecole
# se reglent sur l'equipement, qui les joint a chaque appel. Ces valeurs ne
# servent que de secours pour un appel direct a l'API, sans equipement.
#
# Les arrets sont designes par leur NOM, comme sur la fiche horaires : c'est
# ce que lisent les parents, et cela survit a un changement d'identifiant
# dans le referentiel. Le serveur resout les noms en identifiants quand il
# en a besoin.
#
# Ces reglages vivent ici, pas dans le navigateur : sinon chaque telephone
# aurait les siens, et le serveur d'alertes ne saurait pas lesquels croire.
DEFAULT_PROFILE = {
    "ligne": os.environ.get("LIGNE", ""),
    "arret_maison": os.environ.get("ARRET_MAISON", ""),
    "arret_ecole": os.environ.get("ARRET_ECOLE", ""),
    "marche_m_min": int(os.environ.get("MARCHE_M_MIN", "80")),
    "correspondance_min": int(os.environ.get("CORRESPONDANCE_MIN", "4")),
    "sortie_defaut": os.environ.get("SORTIE_DEFAUT", "16:05"),
    # Heure de fin des cours par jour de semaine. Le mercredi diffère
    # presque toujours ; une valeur vide retombe sur sortie_defaut.
    "sortie_lundi": os.environ.get("SORTIE_LUNDI", "16:05"),
    "sortie_mardi": os.environ.get("SORTIE_MARDI", "16:05"),
    "sortie_mercredi": os.environ.get("SORTIE_MERCREDI", "12:00"),
    "sortie_jeudi": os.environ.get("SORTIE_JEUDI", "16:05"),
    "sortie_vendredi": os.environ.get("SORTIE_VENDREDI", "16:05"),
    "preavis_min": int(os.environ.get("PREAVIS_MIN", "10")),
    # Adresse d'arrivee, pour la marche finale. Vide = l'arret suffit.
    "adresse": os.environ.get("ADRESSE", ""),
    "adresse_lon": float(os.environ.get("ADRESSE_LON", "0")),
    "adresse_lat": float(os.environ.get("ADRESSE_LAT", "0")),
    # La maison, d'ou l'on marche jusqu'a l'arret le matin. Sans elle,
    # l'heure de depart a pied vaudrait celle du car : partir quand il
    # passe, ce qui n'a pas de sens. Zero = inconnue.
    "adresse_maison": os.environ.get("ADRESSE_MAISON", ""),
    # Combien de marche on accepte pour rejoindre un arret. Un quart
    # d'heure de marche vaut mieux qu'une correspondance de plus.
    "marche_max_min": int(os.environ.get("MARCHE_MAX_MIN", "15")),
    # De combien de minutes on part avant le car. Arriver a l'arret pile
    # a l'heure est ce qui stresse le plus : on prend de l'avance, et le
    # temps de marche ne sert que de garde-fou quand l'arret est loin.
    "marge_depart_min": int(os.environ.get("MARGE_DEPART_MIN", "10")),
    "maison_lon": float(os.environ.get("MAISON_LON", "0")),
    "maison_lat": float(os.environ.get("MAISON_LAT", "0")),
}

DATA_DIR.mkdir(parents=True, exist_ok=True)
