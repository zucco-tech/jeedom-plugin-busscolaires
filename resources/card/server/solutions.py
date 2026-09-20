"""Recherche d'itinéraires avec au plus une correspondance.

Le car scolaire couvre le cas courant. Le reste du temps — un car manqué,
un vendredi midi sans car, une sortie hors horaire scolaire — il faut
assembler deux lignes du réseau. C'est ce que fait ce module.

On s'arrête à une correspondance : au-delà, le trajet devient plus long
que d'attendre, et une adolescente ne fera pas trois changements pour
rentrer. La recherche est donc bornée, et rapide.
"""
from __future__ import annotations

import datetime as dt
from typing import Sequence

from .gtfs import connect
from .board import departures, trip_detail, now_local, arrets_proches, lignes


def _lignes_desservant(stop_ids: Sequence[str]) -> set[str]:
    """Les lignes qui passent par l'un de ces arrêts."""
    if not stop_ids:
        return set()
    con = connect()
    q = ",".join("?" * len(stop_ids))
    rows = con.execute(
        f"""SELECT DISTINCT r.short_name FROM stop_times st
            JOIN trips t ON t.trip_id = st.trip_id
            JOIN routes r ON r.route_id = t.route_id
            WHERE st.stop_id IN ({q}) AND r.short_name <> ''""",
        list(stop_ids)).fetchall()
    con.close()
    return {r["short_name"] for r in rows}


def _lignes_par_arret(stop_ids: Sequence[str]) -> dict:
    """Pour chacune des lignes passant par ces arrêts, la liste des
    arrêts concernés. C'est l'inverse de _lignes_desservant, et cela
    permet de ne retenir, par ligne, que l'arrêt le plus proche."""
    if not stop_ids:
        return {}
    con = connect()
    q = ",".join("?" * len(stop_ids))
    rows = con.execute(
        f"""SELECT DISTINCT st.stop_id, r.short_name AS ligne
            FROM stop_times st
            JOIN trips t ON t.trip_id = st.trip_id
            JOIN routes r ON r.route_id = t.route_id
            WHERE st.stop_id IN ({q}) AND r.short_name <> ''""",
        list(stop_ids)).fetchall()
    con.close()
    out: dict = {}
    for r in rows:
        out.setdefault(r["ligne"], []).append(r["stop_id"])
    return out


def _arrets_par_ligne(lignes: Sequence[str]) -> dict:
    """Les arrêts de chaque ligne, en une seule interrogation."""
    if not lignes:
        return {}
    con = connect()
    q = ",".join("?" * len(lignes))
    rows = con.execute(
        # Une course représentative par parcours, comme ailleurs : la
        # sous-requête corrélée que j'avais d'abord écrite relançait un
        # MIN() par ligne d'horaire et ne rendait jamais la main.
        f"""SELECT x.short_name AS ligne, st.stop_id
            FROM (SELECT r.short_name, MIN(t.trip_id) AS trip_id
                  FROM routes r JOIN trips t ON t.route_id = r.route_id
                  WHERE r.short_name IN ({q})
                  GROUP BY r.route_id, t.parcours) x
            JOIN stop_times st ON st.trip_id = x.trip_id
            GROUP BY x.short_name, st.stop_id""", list(lignes)).fetchall()
    con.close()
    out: dict = {}
    for r in rows:
        out.setdefault(r["ligne"], set()).add(r["stop_id"])
    return out


def _arrets_des_lignes(lignes: Sequence[str]) -> set[str]:
    """Tous les arrêts desservis par ces lignes.

    Sert à repérer les correspondances utiles : inutile de descendre à un
    arrêt d'où aucune ligne ne mène à destination.
    """
    if not lignes:
        return set()
    con = connect()
    q = ",".join("?" * len(lignes))
    # Une course représentative par parcours suffit : toutes celles d'un
    # même parcours desservent les mêmes arrêts. Parcourir les quatre
    # mille courses de ces lignes coûtait presque six secondes ; il y a
    # une trentaine de parcours.
    rows = con.execute(
        f"""SELECT DISTINCT st.stop_id
            FROM (SELECT MIN(t.trip_id) AS trip_id
                  FROM routes r JOIN trips t ON t.route_id = r.route_id
                  WHERE r.short_name IN ({q})
                  GROUP BY t.route_id, t.parcours) x
            JOIN stop_times st ON st.trip_id = x.trip_id""",
        list(lignes)).fetchall()
    con.close()
    return {r["stop_id"] for r in rows}


# La géométrie du réseau ne change qu'à la reconstruction de l'index :
# on la charge une fois pour toutes.
_cache_grille = None
_cache_utiles: dict = {}
_cache_frequence: dict | None = None


def oublier() -> None:
    """Vide les caches. Appelé après une reconstruction de l'index."""
    global _cache_grille, _cache_frequence
    _cache_grille = None
    _cache_frequence = None
    _cache_utiles.clear()


def _frequence() -> dict:
    """Nombre de courses par ligne, mesure de sa fréquence."""
    global _cache_frequence
    if _cache_frequence is None:
        _cache_frequence = {l["ligne"]: l["courses"] for l in lignes()}
    return _cache_frequence


def _peine_rarete(ligne: str) -> int:
    """Ce que coûte une ligne rare, en minutes de pénalité.

    Manquer un bus qui passe toutes les sept minutes ne coûte rien ;
    manquer celui qui passe deux fois dans la journée coûte l'après-midi.
    Une adolescente préférera la ligne qu'elle connaît et qui repasse — et
    elle a raison, c'est la plus sûre.
    """
    n = _frequence().get(ligne, 0)
    if n >= 600:
        return 0
    if n >= 200:
        return 8
    return 20


def _grille():
    """Tous les arrêts, rangés dans une grille grossière de 300 m."""
    global _cache_grille
    if _cache_grille is not None:
        return _cache_grille
    con = connect()
    tous = con.execute(
        "SELECT stop_id, lat, lon FROM stops WHERE lat IS NOT NULL").fetchall()
    con.close()
    pas = 0.003
    grille: dict = {}
    coord: dict = {}
    for r in tous:
        coord[r["stop_id"]] = (r["lat"], r["lon"])
        grille.setdefault((int(r["lat"] / pas), int(r["lon"] / pas)),
                          []).append(r["stop_id"])
    _cache_grille = (coord, grille, pas)
    return _cache_grille


def _etendre(ids: set, rayon_m: int = 160) -> set:
    """Ajoute à un ensemble d'arrêts tous ceux qui les touchent.

    Même raison que pour les correspondances : le pôle d'échange porte
    plusieurs identifiants. Sans cet élargissement, on écarterait l'arrêt
    de la ligne 11 à Krypton au motif qu'il n'est pas, lui, un arrêt de
    la 170 — alors que c'est le même trottoir.

    On range les arrêts dans une grille grossière pour ne comparer que
    des voisins : trois mille arrêts comparés deux à deux coûteraient
    cher pour rien.
    """
    if not ids:
        return set()
    import math
    coord, grille, pas = _grille()
    rad = math.pi / 180
    sortie = set(ids)
    for sid in ids:
        if sid not in coord:
            continue
        lat, lon = coord[sid]
        cx, cy = int(lat / pas), int(lon / pas)
        for dx in (-1, 0, 1):
            for dy in (-1, 0, 1):
                for autre in grille.get((cx + dx, cy + dy), ()):
                    if autre in sortie:
                        continue
                    la, lo = coord[autre]
                    a = (math.sin((la - lat) * rad / 2) ** 2
                         + math.cos(lat * rad) * math.cos(la * rad)
                         * math.sin((lo - lon) * rad / 2) ** 2)
                    if 6371000 * 2 * math.asin(math.sqrt(a)) <= rayon_m:
                        sortie.add(autre)
    return sortie


def correspondances(lat, lon, rayon_m: int = 160) -> list[str]:
    """Les arrêts où l'on peut monter en descendant ici.

    Se fier au nom ne marche pas : le pôle d'échange de Krypton porte
    quatre identifiants dans deux référentiels — « P+R Krypton » pour la
    ligne A, « P+R Krypton Q4 » pour la 170 — sans rien de commun. C'est
    pourtant le même trottoir. On juge donc par la distance.
    """
    if not lat or not lon:
        return []
    return [a["arret_id"] for a in arrets_proches(lat, lon, rayon_m, limite=25)]


def ids_par_nom(nom: str) -> list[str]:
    """Tous les identifiants portant ce nom d'arrêt.

    Un même arrêt existe des deux côtés de la chaussée, et parfois dans
    deux référentiels : on les prend tous, sinon on manque des départs.
    """
    if not nom:
        return []
    con = connect()
    rows = con.execute(
        "SELECT stop_id FROM stops WHERE name = ?", (nom,)).fetchall()
    con.close()
    return [r["stop_id"] for r in rows]


def _minutes(heure: str) -> int:
    h, m = heure.split(":")[:2]
    return int(h) * 60 + int(m)


def _texte(minutes: int) -> str:
    if minutes < 60:
        return f"{minutes} min"
    return f"{minutes // 60} h {minutes % 60:02d}"


def _marche(metres: int, vitesse: int) -> int:
    """Minutes de marche pour une distance à vol d'oiseau.

    Majorée d'un quart : on ne marche pas en ligne droite.
    """
    import math
    return math.ceil(metres * 1.25 / max(40, vitesse or 80))


def _points(ids, position, vitesse, rayon):
    """Les arrêts d'où l'on peut partir, avec le temps pour s'y rendre.

    Depuis une position, on retient les arrêts à portée de marche : c'est
    le seul moyen de proposer autre chose quand l'arrêt habituel n'est
    plus desservi — un vendredi midi, par exemple.
    """
    if ids:
        return {i: 0 for i in ids}
    if not position or not position[0] or not position[1]:
        return {}
    # Le centre d'Aix compte plus de quatre-vingts arrêts dans un rayon
    # de six cents mètres, doublons de sens compris. Tronquer la liste
    # écartait la Rotonde — et avec elle toute la ligne A.
    proches = arrets_proches(position[0], position[1], rayon, limite=300)
    return {a["arret_id"]: _marche(a["metres"], vitesse) for a in proches}


def trajets(depart_ids=None, arrivee_ids=None, quand: dt.datetime | None = None,
            depart_pos=None, arrivee_pos=None, vitesse: int = 80,
            rayon_m: int = 900, horizon_min: int = 300,
            correspondance_min: int = 4, limite: int = 4,
            attente_max_min: int = 90) -> list[dict]:
    """Itinéraires de porte à porte, au plus une correspondance.

    Départ et arrivée peuvent être des arrêts ou des positions. Depuis une
    position, on considère tous les arrêts à portée de marche : c'est ce
    qui permet de répondre quand l'arrêt habituel ne sert plus.

    On classe par heure d'arrivée à destination, marche comprise. Un
    direct qui part dans deux heures perd contre une correspondance qui
    part maintenant.
    """
    quand = quand or now_local()
    depuis = _points(list(depart_ids or []), depart_pos, vitesse, rayon_m)
    vers = _points(list(arrivee_ids or []), arrivee_pos, vitesse, rayon_m)
    if not depuis or not vers:
        return []

    lignes_dest = _lignes_desservant(list(vers))
    cle = ",".join(sorted(lignes_dest))
    if cle not in _cache_utiles:
        _cache_utiles[cle] = _etendre(_arrets_des_lignes(list(lignes_dest)))
    arrets_utiles = _cache_utiles[cle]
    base = _minutes(quand.strftime("%H:%M"))

    # Deux cents arrêts au centre d'Aix voient passer un bus toutes les
    # dix secondes : demander « les mille prochains départs » ne couvre
    # qu'un quart d'heure, et le bus utile part dans une heure. On
    # interroge donc ligne par ligne — chacune obtient sa fenêtre — après
    # avoir écarté celles qui ne mènent nulle part.
    cibles = set(vers) | arrets_utiles
    par_ligne = _lignes_par_arret(list(depuis))
    dessertes = _arrets_par_ligne(list(par_ligne))

    premiers = []
    for ligne, arrets_ici in sorted(par_ligne.items()):
        if not (dessertes.get(ligne, set()) & cibles):
            continue
        # Inutile d'interroger les vingt arrêts où passe cette ligne :
        # celui qui est le plus près suffit, et l'interrogation devient
        # vingt fois plus légère.
        proche = min(arrets_ici, key=lambda i: depuis.get(i, 99))
        # On garde aussi l'autre sens, s'il est à peine plus loin.
        retenus = [i for i in arrets_ici
                   if depuis.get(i, 99) <= depuis.get(proche, 0) + 2][:4]
        premiers.extend(departures(retenus, quand,
                                   horizon_min=horizon_min, limit=60,
                                   route_short=ligne))
    # Une même course ressort autant de fois qu'elle dessert d'arrêts
    # autour de nous — huit fois pour une ligne qui traverse le centre.
    # On n'en garde qu'une : celle dont l'arrêt est le plus proche, donc
    # la moins de marche.
    unique: dict = {}
    for d in premiers:
        vieux = unique.get(d["trip_id"])
        if vieux is None or depuis.get(d["arret_id"], 99) < depuis.get(vieux["arret_id"], 99):
            unique[d["trip_id"]] = d
    premiers = sorted(unique.values(), key=lambda d: d["heure"])
    vus: set = set()
    out: list[dict] = []
    # La mémoire porte sur le parcours, pas sur la ligne : une ligne a
    # deux sens, et juger toute la ligne sur une course qui part dans la
    # mauvaise direction reviendrait à rayer le bon car.
    utile: dict[str, bool] = {}
    details: dict = {}
    # Un garde-fou, pas un rationnement : la bonne correspondance peut se
    # trouver au dixième arrêt d'une course. Les interrogations sont
    # devenues bon marché, on peut se permettre de chercher.
    budget = [600]

    def detail_de(trip_id):
        if trip_id not in details:
            details[trip_id] = trip_detail(trip_id)
        return details[trip_id]

    def marque(dep):
        return dep.get("parcours") or (dep["ligne"] + "|" + (dep.get("destination") or ""))

    for d in premiers:
        if utile.get(marque(d)) is False:
            continue
        # Il faut d'abord rejoindre l'arrêt à pied.
        marche_debut = depuis.get(d["arret_id"], 0)
        if _minutes(d["heure"]) < base + marche_debut:
            continue
        detail = detail_de(d["trip_id"])
        if not detail:
            continue
        arrets = detail["arrets"]
        monte = next((i for i, a in enumerate(arrets)
                      if a["arret_id"] == d["arret_id"]
                      and a["heure"][:5] == d["heure"][:5]), None)
        if monte is None:
            continue
        suite = arrets[monte + 1:]

        # Ce parcours touche-t-il seulement quelque chose d'utile ?
        cle_parcours = marque(d)
        if cle_parcours not in utile:
            utile[cle_parcours] = any(
                a["arret_id"] in vers or a["arret_id"] in arrets_utiles
                for a in suite)
        if not utile[cle_parcours]:
            continue

        # --- direct ---
        fin = next((a for a in suite if a["arret_id"] in vers), None)
        if fin:
            cle = ("direct", d["ligne"], d["heure"], fin["arret_id"])
            if cle not in vus:
                vus.add(cle)
                out.append(_composer(base, [(d, arrets[monte], fin, detail)],
                                     marche_debut, vers.get(fin["arret_id"], 0)))
            continue

        # --- une correspondance ---
        # On n'arrête pas à la première correspondance qui marche : la
        # meilleure est souvent plus loin sur la course. Descendre à
        # Beausoleil donne la ligne 8 ; deux arrêts plus loin, Krypton
        # donne la ligne A, qui passe six fois plus souvent. Le
        # classement tranchera, pas l'ordre des arrêts.
        essais_ici = 0
        for a in suite:
            if a["arret_id"] not in arrets_utiles:
                continue
            if essais_ici >= 25 or budget[0] <= 0:
                break
            essais_ici += 1
            budget[0] -= 1
            quand2 = quand.replace(hour=0, minute=0, second=0, microsecond=0) \
                + dt.timedelta(minutes=_minutes(a["heure"]) + correspondance_min)
            voisins = correspondances(a.get("lat"), a.get("lon")) or [a["arret_id"]]
            seconds = departures(voisins, quand2,
                                 horizon_min=attente_max_min, limit=14)
            for sec in seconds:
                if sec["ligne"] not in lignes_dest or sec["ligne"] == d["ligne"]:
                    continue
                d2 = detail_de(sec["trip_id"])
                if not d2:
                    continue
                i2 = next((i for i, x in enumerate(d2["arrets"])
                           if x["arret_id"] == sec["arret_id"]
                           and x["heure"][:5] == sec["heure"][:5]), None)
                if i2 is None:
                    continue
                fin2 = next((x for x in d2["arrets"][i2 + 1:]
                             if x["arret_id"] in vers), None)
                if not fin2:
                    continue
                cle = ("corr", d["ligne"], d["heure"], sec["ligne"], fin2["arret_id"])
                if cle in vus:
                    continue
                vus.add(cle)
                out.append(_composer(base, [
                    (d, arrets[monte], a, detail),
                    (sec, d2["arrets"][i2], fin2, d2),
                ], marche_debut, vers.get(fin2["arret_id"], 0)))
                break        # un seul départ par correspondance suffit
        # La recherche ne se règle pas sur le nombre de réponses voulues :
        # n'en demander qu'une arrêtait l'exploration avant d'avoir trouvé
        # la bonne. Le budget de temps, lui, borne déjà le travail.
        if len(out) >= max(20, limite * 5) or budget[0] <= 0:
            break

    # Une correspondance de moins vaut un quart d'heure : changer de bus
    # avec une valise de cours, c'est une attente, un quai à trouver et
    # un car qu'on peut rater. On la paie donc en minutes, et un trajet
    # direct l'emporte même s'il arrive un peu plus tard.
    for t in out:
        rarete = sum(_peine_rarete(e["ligne"]) for e in t["etapes"])
        t["cout"] = t["arrivee_min"] + 15 * t["correspondances"] + rarete
        t["peine_rarete"] = rarete
    # À coût égal, celui qui part le plus tard : aucune raison d'attendre
    # vingt minutes de plus à l'arrêt pour arriver en même temps.
    out.sort(key=lambda t: (t["cout"], -t["depart_min"], t["arrivee_min"]))
    # Deux solutions qui arrivent à cinq minutes d'écart ne sont pas deux
    # choix, c'est du bruit. On n'en garde une de plus que si elle change
    # vraiment quelque chose : un quart d'heure, ou d'autres lignes.
    retenus: list = []
    for t in out:
        lignes_t = tuple(e["ligne"] for e in t["etapes"])
        proche = any(
            abs(t["arrivee_min"] - r["arrivee_min"]) < 15
            and lignes_t == tuple(e["ligne"] for e in r["etapes"])
            or t["arrivee_min"] == r["arrivee_min"]
            for r in retenus)
        if proche:
            continue
        retenus.append(t)
        if len(retenus) >= limite:
            break
    return retenus


def _composer(base: int, morceaux: list[tuple],
              marche_debut: int = 0, marche_fin: int = 0) -> dict:
    """Met en forme un itinéraire à partir de ses tronçons."""
    etapes = []
    for depart, monte, descend, detail in morceaux:
        etapes.append({
            "ligne": depart["ligne"],
            "ligne_nom": detail.get("ligne_nom") or "",
            "parcours": depart.get("parcours") or "",
            "scolaire": bool(depart.get("scolaire")),
            "couleur": detail.get("couleur"),
            "de": monte["arret"], "de_id": monte["arret_id"],
            "heure_depart": monte["heure"][:5],
            "a": descend["arret"], "a_id": descend["arret_id"],
            "heure_arrivee": descend["heure"][:5],
            "duree_min": _minutes(descend["heure"]) - _minutes(monte["heure"]),
        })
    depart_min = _minutes(etapes[0]["heure_depart"]) - marche_debut
    arrivee_min = _minutes(etapes[-1]["heure_arrivee"]) + marche_fin
    attente = 0
    if len(etapes) > 1:
        attente = _minutes(etapes[1]["heure_depart"]) \
            - _minutes(etapes[0]["heure_arrivee"])
    return {
        "etapes": etapes,
        "depart": f"{depart_min // 60:02d}:{depart_min % 60:02d}",
        "arrivee": f"{arrivee_min // 60:02d}:{arrivee_min % 60:02d}",
        "marche_debut_min": marche_debut,
        "marche_fin_min": marche_fin,
        "depart_min": depart_min,
        "arrivee_min": arrivee_min,
        "duree_min": arrivee_min - depart_min,
        "duree": _texte(arrivee_min - depart_min),
        "attente_avant_min": max(0, depart_min - base),
        "attente_avant": _texte(max(0, depart_min - base)),
        "correspondance_min": attente,
        "correspondances": len(etapes) - 1,
        "scolaire": all(e["scolaire"] for e in etapes),
        "resume": " puis ".join(
            f"{e['ligne']} {e['heure_depart']}" for e in etapes),
    }
