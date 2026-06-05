import hmac

from fastapi import Header, HTTPException, status

from oryntra_agent.settings import settings


async def verify_internal_token(
    x_internal_token: str | None = Header(default=None, alias="X-Internal-Token"),
) -> None:
    """Validate the shared internal token between Laravel and this service.

    All non-health endpoints must depend on this. The comparison is
    constant-time to avoid leaking the token through timing.
    """
    expected = settings.internal_api_token
    if not x_internal_token or not expected or not hmac.compare_digest(x_internal_token, expected):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="invalid or missing X-Internal-Token",
        )
