from __future__ import annotations

import pprint
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "Database" / "OSRH_kaspa_seeding.sql"
TARGET = ROOT / "backend" / "geofences.py"


def main() -> None:
    sql = SOURCE.read_text(encoding="utf-8-sig")
    coordinate_pattern = re.compile(
        r"\(@(?P<name>[A-Za-z]+)GeofenceID,\s*\d+,\s*"
        r"(?P<lat>-?\d+(?:\.\d+)?),\s*(?P<lng>-?\d+(?:\.\d+)?)\)"
    )
    grouped: dict[str, list[dict[str, float]]] = {}
    for match in coordinate_pattern.finditer(sql):
        grouped.setdefault(match.group("name"), []).append(
            {"lat": float(match.group("lat")), "lng": float(match.group("lng"))}
        )

    geofences = [
        {
            "name": f"{name}_District",
            "description": f"{name} District",
            "points": points,
        }
        for name, points in grouped.items()
    ]
    bridges = [
        {
            "name": "Nicosia_Larnaca_Bridge",
            "lat": 35.0034,
            "lng": 33.4499,
            "connects": "Nicosia ↔ Larnaca",
        },
        {
            "name": "Limassol_Nicosia_Bridge",
            "lat": 35.0100,
            "lng": 33.1500,
            "connects": "Limassol ↔ Nicosia",
        },
        {
            "name": "Limassol_Larnaca_Bridge",
            "lat": 34.8000,
            "lng": 33.3350,
            "connects": "Limassol ↔ Larnaca",
        },
        {
            "name": "Paphos_Limassol_Bridge",
            "lat": 34.8500,
            "lng": 32.7500,
            "connects": "Paphos ↔ Limassol",
        },
        {
            "name": "Paphos_Nicosia_Bridge",
            "lat": 35.0350,
            "lng": 32.6400,
            "connects": "Paphos ↔ Nicosia",
        },
    ]
    content = (
        '"""Generated from Database/OSRH_kaspa_seeding.sql; do not edit by hand."""\n\n'
        f"GEOFENCES = {pprint.pformat(geofences, width=100, sort_dicts=False)}\n\n"
        f"BRIDGES = {pprint.pformat(bridges, width=100, sort_dicts=False)}\n"
    )
    TARGET.write_text(content, encoding="utf-8")
    print(
        f"wrote {TARGET.relative_to(ROOT)} with {len(geofences)} geofences and "
        f"{sum(len(item['points']) for item in geofences)} points"
    )


if __name__ == "__main__":
    main()
