from __future__ import annotations

import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
LEGACY = ROOT / "OSRH_KASPA_PHP"
TARGET = ROOT / "src" / "app" / "legacy-inline.css"
PAGES = [
    "index.php",
    "login.php",
    "profile.php",
    "passenger/dashboard.php",
    "passenger/request_ride.php",
    "passenger/request_status.php",
    "passenger/ride_detail.php",
    "passenger/rides_history.php",
    "passenger/payments.php",
    "passenger/messages.php",
    "passenger/settings.php",
    "passenger/gdpr_request.php",
    "driver/dashboard.php",
    "driver/ride_request_detail.php",
    "driver/trips_assigned.php",
    "driver/trip_detail.php",
    "driver/vehicles.php",
    "driver/earnings.php",
    "driver/messages.php",
    "driver/settings.php",
    "driver/upload_documents.php",
]


def main() -> None:
    sections: list[str] = [
        "/* Generated from the legacy PHP inline styles to preserve the original OSRH interface. */"
    ]
    skipped = 0
    for relative in PAGES:
        source = LEGACY / relative
        text = source.read_text(encoding="utf-8-sig")
        for index, block in enumerate(re.findall(r"<style[^>]*>(.*?)</style>", text, re.S | re.I), 1):
            if "<?php" in block:
                skipped += 1
                continue
            sections.append(f"\n/* {relative} · block {index} */\n{block.strip()}\n")
    TARGET.write_text("\n".join(sections), encoding="utf-8")
    print(f"wrote {TARGET.relative_to(ROOT)} ({len(sections) - 1} blocks; {skipped} skipped)")


if __name__ == "__main__":
    main()
