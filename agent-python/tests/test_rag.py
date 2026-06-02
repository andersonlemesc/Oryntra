from typing import Any

import pytest
from fastapi.testclient import TestClient

from oryntra_agent import settings as settings_module
from oryntra_agent.api import rag as rag_api
from oryntra_agent.main import app
from oryntra_agent.rag import extract as extract_mod
from oryntra_agent.rag.chunk import chunk_text, estimate_tokens
from oryntra_agent.rag.embed import EmbedResult
from oryntra_agent.rag.extract import (
    UnsupportedMimeTypeError,
    extract_text,
)


@pytest.fixture(autouse=True)
def configure_internal_token(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(settings_module.settings, "internal_api_token", "ci-token")


# ---- chunker --------------------------------------------------------------


def test_chunk_empty_returns_no_chunks() -> None:
    assert chunk_text("") == []
    assert chunk_text("   \n  ") == []


def test_chunk_splits_long_text_with_overlap() -> None:
    paragraphs = "\n\n".join(f"Paragraph {i} " + ("word " * 60) for i in range(8))
    chunks = chunk_text(paragraphs, target_tokens=60, overlap_tokens=10)

    assert len(chunks) > 1
    assert [c.index for c in chunks] == list(range(len(chunks)))
    for chunk in chunks:
        assert chunk.content.strip() != ""
        assert chunk.tokens == estimate_tokens(chunk.content)


def test_chunk_carries_metadata() -> None:
    chunks = chunk_text("hello world", metadata={"method": "text"})
    assert chunks[0].metadata == {"method": "text"}


# ---- extraction -----------------------------------------------------------


async def test_extract_markdown_is_direct() -> None:
    result = await extract_text(data=b"# Title\n\nbody", mime="text/markdown")
    assert result.method == "text"
    assert "Title" in result.text


async def test_extract_unsupported_mime_raises() -> None:
    with pytest.raises(UnsupportedMimeTypeError):
        await extract_text(data=b"\x00\x01", mime="application/zip")


async def test_extract_pdf_uses_lib_when_text_present(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(extract_mod, "_pdf_text", lambda _data: "x" * 200)
    result = await extract_text(data=b"%PDF-1.4", mime="application/pdf")
    assert result.method == "pdf_lib"


async def test_extract_pdf_falls_back_to_vision(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(extract_mod, "_pdf_text", lambda _data: "")

    async def fake_vision(_data: bytes, _cred: Any) -> str:
        return "scanned page transcript"

    monkeypatch.setattr(extract_mod, "_pdf_vision", fake_vision)

    cred = extract_mod.VisionCredential(provider="openai", model="gpt-4.1-mini", api_key="sk-x")
    result = await extract_text(data=b"%PDF-1.4", mime="application/pdf", vision=cred)

    assert result.method == "pdf_vision"
    assert result.text == "scanned page transcript"


# ---- ingest endpoint ------------------------------------------------------


def base_ingest_payload() -> dict[str, Any]:
    return {
        "workspace_id": 1,
        "document_id": 7,
        "file_url": "http://minio:9000/workspaces/1/knowledge/doc.md",
        "mime": "text/markdown",
        "extractor_cred": None,
        "embedder_cred": {
            "provider": "openai",
            "model": "text-embedding-3-small",
            "api_key": "sk-test",
        },
    }


def test_ingest_requires_internal_token() -> None:
    client = TestClient(app)
    response = client.post("/internal/rag/ingest", json=base_ingest_payload())
    assert response.status_code in (401, 403)


def test_ingest_returns_chunks_and_vectors(monkeypatch: pytest.MonkeyPatch) -> None:
    async def fake_download(_url: str) -> bytes:
        return b"# Knowledge\n\n" + ("sentence about pricing. " * 40).encode()

    async def fake_embed(texts: list[str], **_kwargs: Any) -> EmbedResult:
        return EmbedResult(
            vectors=[[0.1, 0.2, 0.3] for _ in texts], model="text-embedding-3-small", dim=3
        )

    monkeypatch.setattr(rag_api, "_download", fake_download)
    monkeypatch.setattr(rag_api, "embed_texts", fake_embed)

    client = TestClient(app)
    response = client.post(
        "/internal/rag/ingest",
        json=base_ingest_payload(),
        headers={"X-Internal-Token": "ci-token"},
    )

    assert response.status_code == 200
    body = response.json()
    assert len(body["chunks"]) == len(body["vectors"])
    assert len(body["chunks"]) >= 1
    assert body["embedding_provider"] == "openai"
    assert body["embedding_dim"] == 3
    assert body["vectors"][0] == [0.1, 0.2, 0.3]


def test_ingest_empty_document_returns_no_chunks(monkeypatch: pytest.MonkeyPatch) -> None:
    async def fake_download(_url: str) -> bytes:
        return b"   "

    monkeypatch.setattr(rag_api, "_download", fake_download)

    client = TestClient(app)
    response = client.post(
        "/internal/rag/ingest",
        json=base_ingest_payload(),
        headers={"X-Internal-Token": "ci-token"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["chunks"] == []
    assert body["vectors"] == []
    assert body["embedding_dim"] == 0
