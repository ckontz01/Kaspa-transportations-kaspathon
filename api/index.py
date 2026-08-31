from __future__ import annotations

from contextlib import asynccontextmanager
from typing import Any

from fastapi import Cookie, Depends, FastAPI, Request, Response
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pymongo.errors import DuplicateKeyError, PyMongoError

from backend.accounts import (
    authenticate_account,
    change_password,
    link_wallet,
    register_driver,
    register_passenger,
    require_approved_driver,
    require_role,
    require_wallet,
    update_preferences,
    update_profile,
)
from backend.covenant import protocol_metadata
from backend.db import ensure_indexes, get_database
from backend.errors import AppError
from backend.geofences import BRIDGES, GEOFENCES
from backend.mobility import (
    cancel_autonomous_ride,
    create_autonomous_ride,
    create_carshare_booking,
    create_tele_drive,
    end_carshare_rental,
    get_autonomous_ride,
    get_carshare_state,
    list_autonomous_rides,
    list_autonomous_vehicles,
    list_carshare_customers,
    list_carshare_vehicles,
    operator_mobility_snapshot,
    rate_autonomous_ride,
    register_carshare,
    start_carshare_rental,
    update_autonomous_vehicle_status,
    verify_carshare_customer,
)
from backend.platform import (
    conversation_messages,
    create_gdpr_request,
    create_vehicle,
    dashboard_summary,
    driver_earnings,
    get_driver_document,
    list_all_gdpr_requests,
    list_conversations,
    list_driver_applications,
    list_driver_documents,
    list_gdpr_requests,
    list_payment_history,
    list_system_logs,
    list_vehicles,
    message_contacts,
    operator_data_snapshot,
    operator_operations_summary,
    rate_ride,
    record_safety_inspection,
    review_gdpr_request,
    safety_inspection_state,
    save_driver_document,
    send_message,
    set_driver_availability,
    update_driver_verification,
    verify_driver_document,
)
from backend.presentation import public_ride, public_user
from backend.schemas import (
    AccountLogin,
    AutonomousRatingCreate,
    AutonomousRideCreate,
    AutonomousVehicleStatusUpdate,
    CarshareBookingCreate,
    CarshareRegistration,
    CarshareRentalEnd,
    CarshareTeleDriveCreate,
    CreateRideRequest,
    DisplayNameRequest,
    DraftSubmission,
    DriverAvailability,
    DriverDocumentUpload,
    DriverDocumentVerification,
    DriverRegistration,
    DriverVerificationUpdate,
    GdprRequestCreate,
    GdprRequestReview,
    MessageCreate,
    PassengerRegistration,
    PasswordChange,
    PreferencesUpdate,
    ProfileUpdate,
    QuoteRequest,
    RidePlanRequest,
    RideRatingCreate,
    RideVersionRequest,
    SafetyInspectionCreate,
    StartRideRequest,
    TimeoutRefundRequest,
    VehicleCreate,
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
    response.headers["Permissions-Policy"] = (
        "camera=(), microphone=(), geolocation=(self)"
    )
    response.headers["Cache-Control"] = "no-store"
    return response


@app.exception_handler(AppError)
async def app_error_handler(_: Request, exc: AppError) -> JSONResponse:
    return JSONResponse(
        status_code=exc.status_code,
        content={"error": {"code": exc.code, "message": exc.message}},
    )


@app.exception_handler(RequestValidationError)
async def validation_error_handler(
    _: Request, exc: RequestValidationError
) -> JSONResponse:
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
        content={
            "error": {"code": "conflict", "message": "A unique resource already exists"}
        },
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


def request_ip(request: Request) -> str | None:
    forwarded = request.headers.get("x-forwarded-for")
    if forwarded:
        return forwarded.split(",", 1)[0].strip()
    return request.client.host if request.client else None


def set_session_cookie(response: Response, session_token: str, expires_at: Any) -> None:
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


@app.get("/api/v1/geofences")
def geofences() -> dict[str, Any]:
    return {"geofences": GEOFENCES, "bridges": BRIDGES}


@app.post("/api/v1/auth/challenge")
def auth_challenge(payload: WalletAuthRequest, request: Request) -> dict[str, Any]:
    db = get_database()
    ensure_indexes(db)
    return create_auth_challenge(db, payload, get_settings(), request_ip(request))


@app.post("/api/v1/auth/verify")
def auth_verify(payload: WalletVerifyRequest, response: Response) -> dict[str, Any]:
    db = get_database()
    ensure_indexes(db)
    user, session_token, expires_at = verify_auth_challenge(db, payload, get_settings())
    set_session_cookie(response, session_token, expires_at)
    return {"user": user, "network": get_settings().kaspa_network}


@app.post("/api/v1/accounts/passenger", status_code=201)
def passenger_registration(
    payload: PassengerRegistration, response: Response
) -> dict[str, Any]:
    db = get_database()
    ensure_indexes(db)
    user, session_token, expires_at = register_passenger(db, payload)
    set_session_cookie(response, session_token, expires_at)
    return {"user": public_user(user), "network": get_settings().kaspa_network}


@app.post("/api/v1/accounts/driver", status_code=201)
def driver_registration(
    payload: DriverRegistration, response: Response
) -> dict[str, Any]:
    db = get_database()
    ensure_indexes(db)
    user, session_token, expires_at = register_driver(db, payload)
    set_session_cookie(response, session_token, expires_at)
    return {"user": public_user(user), "network": get_settings().kaspa_network}


@app.post("/api/v1/accounts/login")
def account_login(
    payload: AccountLogin, request: Request, response: Response
) -> dict[str, Any]:
    db = get_database()
    ensure_indexes(db)
    user, session_token, expires_at = authenticate_account(
        db, payload, request_ip(request)
    )
    set_session_cookie(response, session_token, expires_at)
    return {"user": public_user(user), "network": get_settings().kaspa_network}


@app.post("/api/v1/accounts/link-wallet")
def account_wallet_link(
    payload: WalletVerifyRequest, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    linked = link_wallet(get_database(), user, payload, get_settings())
    return {"user": public_user(linked), "network": get_settings().kaspa_network}


@app.get("/api/v1/session")
def session(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"user": public_user(user), "network": get_settings().kaspa_network}


@app.get("/api/v1/dashboard")
def dashboard(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return dashboard_summary(get_database(), user)


@app.get("/api/v1/payments")
def payments(
    limit: int = 100, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return {"payments": list_payment_history(get_database(), user, limit)}


@app.get("/api/v1/autonomous/vehicles")
def autonomous_vehicles(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"vehicles": list_autonomous_vehicles(get_database(), user)}


@app.get("/api/v1/autonomous/rides")
def autonomous_rides(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"rides": list_autonomous_rides(get_database(), user)}


@app.post("/api/v1/autonomous/rides", status_code=201)
def autonomous_ride_create(
    payload: AutonomousRideCreate, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return create_autonomous_ride(get_database(), user, payload)


@app.get("/api/v1/autonomous/rides/{ride_id}")
def autonomous_ride_detail(
    ride_id: str, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return get_autonomous_ride(get_database(), user, ride_id)


@app.post("/api/v1/autonomous/rides/{ride_id}/cancel")
def autonomous_ride_cancel(
    ride_id: str, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return cancel_autonomous_ride(get_database(), user, ride_id)


@app.post("/api/v1/autonomous/rides/{ride_id}/rating")
def autonomous_ride_rating(
    ride_id: str,
    payload: AutonomousRatingCreate,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return rate_autonomous_ride(get_database(), user, ride_id, payload)


@app.get("/api/v1/carshare/state")
def carshare_state(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return get_carshare_state(get_database(), user)


@app.post("/api/v1/carshare/register", status_code=201)
def carshare_register(
    payload: CarshareRegistration, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return register_carshare(get_database(), user, payload)


@app.get("/api/v1/carshare/vehicles")
def carshare_vehicles(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return list_carshare_vehicles(get_database(), user)


@app.post("/api/v1/carshare/bookings", status_code=201)
def carshare_booking(
    payload: CarshareBookingCreate, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return create_carshare_booking(get_database(), user, payload)


@app.post("/api/v1/carshare/bookings/{booking_id}/start")
def carshare_start(
    booking_id: str, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return start_carshare_rental(get_database(), user, booking_id)


@app.post("/api/v1/carshare/bookings/{booking_id}/end")
def carshare_end(
    booking_id: str,
    payload: CarshareRentalEnd,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return end_carshare_rental(get_database(), user, booking_id, payload)


@app.post("/api/v1/carshare/teledrive", status_code=201)
def carshare_tele_drive(
    payload: CarshareTeleDriveCreate,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return create_tele_drive(get_database(), user, payload)


@app.get("/api/v1/operator/carshare/customers")
def operator_carshare_customers(
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return {"customers": list_carshare_customers(get_database(), user)}


@app.patch("/api/v1/operator/carshare/customers/{customer_id}")
def operator_carshare_customer_verification(
    customer_id: str,
    payload: DriverVerificationUpdate,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return verify_carshare_customer(get_database(), user, customer_id, payload.status)


@app.get("/api/v1/operator/operations")
def operator_operations(
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return operator_operations_summary(get_database(), user)


@app.get("/api/v1/operator/safety-inspections")
def operator_safety_inspections(
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return safety_inspection_state(get_database(), user)


@app.post("/api/v1/operator/safety-inspections", status_code=201)
def operator_safety_inspection_create(
    payload: SafetyInspectionCreate,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return record_safety_inspection(get_database(), user, payload)


@app.get("/api/v1/operator/system-logs")
def operator_system_logs(
    limit: int = 200,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return {"logs": list_system_logs(get_database(), user, limit)}


@app.get("/api/v1/operator/data")
def operator_data(
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return operator_data_snapshot(get_database(), user)


@app.get("/api/v1/operator/fleet")
def operator_fleet(
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return operator_mobility_snapshot(get_database(), user)


@app.patch("/api/v1/operator/autonomous/vehicles/{vehicle_id}")
def operator_autonomous_vehicle_status(
    vehicle_id: str,
    payload: AutonomousVehicleStatusUpdate,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return update_autonomous_vehicle_status(get_database(), user, vehicle_id, payload)


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


@app.patch("/api/v1/account/profile")
def account_profile(
    payload: ProfileUpdate, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return {"user": public_user(update_profile(get_database(), user, payload))}


@app.patch("/api/v1/account/preferences")
def account_preferences(
    payload: PreferencesUpdate, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return {"user": public_user(update_preferences(get_database(), user, payload))}


@app.post("/api/v1/account/password", status_code=204)
def account_password(
    payload: PasswordChange,
    response: Response,
    user: dict[str, Any] = Depends(current_user),
) -> Response:
    change_password(get_database(), user, payload)
    response.delete_cookie(SESSION_COOKIE, path="/")
    response.status_code = 204
    return response


@app.post("/api/v1/quotes", status_code=201)
def quote(
    payload: QuoteRequest, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    require_role(user, "passenger")
    require_wallet(user)
    return create_quote(get_database(), user, payload)


@app.post("/api/v1/rides", status_code=201)
def new_ride(
    payload: CreateRideRequest, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    require_role(user, "passenger")
    require_wallet(user)
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
    require_approved_driver(user)
    require_wallet(user)
    db = get_database()
    available = (
        db.rides.find({"status": "funded", "passengerId": {"$ne": user["_id"]}})
        .sort("updatedAt", 1)
        .limit(50)
    )
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
    require_role(user, "passenger")
    require_wallet(user)
    return await plan_funding(
        get_database(), ride_id, user, payload.version, get_settings()
    )


@app.post("/api/v1/rides/{ride_id}/acceptance-plan", status_code=201)
async def acceptance_plan(
    ride_id: str,
    payload: RidePlanRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    require_approved_driver(user)
    require_wallet(user)
    return await plan_acceptance(
        get_database(), ride_id, user, payload.version, get_settings()
    )


@app.post("/api/v1/rides/{ride_id}/start")
def begin_ride(
    ride_id: str,
    payload: StartRideRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    require_approved_driver(user)
    require_wallet(user)
    return start_ride(get_database(), ride_id, user, payload.version)


@app.post("/api/v1/rides/{ride_id}/settlement-plan", status_code=201)
async def settlement_plan(
    ride_id: str,
    payload: RidePlanRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    require_wallet(user)
    return await plan_settlement(
        get_database(), ride_id, user, payload.version, get_settings()
    )


@app.post("/api/v1/rides/{ride_id}/cancel")
async def cancel_ride(
    ride_id: str,
    payload: RideVersionRequest,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    require_wallet(user)
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
    require_role(user, "passenger")
    require_wallet(user)
    return await plan_timeout_refund(
        get_database(), ride_id, user, payload.version, get_settings()
    )


@app.post("/api/v1/rides/{ride_id}/rating")
def ride_rating(
    ride_id: str,
    payload: RideRatingCreate,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return rate_ride(get_database(), user, ride_id, payload)


@app.get("/api/v1/driver/vehicles")
def driver_vehicles(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"vehicles": list_vehicles(get_database(), user)}


@app.post("/api/v1/driver/vehicles", status_code=201)
def driver_vehicle_create(
    payload: VehicleCreate, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return create_vehicle(get_database(), user, payload)


@app.get("/api/v1/driver/documents")
def driver_documents(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"documents": list_driver_documents(get_database(), user)}


@app.post("/api/v1/driver/documents")
def driver_document_upload(
    payload: DriverDocumentUpload, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return save_driver_document(get_database(), user, payload)


@app.get("/api/v1/driver/documents/{document_id}/content")
def driver_document_content(
    document_id: str, user: dict[str, Any] = Depends(current_user)
) -> Response:
    item = get_driver_document(get_database(), user, document_id)
    return Response(
        content=bytes(item["data"]),
        media_type=item["contentType"],
        headers={
            "Cache-Control": "private, no-store",
            "Content-Disposition": "inline",
            "Content-Security-Policy": "default-src 'none'; sandbox",
            "X-Content-Type-Options": "nosniff",
        },
    )


@app.patch("/api/v1/driver/availability")
def driver_availability(
    payload: DriverAvailability, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return set_driver_availability(get_database(), user, payload)


@app.get("/api/v1/driver/earnings")
def earnings(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return driver_earnings(get_database(), user)


@app.get("/api/v1/operator/drivers")
def operator_drivers(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"drivers": list_driver_applications(get_database(), user)}


@app.patch("/api/v1/operator/drivers/{driver_account_id}")
def operator_driver_verification(
    driver_account_id: str,
    payload: DriverVerificationUpdate,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return update_driver_verification(get_database(), user, driver_account_id, payload)


@app.get("/api/v1/operator/drivers/{driver_account_id}/documents")
def operator_driver_documents(
    driver_account_id: str, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return {"documents": list_driver_documents(get_database(), user, driver_account_id)}


@app.patch("/api/v1/operator/documents/{document_id}")
def operator_document_verification(
    document_id: str,
    payload: DriverDocumentVerification,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return verify_driver_document(get_database(), user, document_id, payload)


@app.get("/api/v1/messages/contacts")
def contacts(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"contacts": message_contacts(get_database(), user)}


@app.get("/api/v1/messages/conversations")
def conversations(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"conversations": list_conversations(get_database(), user)}


@app.get("/api/v1/messages/{contact_id}")
def messages_with(
    contact_id: str, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return {"messages": conversation_messages(get_database(), user, contact_id)}


@app.post("/api/v1/messages", status_code=201)
def message_send(
    payload: MessageCreate, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return send_message(get_database(), user, payload)


@app.get("/api/v1/privacy/requests")
def privacy_requests(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    return {"requests": list_gdpr_requests(get_database(), user)}


@app.post("/api/v1/privacy/requests", status_code=201)
def privacy_request_create(
    payload: GdprRequestCreate, user: dict[str, Any] = Depends(current_user)
) -> dict[str, Any]:
    return create_gdpr_request(get_database(), user, payload)


@app.get("/api/v1/operator/privacy/requests")
def operator_privacy_requests(
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return {"requests": list_all_gdpr_requests(get_database(), user)}


@app.patch("/api/v1/operator/privacy/requests/{request_id}")
def operator_privacy_review(
    request_id: str,
    payload: GdprRequestReview,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    return review_gdpr_request(get_database(), user, request_id, payload)


@app.get("/api/v1/signing-drafts/pending")
def pending_drafts(user: dict[str, Any] = Depends(current_user)) -> dict[str, Any]:
    require_wallet(user)
    return {"drafts": pending_signing_drafts(get_database(), user)}


@app.post("/api/v1/signing-drafts/{draft_id}/submit")
async def submit_signature(
    draft_id: str,
    payload: DraftSubmission,
    user: dict[str, Any] = Depends(current_user),
) -> dict[str, Any]:
    require_wallet(user)
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
    require_wallet(user)
    return await retry_draft_broadcast(get_database(), draft_id, user, get_settings())
