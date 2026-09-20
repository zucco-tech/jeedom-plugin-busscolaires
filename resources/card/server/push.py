"""Alertes poussées : prévenir avant le départ, application fermée.

Le rappel de la page ne sonne que si elle reste ouverte. Pour être
prévenue sans l'ouvrir, il faut qu'un serveur envoie la notification :
c'est le rôle de ce module. Il garde les abonnements, calcule le prochain
départ du trajet suivi et pousse l'alerte à l'heure dite.

Le serveur doit être joignable depuis le téléphone : sur le réseau de la
maison, ou exposé sur Internet. Sinon, seul le rappel local fonctionne.
"""
from __future__ import annotations

import base64
import datetime as dt
import json
import threading
import time

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import ec
from pywebpush import WebPushException, webpush

from . import config

CLES = config.DATA_DIR / "vapid.json"
ABONNES = config.DATA_DIR / "abonnes.json"
CONTACT = "mailto:" + config.__dict__.get("CONTACT", "bus-ecole@localhost")


def _b64(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).decode().rstrip("=")


def cles() -> dict:
    """Paire de clés VAPID, créée au premier appel et conservée."""
    if CLES.exists():
        return json.loads(CLES.read_text())
    k = ec.generate_private_key(ec.SECP256R1())
    prive = k.private_bytes(serialization.Encoding.DER,
                            serialization.PrivateFormat.PKCS8,
                            serialization.NoEncryption())
    public = k.public_key().public_bytes(
        serialization.Encoding.X962,
        serialization.PublicFormat.UncompressedPoint)
    d = {"prive": _b64(prive), "publique": _b64(public)}
    CLES.write_text(json.dumps(d))
    CLES.chmod(0o600)
    return d


def _pem_prive() -> str:
    d = cles()
    brut = base64.urlsafe_b64decode(d["prive"] + "=" * (-len(d["prive"]) % 4))
    k = serialization.load_der_private_key(brut, password=None)
    return k.private_bytes(serialization.Encoding.PEM,
                           serialization.PrivateFormat.PKCS8,
                           serialization.NoEncryption()).decode()


def abonnes() -> list[dict]:
    if not ABONNES.exists():
        return []
    try:
        return json.loads(ABONNES.read_text())
    except json.JSONDecodeError:
        return []


def abonner(abonnement: dict, preavis: int) -> dict:
    liste = [a for a in abonnes()
             if a["abonnement"].get("endpoint") != abonnement.get("endpoint")]
    liste.append({"abonnement": abonnement, "preavis": max(1, int(preavis)),
                  "depuis": dt.datetime.now().isoformat(timespec="seconds"),
                  "envoyes": []})
    ABONNES.write_text(json.dumps(liste, ensure_ascii=False, indent=1))
    return {"abonnes": len(liste)}


def desabonner(endpoint: str) -> None:
    liste = [a for a in abonnes()
             if a["abonnement"].get("endpoint") != endpoint]
    ABONNES.write_text(json.dumps(liste, ensure_ascii=False, indent=1))


def envoyer(abonnement: dict, titre: str, corps: str) -> bool:
    try:
        webpush(subscription_info=abonnement,
                data=json.dumps({"titre": titre, "corps": corps}),
                vapid_private_key=_pem_prive(),
                vapid_claims={"sub": CONTACT})
        return True
    except WebPushException as e:
        # 404 / 410 : l'abonnement n'existe plus cote navigateur
        if e.response is not None and e.response.status_code in (404, 410):
            desabonner(abonnement.get("endpoint", ""))
        return False


# --------------------------------------------------------------------------
# La boucle qui surveille l'heure
# --------------------------------------------------------------------------

_etat = {"tourne": False, "dernier": None, "envois": 0}


def prochain_depart() -> tuple[dt.datetime, str] | None:
    """Prochain départ du trajet suivi, temps de marche compris.

    Les réglages désignent les arrêts par leur nom : on les résout ici,
    pour rester d'accord avec ce qu'affiche l'application.
    """
    from . import app as application  # import tardif : evite la boucle
    from . import board, fiche

    prof = application.load_profile()
    ligne = prof.get("ligne") or "1800"
    vitesse = max(40, int(prof.get("marche_m_min") or 80))

    def arret_id(nom: str) -> str | None:
        p = fiche.situe(nom) if nom else None
        return p["stop_id"] if p else None

    def marche_min(depuis: str, vers_lon, vers_lat) -> int:
        """Minutes de marche entre un arrêt et un point, majorées de 25 %."""
        p = fiche.situe(depuis) if depuis else None
        if not p or vers_lon is None or p["lat"] is None:
            return 0
        import math
        r = math.pi / 180
        dla = (vers_lat - p["lat"]) * r
        dlo = (vers_lon - p["lon"]) * r
        a = (math.sin(dla / 2) ** 2 + math.cos(p["lat"] * r)
             * math.cos(vers_lat * r) * math.sin(dlo / 2) ** 2)
        m = 6371000 * 2 * math.asin(math.sqrt(a)) * 1.25
        return max(0, math.ceil(m / vitesse))

    # Le matin, elle part de chez elle : la marche jusqu'à l'arrêt n'est pas
    # connue du serveur. Le soir, elle part du collège : on compte la marche
    # jusqu'à l'arrêt d'Aix.
    marche_soir = marche_min(prof.get("arret_ecole"),
                             prof.get("adresse_lon"), prof.get("adresse_lat"))

    maintenant = dt.datetime.now()
    for delta in range(0, 8):
        jour = (maintenant + dt.timedelta(days=delta)).date()
        for sens, nom, marche in (("A", prof.get("arret_maison"), 0),
                                  ("R", prof.get("arret_ecole"), marche_soir)):
            sid = arret_id(nom)
            if not sid:
                continue
            deps = board.departures(
                [sid], when=dt.datetime.combine(jour, dt.time(0, 0)),
                horizon_min=1440, limit=30, route_short=ligne)
            for d in deps:
                if d.get("sens") and d["sens"] != sens:
                    continue
                h, m = d["heure"].split(":")[:2]
                quand = dt.datetime.combine(jour, dt.time(int(h), int(m)))
                quand -= dt.timedelta(minutes=marche)
                if quand > maintenant:
                    ou = "la maison" if sens == "A" else "le collège"
                    return quand, (f"Car {d['parcours']} à {d['heure']} "
                                   f"depuis {d['arret']} — quitter {ou}")
    return None


def _boucle() -> None:
    while _etat["tourne"]:
        try:
            liste = abonnes()
            if liste:
                suite = prochain_depart()
                if suite:
                    quand, quoi = suite
                    reste = (quand - dt.datetime.now()).total_seconds() / 60
                    cle_jour = quand.isoformat(timespec="minutes")
                    for a in liste:
                        if cle_jour in a.get("envoyes", []):
                            continue
                        if 0 < reste <= a["preavis"]:
                            ok = envoyer(a["abonnement"], "Il faut partir",
                                         f"{quoi} — départ à pied dans "
                                         f"{int(reste)} min.")
                            if ok:
                                a.setdefault("envoyes", []).append(cle_jour)
                                a["envoyes"] = a["envoyes"][-20:]
                                _etat["envois"] += 1
                    ABONNES.write_text(json.dumps(liste, ensure_ascii=False,
                                                  indent=1))
                _etat["dernier"] = dt.datetime.now().isoformat(timespec="seconds")
        except Exception as e:                      # la boucle ne doit pas mourir
            _etat["erreur"] = str(e)
        time.sleep(30)


def demarrer() -> None:
    if _etat["tourne"]:
        return
    _etat["tourne"] = True
    threading.Thread(target=_boucle, daemon=True).start()


def etat() -> dict:
    suite = None
    try:
        p = prochain_depart()
        suite = {"quand": p[0].isoformat(timespec="minutes"), "quoi": p[1]} if p else None
    except Exception as e:
        suite = {"erreur": str(e)}
    return {"abonnes": len(abonnes()), "boucle": _etat["tourne"],
            "dernier_controle": _etat["dernier"], "envois": _etat["envois"],
            "prochain_depart": suite,
            "note": "Le serveur doit être joignable depuis le téléphone."}
