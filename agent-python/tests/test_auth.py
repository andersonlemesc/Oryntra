import pytest
from fastapi import HTTPException

from oryntra_agent import auth


@pytest.mark.asyncio
async def test_verify_internal_token_accepts_configured_token(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    await auth.verify_internal_token("ci-token")


@pytest.mark.asyncio
async def test_verify_internal_token_rejects_missing_token(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    with pytest.raises(HTTPException) as exception:
        await auth.verify_internal_token(None)

    assert exception.value.status_code == 401


@pytest.mark.asyncio
async def test_verify_internal_token_rejects_invalid_token(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(auth.settings, "internal_api_token", "ci-token")

    with pytest.raises(HTTPException) as exception:
        await auth.verify_internal_token("wrong")

    assert exception.value.status_code == 401
