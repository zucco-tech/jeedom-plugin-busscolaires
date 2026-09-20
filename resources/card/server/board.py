"""Calcul des prochains passages a partir de l'index GTFS."""
from __future__ import annotations

import datetime as dt
from typing import Sequence

from . import fiche
from .gtfs import connect, norm, sec_to_hms

TZ = dt.timezone(dt.timedelta(hours=2))  # Europe/Paris, gere par le systeme


def now_local() -> dt.datetime:
    return dt.datetime.now()


def _service_days(when: dt.datetime) -> list[tuple[str, int]]:
    """Jours de service a interroger, avec le decalage en secondes.

    Une course partant a 25:10 le lundi appartient au service du lundi : il
    faut donc aussi regarder la veille quand on interroge apres minuit.
    """
    today = when.date()
    sec = when.hour * 3600 + when.minute * 60 + when.second
    return [(today.isoformat(), sec),
            ((today - dt.timedelta(days=1)).isoformat(), sec + 86400)]


def departures(
    stop_ids: Sequence[str],
    when: dt.datetime | None = None,
    horizon_min: int = 240,
    limit: int = 12,
    route_short: str | None = None,
    parcours: str | None = None,
) -> list[dict]:
    """Prochains departs a un ou plusieurs arrets, tries par heure."""
    if not stop_ids:
        return []
    when = when or now_local()
    con = connect()
    out: list[dict] = []
    ph = ",".join("?" * len(stop_ids))
    for day, offset in _service_days(when):
        sql = f"""
        SELECT st.dep, st.stop_id, st.trip_id, st.seq,
               t.headsign, t.parcours, t.sens, t.direction_id,
               r.short_name, r.long_name, r.color, r.text_color,
               s.name AS stop_name, s.city
        FROM stop_times st
        JOIN trips t ON t.trip_id = st.trip_id
        JOIN routes r ON r.route_id = t.route_id
        JOIN stops s ON s.stop_id = st.stop_id
        JOIN service_dates sd ON sd.service_id = t.service_id AND sd.date = ?
        WHERE st.stop_id IN ({ph})
          AND st.dep >= ? AND st.dep <= ?
        """
        args: list = [day, *stop_ids, offset, offset + horizon_min * 60]
        # Les lignes couvertes par une fiche officielle sont servies plus
        # bas : le GTFS s'y trompe (mercredi de la 1800), on l'ecarte ici.
        couvertes = fiche.lignes_couvertes()
        if couvertes:
            sql += " AND r.short_name NOT IN (%s)" % ",".join("?" * len(couvertes))
            args.extend(couvertes)
        if route_short:
            sql += " AND r.short_name = ?"
            args.append(route_short)
        if parcours:
            sql += " AND t.parcours = ?"
            args.append(parcours)
        sql += " ORDER BY st.dep LIMIT ?"
        args.append(limit * 3)
        for r in con.execute(sql, args):
            minutes = (r["dep"] - offset) // 60
            out.append({
                "heure": sec_to_hms(r["dep"]),
                "dans_minutes": int(minutes),
                "ligne": r["short_name"],
                "ligne_nom": r["long_name"],
                "couleur": "#" + (r["color"] or "3b6ea5"),
                "couleur_texte": "#" + (r["text_color"] or "ffffff"),
                "destination": r["headsign"],
                "parcours": r["parcours"],
                "sens": r["sens"],
                "arret": r["stop_name"],
                "commune": r["city"],
                "arret_id": r["stop_id"],
                "trip_id": r["trip_id"],
                "seq": r["seq"],
                "date_service": day,
                "scolaire": (r["parcours"] or "").startswith("SCO"),
                "temps_reel": False,
            })
    con.close()

    # Les fiches officielles, pour les lignes qu'elles couvrent.
    for ligne_f in fiche.lignes_couvertes():
        if route_short and route_short != ligne_f:
            continue
        for day, offset in _service_days(when):
            for d in fiche.departs(ligne_f, day):
                if d["arret_id"] not in stop_ids:
                    continue
                sec = int(d["heure"][:2]) * 3600 + int(d["heure"][3:5]) * 60
                if not (offset <= sec <= offset + horizon_min * 60):
                    continue
                if parcours and parcours != d["code"]:
                    continue
                out.append({
                    "heure": d["heure"],
                    "dans_minutes": int((sec - offset) // 60),
                    "ligne": ligne_f, "ligne_nom": f"Ligne {ligne_f}",
                    "couleur": "#be7b1c", "couleur_texte": "#ffffff",
                    "destination": d["destination"], "parcours": d["code"],
                    "sens": d["sens"], "arret": d["arret"],
                    "commune": d["commune"], "arret_id": d["arret_id"],
                    "trip_id": f"fiche:{ligne_f}:{d['code']}:{d['jours']}:{day}",
                    "seq": d["index"], "date_service": day,
                    "scolaire": True, "temps_reel": False,
                    "source": d["source"],
                })

    out.sort(key=lambda d: d["dans_minutes"])
    # Un meme passage peut ressortir via deux jours de service : on dedoublonne.
    seen, uniq = set(), []
    for d in out:
        key = (d["trip_id"], d["arret_id"], d["heure"])
        if key not in seen:
            seen.add(key)
            uniq.append(d)
    # Les lignes scolaires font parfois circuler deux bus a la meme minute
    # (renfort). On les presente comme un seul passage, en le signalant.
    groupes: dict[tuple, dict] = {}
    for d in uniq:
        key = (d["heure"], d["ligne"], d["destination"], d["parcours"], d["arret_id"])
        if key in groupes:
            groupes[key]["bus"] += 1
        else:
            d["bus"] = 1
            groupes[key] = d
    fusion = sorted(groupes.values(), key=lambda d: d["dans_minutes"])
    return fusion[:limit]


def recaler(departs: list[dict], reference: dt.datetime) -> list[dict]:
    """Recalcule le compte a rebours par rapport a une heure de reference.

    Les listes du matin et du soir sont construites depuis une fenetre fixe
    (05h00, 11h00) pour couvrir toute la journee : sans ce recalage, le
    compte a rebours affiche serait celui de la fenetre, pas celui de l'heure
    qu'il est. Une valeur negative signale un bus deja passe.
    """
    base = reference.hour * 60 + reference.minute
    for d in departs:
        h, m = d["heure"].split(":")[:2]
        minutes = int(h) * 60 + int(m)
        if minutes < base - 720:  # passage apres minuit
            minutes += 1440
        d["dans_minutes"] = minutes - base
    return departs


def trip_detail(trip_id: str) -> dict | None:
    """Itineraire complet d'une course, arret par arret."""
    con = connect()
    t = con.execute(
        """SELECT t.*, r.short_name, r.long_name, r.color, r.text_color
           FROM trips t JOIN routes r ON r.route_id = t.route_id
           WHERE t.trip_id = ?""", (trip_id,)).fetchone()
    if not t:
        con.close()
        return None
    rows = con.execute(
        """SELECT st.seq, st.arr, st.dep, s.name, s.city, s.lat, s.lon, st.stop_id
           FROM stop_times st JOIN stops s ON s.stop_id = st.stop_id
           WHERE st.trip_id = ? ORDER BY st.seq""", (trip_id,)).fetchall()
    con.close()
    return {
        "trip_id": trip_id,
        "ligne": t["short_name"],
        "ligne_nom": t["long_name"],
        "couleur": "#" + (t["color"] or "3b6ea5"),
        "destination": t["headsign"],
        "parcours": t["parcours"],
        "sens": t["sens"],
        "arrets": [{
            "seq": r["seq"],
            "arret": r["name"],
            "commune": r["city"],
            "arret_id": r["stop_id"],
            "heure": sec_to_hms(r["dep"] if r["dep"] is not None else r["arr"]),
            "lat": r["lat"], "lon": r["lon"],
        } for r in rows],
    }


def search_stops(q: str, limit: int = 20) -> list[dict]:
    """Recherche d'arret par nom ou par commune, sans tenir compte des accents.

    On cherche aussi dans la commune : taper le nom d'un village doit lister
    ses arrets, pas seulement un arret qui porterait ce nom. Les arrets dont
    le nom correspond passent devant.
    """
    con = connect()
    n = f"%{norm(q)}%"
    rows = con.execute(
        """SELECT DISTINCT s.stop_id, s.name, s.city, s.lat, s.lon,
                  CASE WHEN s.norm LIKE ? THEN 0 ELSE 1 END AS rang
           FROM stops s JOIN stop_times st ON st.stop_id = s.stop_id
           WHERE s.norm LIKE ?
              OR replace(replace(replace(replace(replace(lower(s.city),
                    'é','e'),'è','e'),'â','a'),'ô','o'),'û','u') LIKE ?
           ORDER BY rang, s.city, s.name LIMIT ?""",
        (n, n, n, limit)).fetchall()
    con.close()
    return [{"arret_id": r["stop_id"], "arret": r["name"], "commune": r["city"],
             "lat": r["lat"], "lon": r["lon"]} for r in rows]


def stops_of_parcours(parcours: str) -> list[dict]:
    """Liste ordonnee des arrets desservis par un parcours (ex SCO1800X02A)."""
    con = connect()
    trip = con.execute(
        """SELECT t.trip_id FROM trips t
           JOIN (SELECT trip_id, count(*) c FROM stop_times GROUP BY trip_id) k
             ON k.trip_id = t.trip_id
           WHERE t.parcours = ? ORDER BY k.c DESC LIMIT 1""", (parcours,)).fetchone()
    if not trip:
        con.close()
        return []
    rows = con.execute(
        """SELECT st.seq, s.stop_id, s.name, s.city, s.lat, s.lon, st.dep
           FROM stop_times st JOIN stops s ON s.stop_id = st.stop_id
           WHERE st.trip_id = ? ORDER BY st.seq""", (trip["trip_id"],)).fetchall()
    con.close()
    return [{"seq": r["seq"], "arret_id": r["stop_id"], "arret": r["name"],
             "commune": r["city"], "lat": r["lat"], "lon": r["lon"]} for r in rows]


def parcours_list(route_short: str | None = None) -> list[dict]:
    """Parcours disponibles, avec leur ligne et leur sens."""
    con = connect()
    sql = """SELECT t.parcours, t.sens, t.headsign, r.short_name, r.long_name,
                    r.color, count(*) AS n
             FROM trips t JOIN routes r ON r.route_id = t.route_id
             WHERE t.parcours <> ''"""
    args: list = []
    if route_short:
        sql += " AND r.short_name = ?"
        args.append(route_short)
    sql += " GROUP BY t.parcours, t.sens ORDER BY r.short_name, t.parcours"
    rows = con.execute(sql, args).fetchall()
    con.close()
    return [{"parcours": r["parcours"], "sens": r["sens"], "ligne": r["short_name"],
             "ligne_nom": r["long_name"], "couleur": "#" + (r["color"] or "3b6ea5"),
             "destination": r["headsign"], "courses": r["n"]} for r in rows]


def stop_by_id(stop_id: str) -> dict | None:
    """Nom lisible d'un arret a partir de son identifiant."""
    con = connect()
    r = con.execute(
        "SELECT stop_id, name, city, lat, lon FROM stops WHERE stop_id = ?",
        (stop_id,)).fetchone()
    con.close()
    if not r:
        return None
    return {"arret_id": r["stop_id"], "arret": r["name"], "commune": r["city"],
            "lat": r["lat"], "lon": r["lon"]}

# --------------------------------------------------------------------------
# Inventaire des lignes
# --------------------------------------------------------------------------

# Les communes desservies demandent de parcourir tous les horaires : deux
# secondes. On les retient, et la reconstruction de l'index vide ce cache.
_communes_par_ligne: dict[str, list[str]] | None = None


def oublier() -> None:
    """Vide le cache des communes. Appelé après une reconstruction."""
    global _communes_par_ligne
    _communes_par_ligne = None


def _communes() -> dict[str, list[str]]:
    global _communes_par_ligne
    if _communes_par_ligne is not None:
        return _communes_par_ligne
    con = connect()
    rows = con.execute(
        """SELECT DISTINCT r.short_name AS ligne, s.city AS commune
           FROM routes r
           JOIN trips t ON t.route_id = r.route_id
           JOIN stop_times st ON st.trip_id = t.trip_id
           JOIN stops s ON s.stop_id = st.stop_id
           WHERE s.city <> '' AND r.short_name <> ''""").fetchall()
    con.close()
    par_ligne: dict[str, set] = {}
    for r in rows:
        par_ligne.setdefault(r["ligne"], set()).add(r["commune"])
    _communes_par_ligne = {k: sorted(v) for k, v in par_ligne.items()}
    return _communes_par_ligne


def lignes(commune: str | None = None, scolaires_seules: bool = False) -> list[dict]:
    """Toutes les lignes du référentiel, scolaires d'abord.

    Une ligne est scolaire quand ses courses portent un parcours « SCO » :
    c'est ainsi que le réseau les marque, et cela se vérifie — les lignes
    scolaires n'ont que des courses de ce type, les autres aucune.
    """
    con = connect()
    rows = con.execute(
        """SELECT r.short_name AS ligne, MIN(r.long_name) AS nom,
                  COUNT(t.trip_id) AS courses,
                  SUM(CASE WHEN t.parcours LIKE 'SCO%' THEN 1 ELSE 0 END) AS sco
           FROM routes r LEFT JOIN trips t ON t.route_id = r.route_id
           WHERE r.short_name <> ''
           GROUP BY r.short_name""").fetchall()
    con.close()

    communes = _communes()
    out = []
    for r in rows:
        villes = communes.get(r["ligne"], [])
        if commune and commune not in villes:
            continue
        scolaire = (r["sco"] or 0) > 0
        if scolaires_seules and not scolaire:
            continue
        out.append({
            "ligne": r["ligne"],
            "nom": r["nom"] or "",
            "courses": r["courses"] or 0,
            "scolaire": scolaire,
            "communes": villes,
        })

    def rang(l: dict):
        # Scolaires d'abord, puis par numéro, en traitant les numéros
        # comme des nombres pour que 170 vienne avant 1800.
        n = l["ligne"]
        return (0 if l["scolaire"] else 1,
                0 if n.isdigit() else 1,
                int(n) if n.isdigit() else 0, n)

    out.sort(key=rang)
    return out

def arrets_de_ligne(ligne: str) -> list[dict]:
    """Tous les arrêts qu'une ligne dessert, d'après le référentiel.

    La fiche officielle ne couvre qu'une ligne ; pour les cent quarante
    huit autres, c'est le GTFS qui sait où elles passent.
    """
    con = connect()
    rows = con.execute(
        """SELECT DISTINCT s.stop_id, s.name AS arret, s.city AS commune,
                  s.lat, s.lon
           FROM routes r
           JOIN trips t ON t.route_id = r.route_id
           JOIN stop_times st ON st.trip_id = t.trip_id
           JOIN stops s ON s.stop_id = st.stop_id
           WHERE r.short_name = ?""", (ligne,)).fetchall()
    con.close()
    vus: dict[str, dict] = {}
    for r in rows:
        # Un même nom d'arrêt existe des deux côtés de la chaussée : on
        # n'en garde qu'un, c'est un choix de réglage, pas un quai.
        if r["arret"] in vus:
            continue
        vus[r["arret"]] = {"arret": r["arret"], "commune": r["commune"] or "",
                           "arret_id": r["stop_id"],
                           "lat": r["lat"], "lon": r["lon"]}
    return sorted(vus.values(), key=lambda a: (a["commune"], a["arret"]))


def arrets_proches(lat: float, lon: float, rayon_m: int = 900,
                   limite: int = 12) -> list[dict]:
    """Les arrêts à portée de marche d'un point, du plus proche au plus loin.

    On présélectionne par une boîte, puis on mesure : comparer 3 791
    distances à chaque appel serait du gâchis.
    """
    if not lat or not lon:
        return []
    import math
    # Un degré de latitude fait 111 km ; en longitude il rétrécit avec le
    # cosinus de la latitude.
    dlat = rayon_m / 111000.0
    dlon = rayon_m / (111000.0 * max(0.1, math.cos(lat * math.pi / 180)))
    con = connect()
    rows = con.execute(
        """SELECT stop_id, name, city, lat, lon FROM stops
           WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?""",
        (lat - dlat, lat + dlat, lon - dlon, lon + dlon)).fetchall()
    con.close()

    r = math.pi / 180
    out = []
    for x in rows:
        if x["lat"] is None or x["lon"] is None:
            continue
        a = (math.sin((x["lat"] - lat) * r / 2) ** 2
             + math.cos(lat * r) * math.cos(x["lat"] * r)
             * math.sin((x["lon"] - lon) * r / 2) ** 2)
        m = 6371000 * 2 * math.asin(math.sqrt(a))
        if m > rayon_m:
            continue
        out.append({"arret_id": x["stop_id"], "arret": x["name"],
                    "commune": x["city"], "lat": x["lat"], "lon": x["lon"],
                    "metres": int(m)})
    out.sort(key=lambda a: a["metres"])
    return out[:limite]


def trace(ligne: str, sens: str | None = None) -> list[dict]:
    """Le tracé d'une ligne : ses arrêts dans l'ordre, avec coordonnées.

    On prend la course qui dessert le plus d'arrêts — c'est celle qui
    décrit le mieux le parcours ; les autres en sont des raccourcis. La
    recherche est bornée à cette ligne : compter les arrêts de toutes les
    courses du réseau coûterait des secondes.
    """
    if not ligne:
        return []
    con = connect()
    sql = """SELECT t.trip_id, t.parcours, COUNT(st.seq) AS n
             FROM routes r
             JOIN trips t ON t.route_id = r.route_id
             JOIN stop_times st ON st.trip_id = t.trip_id
             WHERE r.short_name = ?"""
    args: list = [ligne]
    if sens:
        sql += " AND t.sens = ?"
        args.append(sens)
    sql += " GROUP BY t.trip_id ORDER BY n DESC LIMIT 1"
    t = con.execute(sql, args).fetchone()
    if not t:
        con.close()
        return []
    rows = con.execute(
        """SELECT st.seq, s.stop_id, s.name, s.city, s.lat, s.lon
           FROM stop_times st JOIN stops s ON s.stop_id = st.stop_id
           WHERE st.trip_id = ? ORDER BY st.seq""", (t["trip_id"],)).fetchall()
    con.close()
    return [{"seq": r["seq"], "arret_id": r["stop_id"], "arret": r["name"],
             "commune": r["city"], "lat": r["lat"], "lon": r["lon"]}
            for r in rows if r["lat"] is not None]
