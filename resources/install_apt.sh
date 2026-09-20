#!/bin/bash
# Dépendances du plugin Bus scolaires.
#
# Deux choses seulement : un environnement Python isolé, à l'endroit où
# le coeur de Jeedom va le chercher (system::getCmdPython3), et
# poppler-utils pour pouvoir importer une nouvelle fiche horaire PDF.
# La fiche de l'année en cours voyage déjà avec le plugin : sans
# poppler, tout fonctionne, on ne peut simplement pas en importer une.

PROGRESS_FILE=/tmp/jeedom/busscolaires/dependency
if [ ! -z "$1" ]; then
	PROGRESS_FILE=$1
fi

BASE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
VENV_DIR=${BASE_DIR}/python_venv
REQUIREMENTS=${BASE_DIR}/requirements.txt

function log() {
	echo "$(date +'[%F %T]') $1"
}

function avancement() {
	echo "$1" > "${PROGRESS_FILE}"
}

mkdir -p "$(dirname "${PROGRESS_FILE}")"
touch "${PROGRESS_FILE}"
avancement 0

log "*** Installation des dépendances Bus scolaires ***"

avancement 5
log "Mise à jour de la liste des paquets"
sudo apt-get update

avancement 15
log "Paquets système"
sudo apt-get install -y python3 python3-venv python3-dev poppler-utils

avancement 35
if [ ! -x "${VENV_DIR}/bin/python3" ]; then
	log "Création de l'environnement Python dans ${VENV_DIR}"
	rm -rf "${VENV_DIR}"
	python3 -m venv "${VENV_DIR}"
else
	log "Environnement Python déjà présent"
fi

avancement 50
log "Mise à jour de pip"
"${VENV_DIR}/bin/python3" -m pip install --upgrade pip wheel

avancement 65
log "Modules Python"
"${VENV_DIR}/bin/python3" -m pip install -r "${REQUIREMENTS}"

avancement 90
log "Vérification"
"${VENV_DIR}/bin/python3" - <<'PY'
import importlib, sys
manquants = []
for m in ("fastapi", "uvicorn", "httpx", "google.transit.gtfs_realtime_pb2",
          "pywebpush", "cryptography"):
    try:
        importlib.import_module(m)
    except Exception as e:
        manquants.append(f"{m} ({e})")
if manquants:
    print("MODULES MANQUANTS : " + ", ".join(manquants))
    sys.exit(1)
print("tous les modules Python sont là")
PY
if [ $? -ne 0 ]; then
	log "*** Échec : il manque des modules Python ***"
	avancement 100
	rm -f "${PROGRESS_FILE}"
	exit 1
fi

if command -v pdftotext >/dev/null 2>&1; then
	log "pdftotext présent : l'import d'une fiche PDF est possible"
else
	log "pdftotext absent : la fiche livrée avec le plugin sera utilisée"
fi

avancement 100
log "*** Installation terminée ***"
rm -f "${PROGRESS_FILE}"
