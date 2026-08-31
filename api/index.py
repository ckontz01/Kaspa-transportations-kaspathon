from __future__ import annotations

from contextlib import asynccontextmanager
from typing import Any

from fastapi import Cookie, Depends, FastAPI, Header, Request, Response
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pymongo.errors import DuplicateKeyError, PyMongoError

from backend.covenant import protocol_metadata
from backend.db import ensure_indexes, get_database
from backend.errors import AppError
from backend.presentation import public_ride, public_user
from backend.schemas import (
    CreateRideRequest,
    DisplayNameRequest,
    DraftSubmission,
    QuoteRequest,
    RidePlanRequest,
    RideVersionRequest,
    StartRideRequest,
    TimeoutRefundRequest,
    WalletAuthRequest,
    WalletVerifyRequest,
)
from backend.services import (
    authenticated_user,
    cancel_unfunded_ride,
    create_auth_challenge,
    create_quote,
    create_ride,
    get_ride_for_user,
    list_legacy_rides,
    list_user_rides,
    pending_signing_drafts,
    plan_acceptance,
    plan_cancellation,
    plan_funding,
    plan_settlement,
    plan_timeout_refund,
    reconcile_ride,
    retry_draft_broadcast,
    revoke_session,
    start_ride,
    submit_draft_signature,
    update_display_name,
    verify_auth_challenge,
)
from backend.settings import get_settings


SESSION_COOKIE = "kt_session"


@asynccontextmanager
async def lifespan(_: FastAPI):
    settings = get_settings()
    if settings.mongodb_uri:
        ensure_indexes()
    yield


app = FastAPI(
    title="Kaspa Transportations API",
    version="2.0.0",
    docs_url="/api/docs",
    openapi_url="/api/openapi.json",
    redoc_url=None,
    lifespan=lifespan,
)


@app.middleware("http")
async def security_headers(request: Request, call_next: Any) -> Response:
    response = await call_next(request)
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["X-Frame-Options"] = "DENY"
    response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
    response.headers["Permissions-Policy"] = "camera=(), microphone=(), geolocation=(self)"
    response.headers["Cache-Control"] = "no-store"
    return response


@app.exception_handler(AppError)
async def app_error_handler(_: Request, exc: AppError) -> JSONResponse:
    return JSONResponse(
        status_code=exc.status_code,
        content={"error": {"code": exc.code, "message": exc.message}},
    )


@app.exception_handler(RequestValidationError)
async def validation_error_handler(_: Request, exc: RequestValidationError) -> JSONResponse:
    return JSONResponse(
        status_code=422,
        content={
            "error": {
                "code": "validation_failed",
                "message": "Request validation failed",
                "fields": exc.errors(),
            }
        },
    )


@app.exception_handler(DuplicateKeyError)
async def duplicate_key_handler(_: Request, __: DuplicateKeyError) -> JSONResponse:
    return JSONResponse(
        status_code=409,
        content={"error": {"code": "conflict", "message": "A unique resource already exists"}},
    )


@app.exception_handler(PyMongoError)
async def mongo_error_handler(_: Request, __: PyMongoError) -> JSONResponse:
    return JSONResponse(
        status_code=503,
        content={
            "error": {
                "code": "database_unavailable",
                "message": "The database is temporarily unavailable",
            }
        },
    )


@app.exception_handler(RuntimeError)
async def runtime_error_handler(_: Request, exc: RuntimeError) -> JSONResponse:
    return JSONResponse(
        status_code=503,
        content={"error": {"code": "not_configured", "message": str(exc)}},
    )


@app.exception_handler(ValueError)
async def value_error_handler(_: Request, exc: ValueError) -> JSONResponse:
    return JSONResponse(
        status_code=400,
        content={"error": {"code": "invalid_value", "message": str(exc)}},
    )


def current_user(kt_session: str | None = Cookie(default=None)) -> dict[str, Any]:
    db = get_database()
    ensure_indexes(db)
    return authenticated_user(db, kt_session)


@app.get("/api/health")
def health() -> dict[str, Any]:
    settings = get_settings()
    return {
        "ok": True,
        "service": "kaspa-transportations",
        "version": "2.0.0",
        "network": settings.kaspa_network,
        "databaseConfigured": bool(settings.mongodb_uri),
        "resolverConfigured": bool(settings.kaspa_resolver_public_key),
        "protocol": protocol_metadata(),
    }


@app.get("/api/v1/protocol")
def protocol() -> dict[str, Any]:
    settings = get_settings()
    return {
        **protocol_metadata(),
        "network": settings.kaspa_network,
        "walletStandard": "KIP-12",
        "transactionVersion": 1,
        "mainnetEnabled": settings.kaspa_network == "mainnet",
    }


@app.post("/api/v1/auth/challenge")
def auth_challenge(payload: WalletAuthRequest, request: Request) -> dict[str, Any]:
    db = get_database()
    ensure_indexes(db)
    forwarded = request.headers.get("x-forwarded-for")
    ip = forwarded.split(",", 1)[0].strip() if forwarded else None
    return create_auth_challenge(db, payload, get_settings(), ip)


@app.post("/api/v1/auth/verify")
def auth_verify(payload: WalletVerifyRequest, response: Response) -> dict[str, Any]:
    db = get_database()
    ensure_indexes(db)
    user, session_token, expires_at = verify_auth_challenge(db, payload, get_settings())
    settings = get_settings()
    response.set_cookie(
        SESSION_COOKIE,
        session_token,
        expires=expires_at,
        max_age=14 * 24 * 60 * 60,
        httponly=True,
        secure=settings.secure_cookies,
        samesite="lax",
        path="/",
    )
    return {"user": user, "network": settings.kaspa_network}


@app.get("/api/v1/session")
def session(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"user": public_user(user), "network": get_settings().kaspa_network}


@app.delete("/api/v1/session", status_code=204)
def logout(
    response: Response, kt_session: str | None = Cookie(default=None)
) -> Response:
    db = get_database()
    revoke_session(db, kt_session)
    response.delete_cookie(SESSION_COOKIE, path="/")
    response.status_code = 204
    return response


@app.patch("/api/v1/profile")
def profile(
    payload: DisplayNameRequest, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return {"user": update_display_name(get_database(), user, payload.display_name)}


@app.post("/api/v1/quotes", status_code=201)
def quote(
    payload: QuoteRequest, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return create_quote(get_database(), user, payload)


@app.post("/api/v1/rides", status_code=201)
def new_ride(
    payload: CreateRideRequest, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return create_ride(get_database(), user, payload, get_settings())


@app.get("/api/v1/rides")
def rides(
    limit: int = 20, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return {"rides": list_user_rides(get_database(), user, limit)}


@app.get("/api/v1/legacy/rides")
def legacy_rides(
    limit: int = 50, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    """Historical SQL Server rides claimable only by the same signed wallet."""

    return {"rides": list_legacy_rides(get_database(), user, limit)}


@app.get("/api/v1/dispatch")
def dispatch(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    db = get_database()
    available = db.rides.find(
        {"status": "funded", "passengerId": {"$ne": user["_id"]}}
    ).sort("updatedAt", 1).limit(50)
    return {"rides": [public_ride(item) for item in available]}


@app.get("/api/v1/rides/{ride_id}")
async def ride(
    ride_id: str, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    db = get_database()
    item = get_ride_for_user(db, ride_id, user)
    item = await reconcile_ride(db, item, get_settings())
    return public_ride(item)


@app.post("/api/v1/rides/{ride_id}/funding-plan", status_code=201)
async def funding_plan(
    ride_id: str,
    payload: RidePlanRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return await plan_funding(get_database(), ride_id, user, payload.version, get_settings())


@app.post("/api/v1/rides/{ride_id}/acceptance-plan", status_code=201)
async def acceptance_plan(
    ride_id: str,
    payload: RidePlanRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return await plan_acceptance(get_database(), ride_id, user, payload.version, get_settings())


@app.post("/api/v1/rides/{ride_id}/start")
def begin_ride(
    ride_id: str,
    payload: StartRideRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return start_ride(get_database(), ride_id, user, payload.version)


@app.post("/api/v1/rides/{ride_id}/settlement-plan", status_code=201)
async def settlement_plan(
    ride_id: str,
    payload: RidePlanRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return await plan_settlement(get_database(), ride_id, user, payload.version, get_settings())


@app.post("/api/v1/rides/{ride_id}/cancel")
async def cancel_ride(
    ride_id: str,
    payload: RideVersionRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    db = get_database()
    item = get_ride_for_user(db, ride_id, user)
    if item["status"] == "awaiting_funding":
        return cancel_unfunded_ride(db, ride_id, user, payload.version)
    return await plan_cancellation(db, ride_id, user, payload.version, get_settings())


@app.post("/api/v1/rides/{ride_id}/timeout-refund-plan", status_code=201)
async def timeout_refund_plan(
    ride_id: str,
    payload: TimeoutRefundRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return await plan_timeout_refund(get_database(), ride_id, user, payload.version, get_settings())


@app.get("/api/v1/signing-drafts/pending")
def pending_drafts(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"drafts": pending_signing_drafts(get_database(), user)}


@app.post("/api/v1/signing-drafts/{draft_id}/submit")
async def submit_signature(
    draft_id: str,
    payload: DraftSubmission,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return await submit_draft_signature(
        get_database(),
        draft_id,
        user,
        payload.signed_transaction_json,
        get_settings(),
    )


@app.post("/api/v1/signing-drafts/{draft_id}/retry-broadcast")
async def retry_broadcast(
    draft_id: str, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return await retry_draft_broadcast(get_database(), draft_id, user, get_settings())
