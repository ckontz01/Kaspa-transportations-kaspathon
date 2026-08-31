from __future__ import annotations


class AppError(Exception):
    def __init__(self, status_code: int, message: str, code: str = "request_failed") -> None:
        super().__init__(message)
        self.status_code = status_code
        self.message = message
        self.code = code


def bad_request(message: str, code: str = "bad_request") -> AppError:
    return AppError(400, message, code)


def unauthorized(message: str = "Wallet authentication is required") -> AppError:
    return AppError(401, message, "unauthorized")


def forbidden(message: str = "This wallet cannot perform that action") -> AppError:
    return AppError(403, message, "forbidden")


def not_found(message: str) -> AppError:
    return AppError(404, message, "not_found")


def conflict(message: str, code: str = "state_conflict") -> AppError:
    return AppError(409, message, code)


def unavailable(message: str) -> AppError:
    return AppError(503, message, "temporarily_unavailable")
