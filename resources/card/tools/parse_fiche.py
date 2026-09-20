#!/usr/bin/env python3
"""Lit une fiche horaires scolaire de la Métropole (PDF) et en fait du JSON.

Les fiches publiées par le réseau font foi : le GTFS des lignes scolaires
oublie le service du mercredi midi et fait circuler les retours de 16 h,
17 h et 18 h ce jour-là. Ce script existe pour ne plus dépendre du GTFS
sur les lignes scolaires.

    python3 tools/parse_fiche.py fiche-de-la-ligne.pdf data/fiches/<ligne>.json

Le format des fiches est régulier : deux tableaux côte à côte (aller à
gauche, retour à droite), une colonne par circuit, et sous chaque circuit
le motif de jours (L-M-M-J-V, L-M-J-V, MERCREDI).
"""
from __future__ import annotations

import json
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

EST_HEURE = re.compile(r"^\d{1,2}:\d{2}$")
EST_CODE = re.compile(r"^0[0-9][AR]$")
EST_JOURS = re.compile(r"^(MERCREDI|L-[MJV\-]+)$")
JOURS = {"L-M-M-J-V": [0, 1, 2, 3, 4], "L-M-J-V": [0, 1, 3, 4], "MERCREDI": [2]}


def mots_du_pdf(pdf: Path) -> list[dict]:
    """Position de chaque mot, via pdftotext -bbox-layout."""
    with tempfile.NamedTemporaryFile(suffix=".xml") as tmp:
        subprocess.run(["pdftotext", "-bbox-layout", str(pdf), tmp.name],
                       check=True, capture_output=True)
        racine = ET.parse(tmp.name).getroot()
    out = []
    for w in racine.iter():
        if w.tag.endswith("word"):
            out.append({
                "y": round(float(w.get("yMin")), 1),
                "x0": float(w.get("xMin")), "x1": float(w.get("xMax")),
                "xc": (float(w.get("xMin")) + float(w.get("xMax"))) / 2,
                "t": (w.text or "").strip(),
            })
    return out


def par_lignes(mots: list[dict], tol: float = 3.0) -> list[list[dict]]:
    out: list[list[dict]] = []
    for m in sorted(mots, key=lambda z: (z["y"], z["x0"])):
        if out and abs(out[-1][0]["y"] - m["y"]) <= tol:
            out[-1].append(m)
        else:
            out.append([m])
    return out


def colonnes(mots: list[dict]) -> list[dict]:
    codes = [m for m in mots if EST_CODE.match(m["t"])]
    if not codes:
        raise SystemExit("aucun code de circuit (01A, 02R…) trouvé dans le PDF")
    y_codes = min(m["y"] for m in codes)
    jours = [m for m in mots
             if 0 < m["y"] - y_codes < 25 and EST_JOURS.match(m["t"])]
    out = []
    for c in sorted(codes, key=lambda z: z["xc"]):
        proche = min(jours, key=lambda j: abs(j["xc"] - c["xc"])) if jours else None
        out.append({"code": c["t"], "xc": c["xc"],
                    "jours": proche["t"] if proche else "L-M-M-J-V"})
    return out


def bandes(cols: list[dict]) -> list[dict]:
    """Découpe la page en deux tableaux, à partir de l'écart entre colonnes."""
    xs = [c["xc"] for c in cols]
    coupure = None
    for a, b in zip(xs, xs[1:]):
        if b - a > 100:
            coupure = (a + b) / 2
            break
    if coupure is None:
        coupure = max(xs) + 1000
    gauche = [c for c in cols if c["xc"] < coupure]
    droite = [c for c in cols if c["xc"] >= coupure]
    res = []
    if gauche:
        res.append({"sens": "A", "cols": gauche,
                    "nom": (min(c["xc"] for c in gauche) - 90,
                            min(c["xc"] for c in gauche) - 8)})
    if droite:
        res.append({"sens": "R", "cols": droite,
                    "nom": (min(c["xc"] for c in droite) - 90,
                            min(c["xc"] for c in droite) - 8)})
    return res


def lire(pdf: Path) -> dict:
    mots = mots_du_pdf(pdf)
    cols = colonnes(mots)
    # La première ligne de données est à la même hauteur que les libellés de
    # jours : on ne peut pas l'écarter par sa position, seulement par son
    # contenu. Une ligne compte si elle porte un nom d'arrêt ET des heures.
    y_min = min(m["y"] for m in mots if EST_CODE.match(m["t"])) + 2
    res: dict[str, list] = {}

    for bande in bandes(cols):
        c0 = min(c["xc"] for c in bande["cols"])
        c1 = max(c["xc"] for c in bande["cols"])
        rangs = []
        for ln in par_lignes([m for m in mots if m["y"] > y_min]):
            noms = [m for m in ln
                    if bande["nom"][0] <= m["x0"] <= bande["nom"][1]
                    and not EST_JOURS.match(m["t"])
                    and not EST_CODE.match(m["t"])]
            heures = [m for m in ln if EST_HEURE.match(m["t"])
                      and c0 - 26 <= m["xc"] <= c1 + 26]
            if not noms or not heures:
                continue
            arret = re.sub(r"\s+", " ", " ".join(
                m["t"] for m in sorted(noms, key=lambda z: z["x0"]))).strip()
            cellules = {}
            for h in heures:
                col = min(bande["cols"], key=lambda c: abs(c["xc"] - h["xc"]))
                if abs(col["xc"] - h["xc"]) < 26:
                    cellules[bande["cols"].index(col)] = h["t"]
            if cellules:
                rangs.append({"arret": arret, "cellules": cellules,
                              "y": ln[0]["y"]})
        rangs.sort(key=lambda r: r["y"])

        courses = []
        for i, col in enumerate(bande["cols"]):
            seq = [(r["arret"], r["cellules"][i]) for r in rangs
                   if i in r["cellules"]]
            if len(seq) < 3:
                continue
            courses.append({"code": col["code"], "jours": col["jours"],
                            "semaine": JOURS.get(col["jours"], [0, 1, 2, 3, 4]),
                            "arrets": [s[0] for s in seq],
                            "h": [s[1] for s in seq]})
        res[bande["sens"]] = courses
    return res


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    pdf, sortie = Path(sys.argv[1]), Path(sys.argv[2])
    data = lire(pdf)
    sortie.parent.mkdir(parents=True, exist_ok=True)
    sortie.write_text(json.dumps(data, ensure_ascii=False, indent=1))
    for sens, courses in data.items():
        print(f"sens {sens} : {len(courses)} circuits")
        for c in courses:
            print(f"   {c['code']} ({c['jours']:<10}) {len(c['arrets']):>2} arrêts "
                  f"{c['h'][0]} → {c['h'][-1]}")
    print(f"\nécrit dans {sortie}")


if __name__ == "__main__":
    main()
