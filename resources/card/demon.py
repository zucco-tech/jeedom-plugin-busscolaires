#!/usr/bin/env python3
"""Démon du plugin Jeedom « Bus scolaires ».

Il porte tout le calcul : lecture de la fiche horaire officielle, index
GTFS du réseau, alertes temps réel, choix du car et solutions de repli.
Il n'écoute que sur la boucle locale — c'est le plugin qui l'interroge,
et le serveur web de Jeedom qui expose les pages.
"""
from __future__ import annotations

import argparse
import logging
import os
import signal
import sys
from pathlib import Path

ICI = Path(__file__).resolve().parent

# Jeedom nomme ses niveaux autrement qu'uvicorn : on traduit, et tout
# nom inconnu retombe sur « info » plutôt que de faire échouer uvicorn.
NIVEAUX = {
    "debug": logging.DEBUG, "info": logging.INFO, "notice": logging.INFO,
    "warning": logging.WARNING, "error": logging.ERROR,
    "critical": logging.CRITICAL, "alert": logging.CRITICAL,
    "emergency": logging.CRITICAL, "none": logging.CRITICAL,
}
UVICORN = {
    "debug": "debug", "info": "info", "notice": "info",
    "warning": "warning", "error": "error", "critical": "critical",
    "alert": "critical", "emergency": "critical", "none": "critical",
}


def arguments() -> argparse.Namespace:
    a = argparse.ArgumentParser(description="Démon Bus scolaires")
    a.add_argument("--port", type=int, default=55810,
                   help="port d'écoute sur 127.0.0.1")
    a.add_argument("--pid", default="", help="fichier de PID à écrire")
    a.add_argument("--data", default="", help="dossier de données")
    a.add_argument("--loglevel", default="info", help="niveau de journal")
    return a.parse_args()


def main() -> int:
    opt = arguments()

    logging.basicConfig(
        level=NIVEAUX.get(opt.loglevel.lower(), logging.INFO),
        format="[%(asctime)s][%(levelname)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S", stream=sys.stdout)
    log = logging.getLogger("car")

    # Le dossier de données doit être connu avant d'importer la
    # configuration, qui le fige au chargement.
    data = Path(opt.data) if opt.data else ICI / "data"
    data.mkdir(parents=True, exist_ok=True)
    os.environ["DATA_DIR"] = str(data)

    # Les fiches horaires officielles voyagent avec le plugin ; on les
    # dépose dans les données au premier démarrage, sans écraser une
    # fiche que l'utilisateur aurait importée depuis.
    livrees = ICI / "fiches"
    if livrees.is_dir():
        cible = data / "fiches"
        cible.mkdir(parents=True, exist_ok=True)
        for f in livrees.glob("*.json"):
            if not (cible / f.name).exists():
                (cible / f.name).write_bytes(f.read_bytes())
                log.info("fiche horaire installée : %s", f.name)

    sys.path.insert(0, str(ICI))
    import uvicorn
    from server.app import app  # après DATA_DIR, jamais avant

    if opt.pid:
        pid = Path(opt.pid)
        pid.parent.mkdir(parents=True, exist_ok=True)
        # Écriture atomique : le superviseur relit ce fichier en boucle et
        # ne doit jamais le surprendre à moitié écrit.
        provisoire = pid.with_suffix(pid.suffix + ".tmp")
        provisoire.write_text(str(os.getpid()))
        os.replace(provisoire, pid)

        def partir(signum, _frame):
            log.info("signal %s reçu, arrêt", signum)
            pid.unlink(missing_ok=True)
            sys.exit(0)

        signal.signal(signal.SIGTERM, partir)
        signal.signal(signal.SIGINT, partir)

    log.info("démon prêt sur 127.0.0.1:%s, données dans %s", opt.port, data)
    niveau = opt.loglevel.lower()
    uvicorn.run(app, host="127.0.0.1", port=opt.port,
                log_level=UVICORN.get(niveau, "info"),
                access_log=niveau == "debug")
    return 0


if __name__ == "__main__":
    sys.exit(main())
