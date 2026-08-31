from __future__ import annotations

import argparse
from typing import Any

from pymongo.database import Database

from backend.accounts import normalize_email
from backend.db import get_database, utcnow


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Promote an existing OSRH account without exposing a web endpoint."
    )
    parser.add_argument(
        "--email", required=True, help="Existing registered account email"
    )
    parser.add_argument(
        "--role",
        choices=("operator", "admin"),
        default="operator",
        help="Privileged role to assign (default: operator)",
    )
    parser.add_argument(
        "--yes",
        action="store_true",
        help="Skip the interactive email confirmation",
    )
    return parser.parse_args()


def promote_account(
    db: Database[dict[str, Any]], email: str, role: str
) -> tuple[str, str]:
    normalized = normalize_email(email)

    def transaction(session: Any) -> tuple[str, str]:
        account = db.accounts.find_one(
            {"emailNormalized": normalized},
            {"role": 1, "fullName": 1},
            session=session,
        )
        if account is None:
            raise LookupError("No registered OSRH account matches that email")

        previous_role = str(account.get("role") or "passenger")
        if previous_role == role:
            return previous_role, previous_role

        now = utcnow()
        update = db.accounts.update_one(
            {"_id": account["_id"], "role": previous_role},
            {"$set": {"role": role, "updatedAt": now}},
            session=session,
        )
        if update.modified_count != 1:
            raise RuntimeError(
                "The account role changed concurrently; retry after review"
            )

        db.system_logs.insert_one(
            {
                "createdAt": now,
                "actorId": account["_id"],
                "actorName": "Local operations bootstrap",
                "actorRole": "system",
                "actionType": "Account Role Bootstrap",
                "actionDescription": (
                    "Changed the registered account role from "
                    f"{previous_role} to {role}"
                ),
                "status": "completed",
                "severity": "warning",
                "referenceType": "account",
                "referenceId": str(account["_id"]),
                "metadata": {"previousRole": previous_role, "newRole": role},
            },
            session=session,
        )
        return previous_role, role

    with db.client.start_session() as session:
        return session.with_transaction(transaction)


def main() -> int:
    args = parse_args()
    normalized = normalize_email(args.email)
    if not args.yes:
        confirmation = input(
            f"Assign {args.role!r} to {normalized!r}? Type the email to confirm: "
        )
        if normalize_email(confirmation) != normalized:
            print("Confirmation did not match; no changes made.")
            return 1

    try:
        previous_role, assigned_role = promote_account(
            get_database(), normalized, args.role
        )
    except (LookupError, RuntimeError) as exc:
        print(f"Role update failed: {exc}")
        return 1

    if previous_role == assigned_role:
        print(f"Account already has the {assigned_role} role; no changes made.")
    else:
        print(f"Role changed from {previous_role} to {assigned_role} and audit-logged.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
