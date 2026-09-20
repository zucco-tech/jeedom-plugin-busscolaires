"""Horaires issus des fiches officielles, qui priment sur le GTFS.

Le GTFS de la ligne 1800 est faux : il oublie les quatre courses du
mercredi et fait circuler six retours un jour où ils ne roulent pas. Les
fiches publiées par la Métropole font foi ; ce module les sert à la place
du GTFS pour les lignes couvertes.

Un fichier par ligne dans data/fiches/<ligne>.json, produit par
tools/parse_fiche.py.
"""
from __future__ import annotations

import datetime as dt
import json
import unicodedata
from functools import lru_cache

from . import config
from .gtfs import connect, is_ready

FICHES_DIR = config.DATA_DIR / "fiches"

# Les fiches nomment les arrêts d'Aix sans le préfixe du référentiel GTFS.
ALIAS = {
    "Bourse": "Aix Bourse", "Roi René": "Aix Roi René",
    "Saint Jean": "Aix St Jean", "Briand": "Aix Briand",
    "Silvacane": "Aix Silvacane", "ADM Zola": "Aix Arc de Meyran Zola",
    "Nativité - Grassie": "Grassie", "CLG Font d'Aurumy": "Clg Font d'Aurumy",
}


def _norm(s: str) -> str:
    s = unicodedata.normalize("NFD", s or "")
    return "".join(c for c in s if unicodedata.category(c) != "Mn").lower().strip()


@lru_cache(maxsize=1)
def lignes_couvertes() -> tuple[str, ...]:
    if not FICHES_DIR.exists():
        return ()
    return tuple(sorted(p.stem for p in FICHES_DIR.glob("*.json")))


def couvre(ligne: str | None) -> bool:
    return bool(ligne) and ligne in lignes_couvertes()


@lru_cache(maxsize=8)
def _charger(ligne: str) -> dict:
    p = FICHES_DIR / f"{ligne}.json"
    if not p.exists():
        return {}
    return json.loads(p.read_text())


def _stops_index() -> dict:
    """Nom d'arrêt (normalisé) -> arrêt du référentiel réellement desservi.

    L'index GTFS peut ne pas encore exister : à la première installation,
    il est construit après coup. On répond alors « rien », sans le mettre
    en cache, pour que la réponse change dès qu'il arrive.
    """
    if not is_ready():
        return {}
    return _stops_index_cache()


@lru_cache(maxsize=1)
def _stops_index_cache() -> dict:
    """Nom d'arrêt (normalisé) -> arrêt du référentiel réellement desservi.

    Le référentiel contient plusieurs arrêts homonymes, dont des points
    logiques qu'aucune course ne dessert. On retient celui qui compte le
    plus de passages : c'est le seul sur lequel un horaire tombera.
    """
    con = connect()
    out: dict[str, dict] = {}
    rows = con.execute(
        """SELECT s.stop_id, s.name, s.city, s.lat, s.lon,
                  (SELECT count(*) FROM stop_times st
                   WHERE st.stop_id = s.stop_id) AS n
           FROM stops s""").fetchall()
    con.close()
    for r in rows:
        cle = _norm(r["name"])
        garde = out.get(cle)
        if garde is None or r["n"] > garde["n"]:
            out[cle] = dict(r)
    return out


def situe(nom: str) -> dict | None:
    """L'alias est essayé d'abord : la fiche écrit « Bourse », le
    référentiel « Aix Bourse », et un homonyme non desservi existe."""
    idx = _stops_index()
    for cle in (_norm(ALIAS.get(nom, "")), _norm(nom)):
        if cle and cle in idx:
            return idx[cle]
    return None


def jours_de_classe(ligne: str) -> tuple[str, ...]:
    """Jours où la ligne circule, d'après le calendrier du GTFS.

    La fiche donne les horaires et les jours de la semaine ; le GTFS reste
    la meilleure source pour savoir quelles dates sont des jours de classe.
    Sans index, on ne sait pas : on répond vide sans le retenir.
    """
    if not is_ready():
        return ()
    return _jours_de_classe(ligne)


@lru_cache(maxsize=8)
def _jours_de_classe(ligne: str) -> tuple[str, ...]:
    con = connect()
    rows = con.execute(
        """SELECT DISTINCT sd.date FROM service_dates sd
           JOIN trips t ON t.service_id = sd.service_id
           JOIN routes r ON r.route_id = t.route_id
           WHERE r.short_name = ? ORDER BY sd.date""", (ligne,)).fetchall()
    con.close()
    return tuple(r["date"] for r in rows)


def circule(course: dict, ligne: str, date: str) -> bool:
    if date not in jours_de_classe(ligne):
        return False
    return dt.date.fromisoformat(date).weekday() in course.get("semaine", [])


def courses(ligne: str, date: str, sens: str | None = None) -> list[dict]:
    """Courses de la fiche circulant à cette date, arrêts situés."""
    data = _charger(ligne)
    out = []
    for s, liste in data.items():
        if sens and s != sens:
            continue
        for c in liste:
            if not circule(c, ligne, date):
                continue
            arrets = []
            for k, nom in enumerate(c["arrets"]):
                p = situe(nom)
                arrets.append({
                    "arret": nom, "heure": c["h"][k],
                    "arret_id": (p or {}).get("stop_id"),
                    "commune": (p or {}).get("city", ""),
                    "lat": (p or {}).get("lat"), "lon": (p or {}).get("lon"),
                })
            out.append({"code": c["code"], "sens": s, "jours": c["jours"].strip(),
                        "ligne": ligne, "arrets": arrets})
    out.sort(key=lambda c: c["arrets"][0]["heure"])
    return out


def departs(ligne: str, date: str, arret_id: str | None = None,
            nom: str | None = None) -> list[dict]:
    """Passages à un arrêt. Sans filtre, tous les passages de la journée."""
    res = []
    tout = arret_id is None and nom is None
    for c in courses(ligne, date):
        for k, a in enumerate(c["arrets"]):
            if tout or (arret_id and a["arret_id"] == arret_id) \
                    or (nom and a["arret"] == nom):
                res.append({
                    "heure": a["heure"], "ligne": ligne, "code": c["code"],
                    "sens": c["sens"], "jours": c["jours"],
                    "arret": a["arret"], "commune": a["commune"],
                    "arret_id": a["arret_id"], "index": k,
                    "destination": c["arrets"][-1]["arret"],
                    "source": "fiche officielle",
                })
                if not tout:
                    break
    res.sort(key=lambda d: d["heure"])
    return res


def etat() -> dict:
    lignes = lignes_couvertes()
    detail = {}
    for l in lignes:
        d = _charger(l)
        detail[l] = {"courses": sum(len(v) for v in d.values()),
                     "jours": len(jours_de_classe(l))}
    return {"lignes": list(lignes), "detail": detail,
            "note": "Les fiches officielles priment sur le GTFS pour ces lignes."}


def oublier() -> None:
    """Vide les caches. À appeler après une reconstruction de l'index :
    sans cela, le service continuerait de servir l'ancien référentiel
    jusqu'à son prochain démarrage."""
    lignes_couvertes.cache_clear()
    _charger.cache_clear()
    _stops_index_cache.cache_clear()
    _jours_de_classe.cache_clear()
