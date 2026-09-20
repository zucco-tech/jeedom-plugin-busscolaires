"""API du service. Le démon n'expose que du JSON, sur la boucle locale."""
from __future__ import annotations

import datetime as dt
import json
import re
import threading

from fastapi import FastAPI, HTTPException, Query, Request
from fastapi.responses import JSONResponse, PlainTextResponse

from . import board, config, fiche, gtfs, push, realtime, solutions

app = FastAPI(title="Bus & Ecole", version="1.1")

# Le démon n'écoute que sur la boucle locale et n'est atteint que par le
# plugin : aucune page ne lui parle directement, donc aucun CORS.


@app.on_event("startup")
def _demarrage() -> None:
    push.demarrer()

PROFILE_PATH = config.DATA_DIR / "profil.json"

# Au-delà, un car cesse d'être une solution : le vendredi, les cours
# finissent à midi et le premier car du soir part à 16:18.
# Le car scolaire passe avant le réseau, même s'il faut l'attendre un peu :
# c'est celui qu'elle connaît et qui la dépose devant chez elle. Une heure
# et demie reste une attente ; le vendredi, quatre heures après midi, ce
# n'est plus un car, c'est une salle d'attente.
ATTENTE_MAX_MIN = 90


def _mn(h: str) -> int:
    """« 07:38 » en minutes depuis minuit."""
    return int(h[:2]) * 60 + int(h[3:5])

# Au-delà, on considère le car du matin comme manqué et l'on propose de
# rejoindre l'école autrement.
RETARD_MAX_MIN = 15

# Passé une heure et demie, il est trop tard pour proposer de rejoindre
# l'école : la matinée est entamée, ce n'est plus notre affaire.
FENETRE_MANQUE_MIN = 90


_build_state = {"en_cours": False, "journal": [], "erreur": None}


# --------------------------------------------------------------------------
# Profil (arrets et parcours suivis)
# --------------------------------------------------------------------------

def load_profile() -> dict:
    if PROFILE_PATH.exists():
        try:
            return {**config.DEFAULT_PROFILE, **json.loads(PROFILE_PATH.read_text())}
        except (json.JSONDecodeError, OSError):
            pass
    return dict(config.DEFAULT_PROFILE)


@app.get("/api/profil")
def get_profile():
    return load_profile()


ENTIERS = {"marche_m_min": (40, 140), "correspondance_min": (1, 30),
           "preavis_min": (1, 120)}
JOURS = ["lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi",
         "dimanche"]
SORTIE_JOUR = config.DATA_DIR / "sortie_du_jour.json"


def surcharges(requete: Request) -> dict:
    """Le trajet passé dans l'URL, s'il y en a un.

    Le service ne mémorise qu'un profil, hérité de l'époque où il servait
    un seul enfant. Le plugin, lui, tient un trajet par équipement et le
    joint à chaque appel : le service redevient un simple calculateur, et
    plusieurs enfants, plusieurs écoles ou plusieurs lignes cohabitent
    sans qu'il ait rien à retenir.
    """
    out: dict = {}
    for cle, valeur in requete.query_params.items():
        if cle not in config.DEFAULT_PROFILE or valeur == "":
            continue
        modele = config.DEFAULT_PROFILE[cle]
        try:
            if isinstance(modele, bool):
                out[cle] = valeur not in ("0", "false", "no")
            elif isinstance(modele, int):
                out[cle] = int(valeur)
            elif isinstance(modele, float):
                out[cle] = float(valeur)
            else:
                out[cle] = valeur
        except (TypeError, ValueError):
            raise HTTPException(400, f"{cle} : valeur illisible ({valeur})")
    return out


def profil_effectif(requete: Request | None = None,
                    surcharge: dict | None = None) -> dict:
    """Profil enregistré, complété par ce que l'appel a fourni."""
    p = load_profile()
    p.update(surcharge or (surcharges(requete) if requete is not None else {}))
    return p


def sortie_prevue(jour: dt.date, prof: dict | None = None) -> str:
    """Heure de fin des cours retenue pour ce jour.

    Trois sources, par ordre de confiance :
      1. l'heure poussée pour cette date précise (par la domotique, qui
         peut la tenir d'un plugin Pronote) ;
      2. l'heure réglée pour ce jour de la semaine ;
      3. l'heure par défaut.
    """
    if prof is None:
        prof = load_profile()
    if SORTIE_JOUR.exists():
        try:
            d = json.loads(SORTIE_JOUR.read_text())
            if d.get("date") == jour.isoformat() and d.get("heure"):
                return d["heure"]
        except (json.JSONDecodeError, OSError):
            pass
    nom = JOURS[jour.weekday()]
    return (prof.get(f"sortie_{nom}") or prof.get("sortie_defaut") or "16:05")


@app.put("/api/profil")
def put_profile(profil: dict):
    """Enregistre les réglages, après les avoir vérifiés.

    Un réglage hors bornes ne doit pas s'installer discrètement : on refuse
    en disant lequel et pourquoi.
    """
    current = load_profile()
    propres: dict = {}
    for cle, valeur in profil.items():
        if cle not in config.DEFAULT_PROFILE:
            continue
        if cle in ENTIERS:
            bas, haut = ENTIERS[cle]
            try:
                n = int(valeur)
            except (TypeError, ValueError):
                raise HTTPException(400, f"{cle} : un nombre est attendu")
            if not bas <= n <= haut:
                raise HTTPException(
                    400, f"{cle} : à choisir entre {bas} et {haut}")
            propres[cle] = n
        elif cle == "sortie_defaut" or cle.startswith("sortie_"):
            v = str(valeur or "").strip()
            if v and not re.fullmatch(r"\d{2}:\d{2}", v):
                raise HTTPException(400, f"{cle} : format attendu HH:MM")
            propres[cle] = v
        elif cle in ("arret_maison", "arret_ecole"):
            v = str(valeur or "").strip()
            if v and not fiche.situe(v):
                raise HTTPException(400, f"Arrêt inconnu : {v}")
            propres[cle] = v
        else:
            propres[cle] = valeur

    current.update(propres)
    PROFILE_PATH.write_text(json.dumps(current, ensure_ascii=False, indent=2))
    return current


@app.get("/api/arrets-ligne")
def arrets_ligne(ligne: str | None = None):
    """Les arrêts que la ligne dessert, séparés par côté.

    C'est ce qui alimente les listes de la page de réglages : on ne propose
    que des arrêts réellement desservis.
    """
    _need_index()
    ligne = ligne or load_profile().get("ligne") or "1800"
    vus: dict[str, dict] = {}
    # La fiche officielle prime quand elle existe : elle nomme les arrêts
    # comme la ligne les annonce. Sinon le référentiel fait foi.
    for c in fiche._charger(ligne).get("A", []) + fiche._charger(ligne).get("R", []):
        for nom in c["arrets"]:
            if nom in vus:
                continue
            p = fiche.situe(nom)
            vus[nom] = {"arret": nom, "commune": (p or {}).get("city", ""),
                        "connu": bool(p), "source": "fiche"}
    if not vus:
        for a in board.arrets_de_ligne(ligne):
            vus[a["arret"]] = {"arret": a["arret"], "commune": a["commune"],
                               "connu": True, "source": "gtfs"}
    tous = sorted(vus.values(), key=lambda a: (a["commune"], a["arret"]))
    return {
        "ligne": ligne,
        "aix": [a for a in tous if a["commune"].startswith("Aix")],
        "ailleurs": [a for a in tous if not a["commune"].startswith("Aix")],
    }


# --------------------------------------------------------------------------
# Etat general et index
# --------------------------------------------------------------------------

@app.get("/api/etat")
def etat():
    pret = gtfs.is_ready()
    info = {}
    if pret:
        con = gtfs.connect()
        info = {r["key"]: r["value"] for r in con.execute("SELECT * FROM meta")}
        for table in ("stops", "trips", "stop_times", "service_dates"):
            info[table] = con.execute(f"SELECT count(*) FROM {table}").fetchone()[0]
        con.close()
    alertes = realtime.fetch_alerts()
    return {
        "index_pret": pret,
        "index": info,
        "construction": _build_state,
        "sources": {
            "fiches_officielles": fiche.etat(),
            "horaires": {
                "nom": "GTFS Pays d'Aix Mobilite (Metropole Aix-Marseille-Provence)",
                "etat": "officiel, a jour" if pret else "non indexe",
            },
            "alertes": {
                "nom": "GTFS-RT Service Alerts (api-mobilite.rbgl.fr)",
                "etat": "erreur" if alertes.get("erreur") else "en direct",
                "nombre": len(alertes.get("alertes", [])),
                "detail": alertes.get("erreur"),
            },
            "passages_temps_reel": {
                "nom": "SIRI StopMonitoring (siri.lametropolemobilite.fr)",
                "etat": "en attente des operateurs",
            },

        },
    }


@app.post("/api/index/reconstruire")
def rebuild():
    if _build_state["en_cours"]:
        return {"en_cours": True, "journal": _build_state["journal"]}

    def run():
        _build_state.update(en_cours=True, journal=[], erreur=None)
        try:
            gtfs.build(progress=lambda m: _build_state["journal"].append(m))
            # Le référentiel a changé sous les pieds de la fiche.
            fiche.oublier()
            board.oublier()
            solutions.oublier()
        except Exception as e:
            _build_state["erreur"] = str(e)
            _build_state["journal"].append(f"ECHEC : {e}")
        finally:
            _build_state["en_cours"] = False

    threading.Thread(target=run, daemon=True).start()
    return {"en_cours": True}


@app.get("/api/index/etat")
def build_status():
    return _build_state


# --------------------------------------------------------------------------
# Reseau
# --------------------------------------------------------------------------

def _need_index():
    if not gtfs.is_ready():
        raise HTTPException(503, "Index GTFS absent : lancez la construction "
                                 "(POST /api/index/reconstruire)")


@app.get("/api/arrets")
def arrets(q: str = Query(..., min_length=2), limit: int = 20):
    _need_index()
    return board.search_stops(q, limit)


@app.get("/api/arret/{stop_id}")
def arret(stop_id: str):
    _need_index()
    s = board.stop_by_id(stop_id)
    if not s:
        raise HTTPException(404, f"Arret inconnu : {stop_id}")
    return s


@app.get("/api/lignes")
def lignes(commune: str | None = None, scolaires: bool = False):
    """Inventaire des lignes du référentiel, scolaires d'abord.

    C'est ce qui alimente le choix de la ligne dans les réglages : on ne
    fait pas taper un numéro au hasard. Les lignes couvertes par une
    fiche horaire officielle sont signalées — ce sont celles dont les
    horaires sont sûrs.
    """
    _need_index()
    couvertes = set(fiche.lignes_couvertes())
    return [{**l, "fiche": l["ligne"] in couvertes}
            for l in board.lignes(commune, scolaires)]


@app.get("/api/parcours")
def parcours(ligne: str | None = None):
    _need_index()
    return board.parcours_list(ligne)


@app.get("/api/parcours/{parcours_id}/arrets")
def parcours_arrets(parcours_id: str):
    _need_index()
    stops = board.stops_of_parcours(parcours_id)
    if not stops:
        raise HTTPException(404, f"Parcours inconnu : {parcours_id}")
    return stops


@app.get("/api/departs")
def departs(
    arret: list[str] = Query(default=[]),
    ligne: str | None = None,
    parcours: str | None = None,
    horizon: int | None = None,
    limit: int = 12,
    date: str | None = None,
):
    """Prochains departs, enrichis du temps reel quand il est disponible.

    Sans date, on regarde les 4 heures qui viennent. Avec une date explicite,
    on couvre la journee entiere : demander une date puis n'obtenir que les
    quatre premieres heures de la nuit n'aurait aucun sens.
    """
    _need_index()
    if not arret:
        raise HTTPException(400, "Indiquez au moins un arret (?arret=...)")
    when = dt.datetime.now()
    fenetre = horizon if horizon is not None else 240
    if date:
        try:
            when = dt.datetime.combine(dt.date.fromisoformat(date), dt.time(0, 0))
        except ValueError:
            raise HTTPException(400, "Date invalide (format AAAA-MM-JJ)")
        fenetre = horizon if horizon is not None else 1440
    res = board.departures(arret, when=when, horizon_min=fenetre, limit=limit,
                           route_short=ligne, parcours=parcours)
    if date:
        board.recaler(res, dt.datetime.now()
                      if dt.date.fromisoformat(date) == dt.date.today()
                      else dt.datetime.combine(dt.date.fromisoformat(date),
                                               dt.time(0, 0)))
    _enrich_realtime(res, arret)
    return {"departs": res, "genere_a": dt.datetime.now().isoformat(timespec="seconds")}


def _enrich_realtime(departs: list[dict], arret_ids: list[str]) -> None:
    """Superpose les horaires SIRI aux horaires theoriques, quand ils existent."""
    if not config.SIRI_ENABLED or not departs:
        return
    reel: list[dict] = []
    for sid in arret_ids[:3]:
        reel.extend(realtime.siri_stop(sid).get("passages", []))
    if not reel:
        return
    for d in departs:
        for p in reel:
            heure = (p.get("heure") or "")[11:16]
            if heure == d["heure"] and (not p.get("ligne")
                                        or str(p["ligne"]) == str(d["ligne"])):
                d["temps_reel"] = True
                d["retard_minutes"] = p.get("retard_minutes")
                d["a_quai"] = p.get("a_quai")
                if p.get("retard_minutes"):
                    d["dans_minutes"] += p["retard_minutes"]
                break


@app.get("/api/course/{trip_id}")
def course(trip_id: str):
    _need_index()
    d = board.trip_detail(trip_id)
    if not d:
        raise HTTPException(404, "Course inconnue")
    return d


@app.get("/api/alertes")
def alertes(ligne: list[str] = Query(default=[]), arret: list[str] = Query(default=[])):
    if ligne or arret:
        return {"alertes": realtime.alerts_for(set(ligne), set(arret))}
    return realtime.fetch_alerts()


@app.get("/api/temps-reel/etat")
def temps_reel_etat():
    return realtime.siri_status()


# --------------------------------------------------------------------------
# Vue fusionnee : la journee de A a Z
# --------------------------------------------------------------------------

@app.get("/api/journee")
def journee(requete: Request = None, date: str | None = None,
            prof: dict | None = None):
    """Croise l'emploi du temps et les bus : quel bus prendre, pour quel cours."""
    _need_index()
    jour = dt.date.fromisoformat(date) if date else dt.date.today()
    if prof is None:
        prof = profil_effectif(requete)
    maintenant = dt.datetime.now()
    ref = maintenant if jour == dt.date.today() else dt.datetime.combine(jour, dt.time(0, 1))


    # Le profil désigne les arrêts par leur nom : on les résout en
    # identifiants, comme le fait l'application.
    id_fuveau = _id_arret(prof.get("arret_maison"))
    id_aix = _id_arret(prof.get("arret_ecole"))
    ligne = prof.get("ligne") or "1800"
    aller = _sens(id_fuveau, None, jour, dt.time(5, 0), 360, 8, ligne, "A")
    retour = _sens(id_aix, None, jour, dt.time(11, 0), 600, 10, ligne, "R")

    # Les fenetres de recherche partent de 05h00 et 11h00 : on recale les
    # comptes a rebours sur l'heure reelle avant de les envoyer a l'interface.
    board.recaler(aller, ref)
    board.recaler(retour, ref)

    # En fin de journee tous les bus sont passes : on bascule sur le prochain
    # jour ou la ligne circule, plutot que d'afficher une carte vide.
    demain_aller = demain_retour = None
    if jour == dt.date.today():
        if not any(d["dans_minutes"] >= 0 for d in aller):
            demain_aller = _jour_suivant(id_fuveau, None, jour,
                                         dt.time(5, 0), 360, 8, ligne, "A")
        if not any(d["dans_minutes"] >= 0 for d in retour):
            demain_retour = _jour_suivant(id_aix, None, jour,
                                          dt.time(11, 0), 600, 10, ligne, "R")

    conseil_aller = _bus_pour_arriver(aller, None, prof)
    conseil_retour = _bus_apres(retour, sortie_prevue(jour, prof))

    lignes = {d["ligne"] for d in aller + retour}
    arrets = {a for a in (id_fuveau, id_aix) if a}
    return {
        "date": jour.isoformat(),
        "maintenant": maintenant.isoformat(timespec="seconds"),
        "profil": prof,
        "sortie": sortie_prevue(jour, prof),
        "aller": {"departs": aller, "conseille": conseil_aller,
                   "jour_suivant": demain_aller},
        "retour": {"departs": retour, "conseille": conseil_retour,
                   "jour_suivant": demain_retour},
        "alertes": realtime.alerts_for(lignes, arrets),
    }


def _id_arret(nom: str | None) -> str | None:
    """Nom d'arrêt du profil -> identifiant du référentiel."""
    p = fiche.situe(nom) if nom else None
    return p["stop_id"] if p else None


def _sens(arret: str | None, parcours: str | None, jour: dt.date,
          debut: dt.time, horizon: int, limit: int,
          ligne: str | None = None, sens: str | None = None) -> list[dict]:
    if not arret:
        return []
    deps = board.departures([arret], when=dt.datetime.combine(jour, debut),
                            horizon_min=horizon, limit=limit,
                            route_short=ligne, parcours=parcours or None)
    # Un arrêt voit passer les deux sens : on ne garde que celui demandé.
    return [d for d in deps if not sens or d.get("sens") == sens]


def _jour_suivant(arret: str | None, parcours: str | None, depuis: dt.date,
                  debut: dt.time, horizon: int, limit: int,
                  ligne: str | None = None, sens: str | None = None) -> dict | None:
    """Cherche le prochain jour (jusqu'a 10 jours) ou la ligne circule."""
    for delta in range(1, 11):
        jour = depuis + dt.timedelta(days=delta)
        deps = _sens(arret, parcours, jour, debut, horizon, limit, ligne, sens)
        if deps:
            board.recaler(deps, dt.datetime.combine(jour, dt.time(0, 0)))
            return {"date": jour.isoformat(), "departs": deps,
                    "conseille": deps[0]}
    return None


def _futurs(departs: list[dict]) -> list[dict]:
    return [d for d in departs if d["dans_minutes"] >= 0]


def _bus_pour_arriver(departs: list[dict], debut_cours: str | None, prof: dict):
    """Dernier bus encore a prendre permettant d'etre a l'heure au premier cours."""
    dispo = _futurs(departs)
    if not dispo:
        return None
    if not debut_cours:
        return dispo[0]
    marche = 2   # marche jusqu'à l'arrêt, en minutes
    limite = _min(debut_cours) - marche
    ok = [d for d in dispo if _min(d["heure"]) <= limite]
    return ok[-1] if ok else dispo[0]


def _bus_apres(departs: list[dict], fin_cours: str | None):
    """Premier bus encore a prendre apres la fin des cours."""
    dispo = _futurs(departs)
    if not dispo:
        return None
    if not fin_cours:
        return dispo[0]
    ok = [d for d in dispo if _min(d["heure"]) >= _min(fin_cours)]
    return ok[0] if ok else dispo[0]


def _min(hhmm: str) -> int:
    h, m = hhmm.split(":")[:2]
    return int(h) * 60 + int(m)


# --------------------------------------------------------------------------
# Interface
# --------------------------------------------------------------------------

@app.get("/api/sortie")
def get_sortie(requete: Request = None, date: str | None = None):
    jour = dt.date.fromisoformat(date) if date else dt.date.today()
    prof = profil_effectif(requete)
    pousse = None
    if SORTIE_JOUR.exists():
        try:
            d = json.loads(SORTIE_JOUR.read_text())
            if d.get("date") == jour.isoformat():
                pousse = d.get("heure")
        except (json.JSONDecodeError, OSError):
            pass
    return {"date": jour.isoformat(), "jour": JOURS[jour.weekday()],
            "heure": sortie_prevue(jour, prof), "poussee": pousse,
            "hebdomadaire": prof.get(f"sortie_{JOURS[jour.weekday()]}"),
            "defaut": prof.get("sortie_defaut")}


@app.put("/api/sortie")
def put_sortie(corps: dict):
    """L'heure réelle de fin des cours, poussée depuis la domotique.

    Ouverte sans code : c'est une machine du réseau local qui l'appelle, et
    la valeur n'expose rien. Elle ne vaut que pour la date indiquée.
    """
    heure = str(corps.get("heure") or "").strip()
    if heure and not re.fullmatch(r"\d{2}:\d{2}", heure):
        raise HTTPException(400, "heure : format attendu HH:MM")
    jour = corps.get("date") or dt.date.today().isoformat()
    try:
        dt.date.fromisoformat(jour)
    except ValueError:
        raise HTTPException(400, "date invalide (AAAA-MM-JJ)")
    if not heure:
        SORTIE_JOUR.unlink(missing_ok=True)
        return {"date": jour, "heure": sortie_prevue(dt.date.fromisoformat(jour)),
                "poussee": None}
    SORTIE_JOUR.write_text(json.dumps({"date": jour, "heure": heure,
                                       "recu": dt.datetime.now().isoformat(
                                           timespec="seconds")}))
    return {"date": jour, "heure": heure, "poussee": heure}


# --------------------------------------------------------------------------
# Résumé plat, pour la domotique
#
# Jeedom ne sait pas parcourir une structure imbriquée : il attribue une
# commande à une valeur. Cette route renvoie donc des valeurs simples,
# nommées, prêtes à devenir des commandes info — un seul appel, pas dix.
# --------------------------------------------------------------------------

def _marche_min(depuis: tuple, nom_arret: str, vitesse) -> int:
    """Minutes de marche entre un point et un arret.

    La distance a vol d'oiseau est majoree d'un quart : on ne marche pas
    en ligne droite. Renvoie zero si l'on ignore d'ou l'on part.
    """
    lat, lon = depuis
    if not lat or not lon or not nom_arret:
        return 0
    p = fiche.situe(nom_arret)
    if not p or p["lat"] is None or p["lon"] is None:
        return 0
    import math
    r = math.pi / 180
    a = (math.sin((lat - p["lat"]) * r / 2) ** 2
         + math.cos(p["lat"] * r) * math.cos(lat * r)
         * math.sin((lon - p["lon"]) * r / 2) ** 2)
    metres = 6371000 * 2 * math.asin(math.sqrt(a)) * 1.25
    return math.ceil(metres / max(40, vitesse or 80))


@app.get("/api/trace")
def api_trace(requete: Request = None, ligne: str | None = None,
              sens: str | None = None, parcours: str | None = None):
    """Le tracé d'une ligne, pour le dessiner.

    Renvoie les arrêts dans l'ordre avec leurs coordonnées, plus la boîte
    qui les contient : de quoi projeter sans rien calculer de plus.
    """
    _need_index()
    prof = profil_effectif(requete)
    ligne = ligne or prof.get("ligne") or "1800"

    arrets = []
    # La fiche officielle décrit les circuits réels de la ligne. Le tracé
    # tiré du GTFS est celui d'une course quelconque : sur la 1800 il ne
    # passe pas par les arrêts de l'élève. Quand on sait quel circuit
    # l'intéresse, on prend le sien.
    if parcours and fiche.couvre(ligne):
        for jour in fiche.jours_de_classe(ligne)[:1] or [dt.date.today().isoformat()]:
            for c in fiche.courses(ligne, jour):
                if c["code"] != parcours:
                    continue
                arrets = [{"seq": i, "arret_id": a.get("arret_id"),
                           "arret": a["arret"], "commune": a.get("commune", ""),
                           "lat": a.get("lat"), "lon": a.get("lon")}
                          for i, a in enumerate(c["arrets"])
                          if a.get("lat") is not None]
                break
            if arrets:
                break

    if not arrets:
        arrets = board.trace(ligne, sens)
    if not arrets:
        raise HTTPException(404, f"Aucun tracé pour la ligne {ligne}")
    lats = [a["lat"] for a in arrets]
    lons = [a["lon"] for a in arrets]
    return {
        "ligne": ligne, "sens": sens or "", "parcours": parcours or "",
        "arrets": arrets,
        "boite": {"lat_min": min(lats), "lat_max": max(lats),
                  "lon_min": min(lons), "lon_max": max(lons)},
    }


@app.get("/api/solutions")
def api_solutions(requete: Request = None, depuis: str | None = None,
                  vers: str | None = None, date: str | None = None,
                  heure: str | None = None, limite: int = 4,
                  sens: str | None = None,
                  lat: float | None = None, lon: float | None = None):
    """Comment rentrer, ou aller, quand le car ne suffit pas.

    Sans arrêts précisés, on prend ceux du trajet : de l'école vers la
    maison. C'est le cas qui sert le plus — un car manqué, ou un vendredi
    midi où aucun car ne circule.
    """
    _need_index()
    prof = profil_effectif(requete)
    depuis = depuis or prof.get("arret_ecole") or ""
    vers = vers or prof.get("arret_maison") or ""

    jour = dt.date.fromisoformat(date) if date else dt.date.today()
    if heure:
        h, m = heure.split(":")[:2]
        quand = dt.datetime.combine(jour, dt.time(int(h), int(m)))
    elif jour == dt.date.today():
        quand = dt.datetime.now()
    else:
        quand = dt.datetime.combine(jour, dt.time(0, 1))

    # Par défaut on cherche de porte à porte : depuis l'école vers la
    # maison. Partir d'une position plutôt que d'un arrêt est ce qui
    # permet de répondre quand l'arrêt habituel n'est plus desservi.
    # Le retour est le cas courant, mais le car du matin peut ne pas
    # venir : il faut alors savoir rejoindre l'école depuis la maison.
    if (sens or "").lower() in ("a", "aller"):
        pos_depuis = (prof.get("maison_lat"), prof.get("maison_lon"))
        pos_vers = (prof.get("adresse_lat"), prof.get("adresse_lon"))
        depuis, vers = prof.get("arret_maison") or "", prof.get("arret_ecole") or ""
    else:
        pos_depuis = (prof.get("adresse_lat"), prof.get("adresse_lon"))
        pos_vers = (prof.get("maison_lat"), prof.get("maison_lon"))
    if lat and lon:
        pos_depuis, depuis = (lat, lon), "ma position"

    # Le rayon découle du temps de marche accepté : la distance à vol
    # d'oiseau est majorée d'un quart dans le calcul de la marche, on
    # défait cette majoration pour retomber sur les minutes voulues.
    vitesse = int(prof.get("marche_m_min") or 80)
    minutes_max = max(2, min(30, int(prof.get("marche_max_min") or 10)))
    rayon = int(minutes_max * vitesse / 1.25)

    trouves = solutions.trajets(
        arrivee_ids=None, quand=quand,
        depart_pos=pos_depuis, arrivee_pos=pos_vers,
        vitesse=vitesse, rayon_m=rayon,
        correspondance_min=int(prof.get("correspondance_min") or 4),
        limite=max(1, min(10, limite)))

    return {
        "depuis": depuis, "vers": vers, "sens": sens or "retour",
        "date": jour.isoformat(), "a_partir_de": quand.strftime("%H:%M"),
        "marche_max_min": minutes_max,
        "trajets": trouves,
    }


@app.get("/api/resume")
def resume(requete: Request = None, date: str | None = None,
           sens: str | None = None):
    prof = profil_effectif(requete)
    jour = dt.date.fromisoformat(date) if date else dt.date.today()
    maintenant = dt.datetime.now()

    j = journee(date=jour.isoformat(), prof=prof)

    def choisir(bloc: dict) -> dict | None:
        c = bloc.get("conseille")
        if c and c.get("dans_minutes", 0) >= 0:
            return c
        futurs = [d for d in bloc.get("departs", [])
                  if d.get("dans_minutes", -1) >= 0]
        return futurs[0] if futurs else None

    aller = choisir(j.get("aller", {}))
    retour = choisir(j.get("retour", {}))

    # Le sens peut être imposé (un scénario qui ne veut que le matin) ;
    # sinon on garde celui qui a encore quelque chose, au plus tôt.
    voulu = {"a": "aller", "aller": "aller",
             "r": "retour", "retour": "retour"}.get((sens or "").lower())
    if voulu == "aller":
        prochain, sens = aller, ("aller" if aller else None)
    elif voulu == "retour":
        prochain, sens = retour, ("retour" if retour else None)
    else:
        prochain = aller or retour
        sens = "aller" if aller else ("retour" if retour else None)
        if aller and retour and retour["dans_minutes"] < aller["dans_minutes"]:
            prochain, sens = retour, "retour"

    # Le temps de marche jusqu'a l'arret, des deux cotes : la maison le
    # matin, l'ecole le soir. Sans coordonnees d'un cote, on ne l'invente
    # pas — l'heure de depart vaut alors celle du car.
    if sens == "retour":
        depuis = (prof.get("adresse_lat"), prof.get("adresse_lon"))
        arret_marche = prof.get("arret_ecole", "")
    else:
        depuis = (prof.get("maison_lat"), prof.get("maison_lon"))
        arret_marche = prof.get("arret_maison", "")
    marche = _marche_min(depuis, arret_marche, prof.get("marche_m_min"))
    # On part avec de l'avance, pas au plus juste. Le temps de marche ne
    # l'emporte que s'il dépasse cette avance — un arrêt à vingt minutes
    # ne se rejoint pas en dix.
    marge = max(0, int(prof.get("marge_depart_min") or 0))
    avance = max(marche, marge)

    depart_pied = None
    if prochain:
        h, mi = prochain["heure"].split(":")[:2]
        depart_pied = (dt.datetime.combine(jour, dt.time(int(h), int(mi)))
                       - dt.timedelta(minutes=avance))

    # Le car scolaire garde la priorité, même s'il faut l'attendre : c'est
    # celui qu'elle connaît, qui la dépose devant chez elle. On dit combien
    # de temps il faut patienter, et le repli propose autre chose à côté —
    # mais on ne lui retire pas son car.
    attente = None
    if prochain and sens == "retour":
        fin = sortie_prevue(jour, prof)
        if fin:
            attente = _mn(prochain["heure"]) - _mn(fin)
            if attente > ATTENTE_MAX_MIN:
                prochain, sens = None, None

    def descente(depart: dict | None, vers_ecole: bool):
        """Où et quand elle descend de CE car.

        Le terminus du circuit n'est pas sa destination : on cherche son
        arrêt d'arrivée sur cette même course.
        """
        if not depart:
            return None, None
        cible = prof.get("arret_ecole" if vers_ecole else "arret_maison") or ""
        jour_service = depart.get("date_service") or jour.isoformat()
        for c in fiche.courses(prof.get("ligne") or "1800", jour_service):
            if c["code"] != depart.get("parcours"):
                continue
            # Un même circuit fait plusieurs courses dans la journée : la
            # bonne est celle qui passe à cette heure-là à cet arrêt. Sans
            # ce contrôle, on lisait l'arrivée d'un autre départ — et un
            # car de 17:18 « arrivait » à 16:51.
            if not any(a["arret"] == depart.get("arret")
                       and a["heure"] == depart.get("heure")
                       for a in c["arrets"]):
                continue
            apres = depart.get("seq")
            for k, a in enumerate(c["arrets"]):
                if a["arret"] == cible and (apres is None or k > apres):
                    return a["arret"], a["heure"]
            break
        return depart.get("destination"), None

    destination, heure_arrivee = descente(prochain, sens == "aller")
    if destination is None:
        destination = (prochain or {}).get("destination")

    # Le matin, un car qui a un quart d'heure de retard ne viendra
    # probablement plus. Aucun suivi temps réel sur ce réseau ne peut le
    # confirmer, mais on peut cesser de faire attendre. Attention : dès
    # que son heure est passée, la journée bascule sur le retour, donc on
    # relit le bloc aller plutôt que le prochain départ.
    car_manque = False
    heure_car_manque = None
    sens_manque = None
    car_suivant = None
    car_saute = None
    jour_de_classe = jour.isoformat() in fiche.jours_de_classe(
        prof.get("ligne") or "1800")
    if jour == dt.date.today() and jour_de_classe:
        # Le matin comme le soir : un car dont l'heure est passée d'un
        # quart d'heure et qui n'a pas de successeur utile ne viendra plus.
        for nom, bloc, choisi in (("aller", j.get("aller", {}), aller),
                                  ("retour", j.get("retour", {}), retour)):
            recents = [d for d in bloc.get("departs", [])
                       if -FENETRE_MANQUE_MIN <= d.get("dans_minutes", 0) < 0]
            passes = [d for d in recents
                      if d.get("dans_minutes", 0) <= -RETARD_MAX_MIN]
            if choisi:
                # Un car est passé, mais il en reste un : on ne bascule pas
                # sur le réseau, on dit seulement pourquoi le compte à
                # rebours vient de s'allonger d'un coup.
                if recents:
                    car_saute = recents[-1].get("heure")
                continue
            if not passes:
                continue
            car_manque = True
            heure_car_manque = passes[-1].get("heure")
            sens_manque = nom
            # Le soir, il reste souvent un car une heure plus tard. Il ne
            # vaut pas mieux que le réseau, mais elle a le droit de savoir
            # qu'il existe.
            suivants = [d for d in bloc.get("departs", [])
                        if d.get("dans_minutes", -1) >= 0]
            car_suivant = suivants[0].get("heure") if suivants else None
            break

    # Le car parti dont l'arrivée est encore devant : elle est très
    # probablement dedans. Sans cela, la journée basculait sur le retour
    # à la seconde où le car du matin démarrait, et son écran lui parlait
    # du soir pendant qu'elle roulait vers le lycée.
    en_route = None
    if jour == dt.date.today():
        for nom, bloc in (("aller", j.get("aller", {})),
                          ("retour", j.get("retour", {}))):
            partis = [d for d in bloc.get("departs", [])
                      if -FENETRE_MANQUE_MIN <= d.get("dans_minutes", 0) < 0]
            if nom == "retour":
                # Elle ne peut être que dans un car qu'on lui a proposé. Le
                # vendredi, celui de 16:18 part quatre heures après ses
                # cours : il passe devant le lycée, mais sûrement pas avec
                # elle dedans.
                fin_j = sortie_prevue(jour, prof)
                partis = [d for d in partis if fin_j
                          and 0 <= _mn(d["heure"]) - _mn(fin_j) <= ATTENTE_MAX_MIN]
            # Plusieurs circuits partent à la même minute et tous ne
            # passent pas chez elle : on retient celui qui la dépose.
            for d in reversed(partis):
                ou, quand = descente(d, nom == "aller")
                if quand and _mn(quand) > maintenant.hour * 60 + maintenant.minute:
                    en_route = {"sens": nom, "heure": d.get("heure"),
                                "car": d.get("parcours"), "arret": d.get("arret"),
                                "destination": ou, "heure_arrivee": quand}
                    break
            if en_route:
                break

    alertes = j.get("alertes") or []

    return {
        "date": jour.isoformat(),
        "maintenant": maintenant.isoformat(timespec="seconds"),
        "sens": sens,
        "car": (prochain or {}).get("parcours"),
        "ligne": prof.get("ligne"),
        "heure_car": (prochain or {}).get("heure"),
        "heure_depart_a_pied": depart_pied.strftime("%H:%M") if depart_pied else None,
        "minutes_avant_depart": (
            int((depart_pied - maintenant).total_seconds() // 60)
            if depart_pied else None),
        "arret": (prochain or {}).get("arret"),
        "destination": destination,
        "heure_arrivee": heure_arrivee,
        "fin_cours": sortie_prevue(jour, prof),
        # D'où viennent les horaires : la fiche officielle est sûre, le
        # GTFS se trompe. Le widget le signale d'une étoile.
        "fiche_officielle": fiche.couvre(prof.get("ligne")),
        # Minutes entre la fin des cours et le car, quand elles sont trop
        # nombreuses pour que ce car compte comme une solution.
        "attente_min": attente if attente and attente > ATTENTE_MAX_MIN else None,
        # Vrai quand l'heure du car est dépassée d'un quart d'heure : il
        # est temps de proposer autre chose.
        "car_manque": car_manque,
        "heure_car_manque": heure_car_manque,
        "sens_manque": sens_manque,
        "car_suivant": car_suivant,
        "car_saute": car_saute,
        "en_route": en_route,
        "marche_min": marche,
        "avance_min": avance,
        "marge_min": marge,
        "perturbations": len(alertes),
        "jour_de_classe": jour_de_classe,
        "texte": (
            f"Car {prochain['parcours']} à {prochain['heure']} "
            f"depuis {prochain['arret']}" if prochain
            else (f"Pas de car utile : le prochain partirait "
                  f"{attente // 60} h {attente % 60:02d} après les cours"
                  if attente and attente > ATTENTE_MAX_MIN
                  else "Aucun car aujourd'hui")),
    }


# --------------------------------------------------------------------------
# Alertes poussées
# --------------------------------------------------------------------------

@app.get("/cle", response_class=PlainTextResponse)
def cle_publique():
    """Clé publique VAPID, que le navigateur exige pour s'abonner."""
    return push.cles()["publique"]


@app.post("/abonner")
def abonner(corps: dict):
    ab = corps.get("abonnement")
    if not ab or not ab.get("endpoint"):
        raise HTTPException(400, "Abonnement invalide")
    return push.abonner(ab, corps.get("preavis", 10))


@app.post("/desabonner")
def desabonner(corps: dict):
    push.desabonner(corps.get("endpoint", ""))
    return {"ok": True}


@app.get("/api/alertes-push/etat")
def etat_push():
    return push.etat()


@app.post("/api/alertes-push/test")
def test_push():
    """Envoie une notification tout de suite, pour vérifier la chaîne."""
    envoyes = 0
    for a in push.abonnes():
        if push.envoyer(a["abonnement"], "Essai",
                        "Si vous lisez ceci, les alertes fonctionnent."):
            envoyes += 1
    return {"envoyes": envoyes, "abonnes": len(push.abonnes())}


@app.get("/")
def index():
    """Le démon ne sert que l'API : les pages viennent du plugin Jeedom."""
    return {"service": "car", "version": app.version}
