"""Temps reel : alertes trafic (GTFS-RT) et passages a l'arret (SIRI).

Deux sources, deux niveaux de disponibilite :

* Les alertes GTFS-RT de la Metropole fonctionnent aujourd'hui, sans cle.
* Le flux SIRI StopMonitoring est ouvert mais les centres operateurs ne
  publient pas encore leurs seuils. L'adaptateur est ecrit et teste : il
  s'activera de lui-meme le jour ou le flux repondra, sans rien changer ici.
"""
from __future__ import annotations

import datetime as dt
import re
import time
import xml.etree.ElementTree as ET

import httpx
from google.transit import gtfs_realtime_pb2

from . import config

_cache: dict[str, tuple[float, object]] = {}


def _cached(key: str, ttl: int, producer):
    hit = _cache.get(key)
    if hit and time.time() - hit[0] < ttl:
        return hit[1]
    value = producer()
    _cache[key] = (time.time(), value)
    return value


# --------------------------------------------------------------------------
# Alertes trafic (GTFS-RT Service Alerts) -- operationnel
# --------------------------------------------------------------------------

def _pick_text(container) -> str:
    """Prend la version francaise d'un texte traduit, sinon la premiere."""
    best = ""
    for tr in container.translation:
        if tr.language.lower().startswith("fr"):
            return tr.text.strip()
        best = best or tr.text.strip()
    return best


def _clean(txt: str) -> str:
    txt = re.sub(r"<br\s*/?>", "\n", txt or "", flags=re.I)
    txt = re.sub(r"<[^>]+>", "", txt)
    return re.sub(r"\n{3,}", "\n\n", txt).strip()


def fetch_alerts() -> list[dict]:
    """Toutes les alertes en cours sur le reseau metropolitain."""
    def go():
        try:
            r = httpx.get(config.ALERTS_URL, timeout=20.0)
            r.raise_for_status()
        except httpx.HTTPError as e:
            return {"erreur": str(e), "alertes": []}
        feed = gtfs_realtime_pb2.FeedMessage()
        try:
            feed.ParseFromString(r.content)
        except Exception as e:  # protobuf malforme
            return {"erreur": f"flux illisible: {e}", "alertes": []}
        now = int(time.time())
        out = []
        for ent in feed.entity:
            if not ent.HasField("alert"):
                continue
            a = ent.alert
            periods = [(p.start or 0, p.end or 0) for p in a.active_period]
            if periods and not any(
                (s == 0 or s <= now) and (e == 0 or e >= now) for s, e in periods
            ):
                continue
            lignes, arrets = set(), set()
            for inf in a.informed_entity:
                if inf.route_id:
                    lignes.add(inf.route_id)
                if inf.stop_id:
                    arrets.add(inf.stop_id)
            out.append({
                "id": ent.id,
                "titre": _clean(_pick_text(a.header_text)),
                "texte": _clean(_pick_text(a.description_text)),
                "effet": gtfs_realtime_pb2.Alert.Effect.Name(a.effect)
                         if a.effect else "UNKNOWN_EFFECT",
                "cause": gtfs_realtime_pb2.Alert.Cause.Name(a.cause)
                         if a.cause else "UNKNOWN_CAUSE",
                "lignes": sorted(lignes),
                "arrets": sorted(arrets),
                "debut": _iso(periods[0][0]) if periods and periods[0][0] else None,
                "fin": _iso(periods[0][1]) if periods and periods[0][1] else None,
            })
        return {"erreur": None, "alertes": out,
                "maj": dt.datetime.now().isoformat(timespec="seconds")}

    return _cached("alerts", config.ALERTS_TTL, go)


def _iso(ts: int) -> str:
    return dt.datetime.fromtimestamp(ts).isoformat(timespec="minutes")


GRAVITE = {
    "NO_SERVICE": 3, "REDUCED_SERVICE": 2, "SIGNIFICANT_DELAYS": 2,
    "DETOUR": 2, "STOP_MOVED": 2, "MODIFIED_SERVICE": 1,
    "ADDITIONAL_SERVICE": 0, "OTHER_EFFECT": 1, "UNKNOWN_EFFECT": 1,
}


def _numero(ref: str) -> str:
    """'AIX-03' -> '3', 'PAM-1800' -> '1800', '170' -> '170'."""
    tail = ref.rsplit("-", 1)[-1] if "-" in ref else ref
    tail = re.sub(r"(SP|BIS)$", "", tail, flags=re.I)
    return tail.lstrip("0") or tail


def alerts_for(lignes: set[str], arrets: set[str]) -> list[dict]:
    """Filtre les alertes concernant les lignes / arrets suivis.

    Deux subtilites du flux metropolitain :
    * les lignes y sont nommees par leur identifiant reseau (AIX-03) et non
      par le route_id du GTFS, d'ou la comparaison sur le numero nu ;
    * les arrets y sont des identifiants composites qui agregent plusieurs
      points d'arret (AST-MAMP-15480-0613000-MAMP-15481-...), d'ou la
      recherche par inclusion plutot que par egalite.
    """
    data = fetch_alerts()
    if data.get("erreur"):
        return []
    nus = {_numero(l) for l in lignes}
    nus.discard("")
    keep = []
    for a in data["alertes"]:
        touche_ligne = bool({_numero(l) for l in a["lignes"]} & nus)
        touche_arret = any(mien in ref for ref in a["arrets"] for mien in arrets)
        # Une alerte sans ligne ni arret concerne tout le reseau : on la garde.
        globale = not a["lignes"] and not a["arrets"]
        if touche_ligne or touche_arret or globale:
            keep.append(a)
    keep.sort(key=lambda a: -GRAVITE.get(a["effet"], 1))
    return keep


# --------------------------------------------------------------------------
# Passages a l'arret (SIRI StopMonitoring) -- en attente cote operateurs
# --------------------------------------------------------------------------

SIRI_TEMPLATE = """<?xml version="1.0" encoding="UTF-8"?>
<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">
<S:Body>
<sw:GetStopMonitoring xmlns:sw="http://wsdl.siri.org.uk"
                      xmlns:siri="http://www.siri.org.uk/siri">
<ServiceRequestInfo>
<siri:RequestTimestamp>{ts}</siri:RequestTimestamp>
<siri:RequestorRef>{ref}</siri:RequestorRef>
<siri:MessageIdentifier>{mid}</siri:MessageIdentifier>
</ServiceRequestInfo>
<Request version="2.0">
<siri:RequestTimestamp>{ts}</siri:RequestTimestamp>
<siri:MessageIdentifier>{mid}</siri:MessageIdentifier>
<siri:PreviewInterval>PT{horizon}M</siri:PreviewInterval>
<siri:MonitoringRef>{stop}</siri:MonitoringRef>
<siri:StopVisitTypes>all</siri:StopVisitTypes>
<siri:MaximumStopVisits>{maxi}</siri:MaximumStopVisits>
<siri:StopMonitoringDetailLevel>normal</siri:StopMonitoringDetailLevel>
</Request>
<RequestExtension/>
</sw:GetStopMonitoring>
</S:Body>
</S:Envelope>"""

SIRI_NS = "{http://www.siri.org.uk/siri}"


def siri_stop(stop_id: str, horizon_min: int = 90, maxi: int = 10) -> dict:
    """Interroge tous les centres SIRI pour un arret.

    Renvoie {'disponible': bool, 'passages': [...], 'diagnostic': {...}}.
    """
    if not config.SIRI_ENABLED:
        return {"disponible": False, "passages": [], "diagnostic": {"etat": "desactive"}}

    def go():
        passages, diag = [], {}
        body_common = dict(
            ts=dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.000Z"),
            ref=config.SIRI_REQUESTOR_REF, mid=f"be-{int(time.time())}",
            horizon=horizon_min, stop=stop_id, maxi=maxi)
        for ep in config.SIRI_ENDPOINTS:
            xml = SIRI_TEMPLATE.format(**body_common)
            try:
                r = httpx.post(f"{config.SIRI_BASE}/{ep}", content=xml.encode(),
                               headers={"Content-Type": "text/xml; charset=utf-8",
                                        "SOAPAction": '"GetStopMonitoring"'},
                               timeout=12.0)
                r.raise_for_status()
            except httpx.HTTPError as e:
                diag[ep] = f"injoignable: {e.__class__.__name__}"
                continue
            try:
                root = ET.fromstring(r.text)
            except ET.ParseError:
                diag[ep] = "reponse illisible"
                continue
            err = root.find(f".//{SIRI_NS}ErrorText")
            if err is not None and (err.text or "").strip():
                diag[ep] = (err.text or "").strip()
                continue
            found = 0
            for visit in root.iter(f"{SIRI_NS}MonitoredStopVisit"):
                p = _parse_visit(visit)
                if p:
                    passages.append(p)
                    found += 1
            diag[ep] = f"{found} passage(s)"
        passages.sort(key=lambda p: p["heure"])
        return {"disponible": bool(passages), "passages": passages,
                "diagnostic": diag}

    return _cached(f"siri:{stop_id}", 30, go)


def _parse_visit(visit) -> dict | None:
    def txt(path):
        el = visit.find(path)
        return (el.text or "").strip() if el is not None and el.text else None

    j = f"{SIRI_NS}MonitoredVehicleJourney/"
    call = j + f"{SIRI_NS}MonitoredCall/"
    heure = (txt(call + f"{SIRI_NS}ExpectedDepartureTime")
             or txt(call + f"{SIRI_NS}ExpectedArrivalTime")
             or txt(call + f"{SIRI_NS}AimedDepartureTime"))
    if not heure:
        return None
    prevu = txt(call + f"{SIRI_NS}AimedDepartureTime")
    retard = None
    if prevu and heure and prevu != heure:
        try:
            retard = round((dt.datetime.fromisoformat(heure)
                            - dt.datetime.fromisoformat(prevu)).total_seconds() / 60)
        except ValueError:
            retard = None
    return {
        "heure": heure,
        "heure_prevue": prevu,
        "retard_minutes": retard,
        "ligne": txt(j + f"{SIRI_NS}PublishedLineName")
                 or txt(j + f"{SIRI_NS}LineRef"),
        "destination": txt(j + f"{SIRI_NS}DestinationName"),
        "a_quai": txt(call + f"{SIRI_NS}VehicleAtStop") == "true",
        "temps_reel": True,
    }


def siri_status() -> dict:
    """Etat du flux temps reel, pour l'affichage honnete dans l'interface."""
    probe = siri_stop("MAMP-SUMA-P22831", horizon_min=60, maxi=5)
    return {
        "actif": probe["disponible"],
        "diagnostic": probe["diagnostic"],
        "endpoints": config.SIRI_ENDPOINTS,
    }
