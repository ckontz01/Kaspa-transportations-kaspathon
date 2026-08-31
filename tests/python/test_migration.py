import json
from datetime import datetime, timezone
from pathlib import Path

from scripts.migrate_legacy_normal_rides import _documents_by_collection


FIXTURE = Path(__file__).parents[1] / "fixtures" / "legacy-normal-rides.snapshot.json"


def test_legacy_snapshot_maps_only_claimable_normal_ride_history() -> None:
    snapshot = json.loads(FIXTURE.read_text(encoding="utf-8"))
    documents = _documents_by_collection(
        snapshot, imported_at=datetime(2026, 8, 31, tzinfo=timezone.utc)
    )

    assert [item["legacyUserId"] for item in documents["legacy_identities"]] == [7, 8]
    assert documents["legacy_wallet_links"][0]["address"] == "kaspatest:qqexample"
    assert documents["legacy_rides"][0]["driverUserIds"] == [8]
    assert documents["legacy_payments"][0]["legacyPaymentId"] == 29
    assert all(
        "password" not in json.dumps(item, default=str).lower()
        for items in documents.values()
        for item in items
    )


def test_legacy_snapshot_format_is_fail_closed() -> None:
    try:
        _documents_by_collection(
            {"format": "unknown"}, imported_at=datetime.now(timezone.utc)
        )
    except ValueError as exc:
        assert "Unsupported" in str(exc)
    else:
        raise AssertionError("unknown snapshot format should be rejected")
