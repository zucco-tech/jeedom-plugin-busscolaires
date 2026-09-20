"""Indexation et interrogation du GTFS officiel Pays d'Aix Mobilite.

Le fichier stop_times.txt fait ~136 Mo (1,7 M lignes) : on le transforme une
fois pour toutes en base SQLite indexee, en ne gardant que les courses qui
desservent les communes configurees.
"""
from __future__ import annotations

import csv
import datetime as dt
import io
import sqlite3
import unicodedata
import urllib.request
import zipfile
from typing import Iterable

from . import config

SCHEMA = """
PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS stops(
    stop_id TEXT PRIMARY KEY, name TEXT, city TEXT,
    lat REAL, lon REAL, parent TEXT, norm TEXT);
CREATE TABLE IF NOT EXISTS routes(
    route_id TEXT PRIMARY KEY, short_name TEXT, long_name TEXT,
    color TEXT, text_color TEXT);
CREATE TABLE IF NOT EXISTS trips(
    trip_id TEXT PRIMARY KEY, route_id TEXT, service_id TEXT,
    headsign TEXT, direction_id INTEGER, parcours TEXT, sens TEXT);
CREATE TABLE IF NOT EXISTS stop_times(
    trip_id TEXT, seq INTEGER, stop_id TEXT, arr INTEGER, dep INTEGER);
CREATE TABLE IF NOT EXISTS service_dates(service_id TEXT, date TEXT);
CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY, value TEXT);
"""

INDEXES = """
CREATE INDEX IF NOT EXISTS ix_st_stop ON stop_times(stop_id, dep);
CREATE INDEX IF NOT EXISTS ix_st_trip ON stop_times(trip_id, seq);
CREATE INDEX IF NOT EXISTS ix_trips_route ON trips(route_id);
CREATE INDEX IF NOT EXISTS ix_trips_parcours ON trips(parcours);
CREATE INDEX IF NOT EXISTS ix_sd_date ON service_dates(date, service_id);
CREATE INDEX IF NOT EXISTS ix_stops_norm ON stops(norm);
"""


def norm(s: str) -> str:
    """Minuscule sans accent, pour comparer 'Greasque' et 'Gréasque'."""
    s = unicodedata.normalize("NFD", s or "")
    return "".join(c for c in s if unicodedata.category(c) != "Mn").lower().strip()


def hms_to_sec(v: str) -> int | None:
    if not v:
        return None
    p = v.split(":")
    if len(p) != 3:
        return None
    return int(p[0]) * 3600 + int(p[1]) * 60 + int(p[2])


def sec_to_hms(sec: int) -> str:
    """Secondes depuis minuit -> 'HH:MM'. 25:10 devient 01:10 (lendemain)."""
    return f"{(sec // 3600) % 24:02d}:{(sec % 3600) // 60:02d}"


def connect() -> sqlite3.Connection:
    con = sqlite3.connect(config.DB_PATH, check_same_thread=False)
    con.row_factory = sqlite3.Row
    return con


# --------------------------------------------------------------------------
# Construction de l'index
# --------------------------------------------------------------------------

def _reader(zf: zipfile.ZipFile, name: str):
    with zf.open(name) as fh:
        yield from csv.DictReader(io.TextIOWrapper(fh, encoding="utf-8-sig"))


def build(progress=print) -> dict:
    """Telecharge les GTFS et reconstruit la base SQLite. Renvoie des stats."""
    global _pret
    _pret = False          # l'index change : on ne se fie plus au souvenir
    tmp = config.DB_PATH.with_suffix(".building")
    tmp.unlink(missing_ok=True)
    con = sqlite3.connect(tmp)
    con.executescript(SCHEMA)
    stats: dict[str, int | str] = {}
    wanted_cities = {norm(c) for c in config.COMMUNES}

    for flux in config.FLUX:
        progress(f"Telechargement du GTFS {flux['nom']}...")
        req = urllib.request.Request(flux["url"],
                                     headers={"User-Agent": "bus-ecole/1.0"})
        with urllib.request.urlopen(req, timeout=180) as r:
            raw = r.read()
        progress(f"  {len(raw) / 1e6:.1f} Mo telecharges")
        _ingerer(con, raw, wanted_cities, stats, progress)

    con.executescript(INDEXES)
    con.execute("INSERT OR REPLACE INTO meta VALUES('built_at', ?)",
                (dt.datetime.now().isoformat(timespec="seconds"),))
    con.execute("INSERT OR REPLACE INTO meta VALUES('sources', ?)",
                (", ".join(f["nom"] for f in config.FLUX),))
    con.commit()
    con.close()
    tmp.replace(config.DB_PATH)
    for suffix in ("-wal", "-shm"):
        p = tmp.with_name(tmp.name + suffix)
        p.unlink(missing_ok=True)
    progress("Index pret.")
    stats["built_at"] = dt.datetime.now().isoformat(timespec="seconds")
    return stats


def _ingerer(con, raw: bytes, wanted_cities: set, stats: dict, progress) -> None:
    """Verse un flux GTFS dans la base ouverte."""
    with zipfile.ZipFile(io.BytesIO(raw)) as zf:
        names = set(zf.namelist())

        progress("Arrets...")
        stop_rows, keep_stops = [], set()
        for r in _reader(zf, "stops.txt"):
            city = (r.get("city_name") or "").strip()
            stop_rows.append((
                r["stop_id"], (r.get("stop_name") or "").strip(), city,
                float(r["stop_lat"]) if r.get("stop_lat") else None,
                float(r["stop_lon"]) if r.get("stop_lon") else None,
                (r.get("parent_station") or "") or None,
                norm(r.get("stop_name", "")),
            ))
            if norm(city) in wanted_cities:
                keep_stops.add(r["stop_id"])
        con.executemany("INSERT OR REPLACE INTO stops VALUES(?,?,?,?,?,?,?)", stop_rows)
        stats["stops"] = stats.get("stops", 0) + len(stop_rows)
        stats["stops_zone"] = stats.get("stops_zone", 0) + len(keep_stops)
        progress(f"  {len(stop_rows)} arrets, dont {len(keep_stops)} dans la zone suivie")

        progress("Lignes et courses...")
        con.executemany(
            "INSERT OR REPLACE INTO routes VALUES(?,?,?,?,?)",
            [(r["route_id"], (r.get("route_short_name") or "").strip(),
              (r.get("route_long_name") or "").strip(),
              (r.get("route_color") or "").strip() or "3b6ea5",
              (r.get("route_text_color") or "").strip() or "ffffff")
             for r in _reader(zf, "routes.txt")],
        )
        trips = {}
        for r in _reader(zf, "trips.txt"):
            trips[r["trip_id"]] = (
                r["trip_id"], r["route_id"], r["service_id"],
                (r.get("trip_headsign") or "").strip(),
                int(r["direction_id"]) if (r.get("direction_id") or "").isdigit() else None,
                (r.get("ext_id_parcours") or "").strip(),
                (r.get("ext_sens_bill") or "").strip(),
            )
        progress(f"  {len(trips)} courses au total")

        # Passe 1 : quelles courses touchent la zone ? Passe 2 : on les stocke
        # entierement, pour garder les itineraires complets.
        progress("Horaires (passe 1/2 : selection)...")
        keep_trips = set()
        for r in _reader(zf, "stop_times.txt"):
            if r["stop_id"] in keep_stops:
                keep_trips.add(r["trip_id"])
        progress(f"  {len(keep_trips)} courses retenues")

        progress("Horaires (passe 2/2 : indexation)...")
        batch, total = [], 0
        for r in _reader(zf, "stop_times.txt"):
            if r["trip_id"] not in keep_trips:
                continue
            batch.append((r["trip_id"], int(r["stop_sequence"]), r["stop_id"],
                          hms_to_sec(r.get("arrival_time", "")),
                          hms_to_sec(r.get("departure_time", ""))))
            if len(batch) >= 50_000:
                con.executemany("INSERT INTO stop_times VALUES(?,?,?,?,?)", batch)
                total += len(batch)
                batch.clear()
        if batch:
            con.executemany("INSERT INTO stop_times VALUES(?,?,?,?,?)", batch)
            total += len(batch)
        stats["stop_times"] = stats.get("stop_times", 0) + total
        progress(f"  {total} horaires indexes")

        con.executemany("INSERT OR REPLACE INTO trips VALUES(?,?,?,?,?,?,?)",
                        [trips[t] for t in keep_trips if t in trips])
        stats["trips"] = stats.get("trips", 0) + len(keep_trips)

        progress("Calendrier...")
        dates: list[tuple[str, str]] = []
        if "calendar.txt" in names:
            wd = ["monday", "tuesday", "wednesday", "thursday", "friday",
                  "saturday", "sunday"]
            for r in _reader(zf, "calendar.txt"):
                start = dt.date.fromisoformat(_iso(r["start_date"]))
                end = dt.date.fromisoformat(_iso(r["end_date"]))
                active = [i for i, d in enumerate(wd) if r.get(d) == "1"]
                if not active:
                    continue
                day = start
                while day <= end:
                    if day.weekday() in active:
                        dates.append((r["service_id"], day.isoformat()))
                    day += dt.timedelta(days=1)
        if "calendar_dates.txt" in names:
            removed = set()
            for r in _reader(zf, "calendar_dates.txt"):
                key = (r["service_id"], _iso(r["date"]))
                if r["exception_type"] == "1":
                    dates.append(key)
                else:
                    removed.add(key)
            if removed:
                dates = [d for d in dates if d not in removed]
        # Certains operateurs nomment leurs services par date (PAM-SUMA-20260908)
        # sans les declarer au calendrier : on les rattache a leur date.
        known = {s for s, _ in dates}
        for tid in keep_trips:
            sid = trips[tid][2] if tid in trips else None
            if sid and sid not in known:
                tail = sid.rsplit("-", 1)[-1]
                if len(tail) == 8 and tail.isdigit():
                    dates.append((sid, _iso(tail)))
                    known.add(sid)
        con.executemany("INSERT INTO service_dates VALUES(?,?)", sorted(set(dates)))
        stats["service_dates"] = stats.get("service_dates", 0) + len(set(dates))
        progress(f"  {len(set(dates))} jours de service")




def _iso(yyyymmdd: str) -> str:
    return f"{yyyymmdd[:4]}-{yyyymmdd[4:6]}-{yyyymmdd[6:8]}"


# L'état de l'index est consulté à chaque résolution de nom d'arrêt, soit
# des centaines de fois par requête : le contrôle doit être gratuit. On le
# retient dès qu'il est vrai, et build() le remet à zéro.
_pret = False


def is_ready() -> bool:
    global _pret
    if _pret:
        return True
    if not config.DB_PATH.exists():
        return False
    try:
        con = connect()
        try:
            # « Y a-t-il au moins une ligne » et non « combien » : compter
            # 1,7 million de lignes pour cela coûtait 30 ms par appel.
            trouve = con.execute(
                "SELECT 1 FROM stop_times LIMIT 1").fetchone() is not None
        finally:
            con.close()
    except sqlite3.Error:
        return False
    _pret = trouve
    return trouve
