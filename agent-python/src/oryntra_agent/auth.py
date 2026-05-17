from fastapi import Header, HTTPException, status

from oryntra_agent.settings import settings


async def verify_internal_token(
    x_internal_token: str | None = Header(default=None, alias="X-Internal-Token"),
) -> None:
    """Validate the shared internal token between Laravel and this service.

    All non-health endpoints must depend on this.
    """
    if not x_internal_token or x_internal_token != settings.internal_api_token:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="invalid or missing X-Internal-Token",
        )
