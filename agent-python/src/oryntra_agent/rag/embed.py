"""Embedding generation via BYOK credentials.

Anthropic has no embeddings API, so only openai / local (OpenAI-compatible) and
gemini are supported. Sync SDK calls are offloaded to a thread to keep the
FastAPI event loop responsive.
"""

from __future__ import annotations

import asyncio
from dataclasses import dataclass
from typing import Any

from pydantic import SecretStr

EMBEDDING_PROVIDERS: frozenset[str] = frozenset({"openai", "local", "gemini"})
DEFAULT_BATCH_SIZE = 100


class UnsupportedEmbeddingProviderError(ValueError):
    pass


@dataclass
class EmbedResult:
    vectors: list[list[float]]
    model: str
    dim: int


def _build_embedder(provider: str, model: str, api_key: str, base_url: str | None) -> Any:
    if provider in ("openai", "local"):
        from langchain_openai import OpenAIEmbeddings

        kwargs: dict[str, Any] = {}
        if base_url:
            kwargs["base_url"] = base_url
        return OpenAIEmbeddings(model=model, openai_api_key=SecretStr(api_key), **kwargs)

    if provider == "gemini":
        from langchain_google_genai import GoogleGenerativeAIEmbeddings

        kwargs = {}
        if base_url:
            kwargs["client_options"] = {"api_endpoint": base_url}
        return GoogleGenerativeAIEmbeddings(
            model=model, google_api_key=SecretStr(api_key), **kwargs
        )

    raise UnsupportedEmbeddingProviderError(f"provider '{provider}' does not support embeddings")


async def embed_texts(
    texts: list[str],
    *,
    provider: str,
    model: str,
    api_key: str,
    base_url: str | None = None,
    batch_size: int = DEFAULT_BATCH_SIZE,
) -> EmbedResult:
    if provider not in EMBEDDING_PROVIDERS:
        raise UnsupportedEmbeddingProviderError(
            f"provider '{provider}' does not support embeddings"
        )

    embedder = _build_embedder(provider, model, api_key, base_url)

    vectors: list[list[float]] = []
    for start in range(0, len(texts), batch_size):
        batch = texts[start : start + batch_size]
        result = await asyncio.to_thread(embedder.embed_documents, batch)
        vectors.extend([[float(value) for value in vector] for vector in result])

    dim = len(vectors[0]) if vectors else 0
    return EmbedResult(vectors=vectors, model=model, dim=dim)
