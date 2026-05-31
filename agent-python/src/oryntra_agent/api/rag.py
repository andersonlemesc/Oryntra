"""Knowledge-base ingestion endpoint.

Laravel dispatches a job that calls this endpoint with a presigned file URL and
BYOK credentials. Python downloads the file, extracts text (lib + vision
fallback), chunks and embeds it, and returns the chunks + vectors for Laravel to
persist into pgvector. Python never writes the business tables.
"""

from __future__ import annotations

import logging
from typing import Any, Literal

import httpx
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field, SecretStr

from oryntra_agent.auth import verify_internal_token
from oryntra_agent.rag.chunk import chunk_text
from oryntra_agent.rag.embed import (
    UnsupportedEmbeddingProviderError,
    embed_texts,
)
from oryntra_agent.rag.extract import (
    UnsupportedMimeTypeError,
    VisionCredential,
    VisionUnavailableError,
    extract_text,
)

logger = logging.getLogger(__name__)

MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024
DOWNLOAD_TIMEOUT_SECONDS = 60

router = APIRouter(
    prefix="/internal/rag",
    tags=["rag"],
    dependencies=[Depends(verify_internal_token)],
)


class RagCredential(BaseModel):
    model_config = ConfigDict(extra="forbid")

    provider: Literal["openai", "anthropic", "gemini", "local"]
    base_url: str | None = None
    model: str
    api_key: SecretStr = Field(exclude=True)


class IngestRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    workspace_id: int
    document_id: int
    file_url: str
    mime: str
    extractor_cred: RagCredential | None = None
    embedder_cred: RagCredential


class EmbedQueryRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    query: str
    embedder_cred: RagCredential


class EmbedQueryResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    vector: list[float]
    embedding_model: str
    embedding_dim: int


class IngestChunk(BaseModel):
    model_config = ConfigDict(extra="forbid")

    index: int
    content: str
    tokens: int | None = None
    metadata: dict[str, Any] = Field(default_factory=dict)


class IngestResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    chunks: list[IngestChunk]
    vectors: list[list[float]]
    embedding_provider: str
    embedding_model: str
    embedding_dim: int
    usage: dict[str, Any] = Field(default_factory=dict)


async def _download(url: str) -> bytes:
    try:
        async with httpx.AsyncClient(timeout=DOWNLOAD_TIMEOUT_SECONDS) as client:
            response = await client.get(url, follow_redirects=True)
            response.raise_for_status()
    except httpx.HTTPError as exc:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail=f"failed to download document: {type(exc).__name__}",
        ) from exc

    if len(response.content) > MAX_DOWNLOAD_BYTES:
        raise HTTPException(
            status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            detail="document exceeds the maximum ingest size",
        )

    return response.content


@router.post("/embed-query", response_model=EmbedQueryResponse)
async def embed_query(payload: EmbedQueryRequest) -> EmbedQueryResponse:
    try:
        result = await embed_texts(
            [payload.query],
            provider=payload.embedder_cred.provider,
            model=payload.embedder_cred.model,
            api_key=payload.embedder_cred.api_key.get_secret_value(),
            base_url=payload.embedder_cred.base_url,
        )
    except UnsupportedEmbeddingProviderError as exc:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=str(exc),
        ) from exc

    vector = result.vectors[0] if result.vectors else []
    return EmbedQueryResponse(
        vector=vector,
        embedding_model=result.model,
        embedding_dim=result.dim,
    )


@router.post("/ingest", response_model=IngestResponse)
async def ingest(payload: IngestRequest) -> IngestResponse:
    data = await _download(payload.file_url)

    vision: VisionCredential | None = None
    if payload.extractor_cred is not None:
        vision = VisionCredential(
            provider=payload.extractor_cred.provider,
            model=payload.extractor_cred.model,
            api_key=payload.extractor_cred.api_key.get_secret_value(),
            base_url=payload.extractor_cred.base_url,
        )

    try:
        extracted = await extract_text(data=data, mime=payload.mime, vision=vision)
    except (UnsupportedMimeTypeError, VisionUnavailableError) as exc:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=str(exc),
        ) from exc
    except Exception as exc:
        logger.exception(
            "rag.extract_failed",
            extra={"workspace_id": payload.workspace_id, "document_id": payload.document_id},
        )
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=f"extraction failed: {type(exc).__name__}",
        ) from exc

    chunks = chunk_text(extracted.text, metadata={"method": extracted.method})

    if not chunks:
        return IngestResponse(
            chunks=[],
            vectors=[],
            embedding_provider=payload.embedder_cred.provider,
            embedding_model=payload.embedder_cred.model,
            embedding_dim=0,
            usage={"chunks": 0, "method": extracted.method},
        )

    try:
        embed_result = await embed_texts(
            [chunk.content for chunk in chunks],
            provider=payload.embedder_cred.provider,
            model=payload.embedder_cred.model,
            api_key=payload.embedder_cred.api_key.get_secret_value(),
            base_url=payload.embedder_cred.base_url,
        )
    except UnsupportedEmbeddingProviderError as exc:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=str(exc),
        ) from exc

    return IngestResponse(
        chunks=[
            IngestChunk(
                index=chunk.index,
                content=chunk.content,
                tokens=chunk.tokens,
                metadata=chunk.metadata,
            )
            for chunk in chunks
        ],
        vectors=embed_result.vectors,
        embedding_provider=payload.embedder_cred.provider,
        embedding_model=embed_result.model,
        embedding_dim=embed_result.dim,
        usage={"chunks": len(chunks), "method": extracted.method},
    )


__all__ = ["EmbedQueryRequest", "EmbedQueryResponse", "IngestRequest", "IngestResponse", "router"]
