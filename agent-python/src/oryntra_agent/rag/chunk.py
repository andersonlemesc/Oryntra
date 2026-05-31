"""Sliding-window chunker with paragraph > sentence > word boundary preference.

Token counts use a 4-chars-per-token heuristic (no tokenizer dependency).
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

CHARS_PER_TOKEN = 4
DEFAULT_TARGET_TOKENS = 500
DEFAULT_OVERLAP_TOKENS = 80

_PARAGRAPH_RE = re.compile(r"\n\s*\n")
_SENTENCE_RE = re.compile(r"(?<=[.!?])\s+")


@dataclass
class Chunk:
    index: int
    content: str
    tokens: int
    metadata: dict[str, Any] = field(default_factory=dict)


def estimate_tokens(text: str) -> int:
    return max(1, len(text) // CHARS_PER_TOKEN)


def _split_units(text: str, max_chars: int) -> list[str]:
    """Break text into units no larger than ``max_chars``, preferring paragraph
    then sentence then hard-character boundaries."""
    units: list[str] = []

    for paragraph in _PARAGRAPH_RE.split(text):
        paragraph = paragraph.strip()
        if not paragraph:
            continue
        if len(paragraph) <= max_chars:
            units.append(paragraph)
            continue

        for sentence in _SENTENCE_RE.split(paragraph):
            sentence = sentence.strip()
            if not sentence:
                continue
            if len(sentence) <= max_chars:
                units.append(sentence)
                continue

            for start in range(0, len(sentence), max_chars):
                piece = sentence[start : start + max_chars].strip()
                if piece:
                    units.append(piece)

    return units


def chunk_text(
    text: str,
    *,
    target_tokens: int = DEFAULT_TARGET_TOKENS,
    overlap_tokens: int = DEFAULT_OVERLAP_TOKENS,
    metadata: dict[str, Any] | None = None,
) -> list[Chunk]:
    text = (text or "").strip()
    if not text:
        return []

    base_metadata = metadata or {}
    target_chars = max(1, target_tokens * CHARS_PER_TOKEN)
    overlap_chars = max(0, overlap_tokens * CHARS_PER_TOKEN)

    units = _split_units(text, target_chars)
    if not units:
        return []

    chunks: list[Chunk] = []
    current: list[str] = []
    current_len = 0
    index = 0

    def unit_len(unit: str) -> int:
        return len(unit) + 2  # account for the "\n\n" join

    for unit in units:
        if current and current_len + unit_len(unit) > target_chars:
            content = "\n\n".join(current)
            chunks.append(Chunk(index, content, estimate_tokens(content), dict(base_metadata)))
            index += 1

            overlap: list[str] = []
            acc = 0
            for previous in reversed(current):
                if acc >= overlap_chars:
                    break
                overlap.insert(0, previous)
                acc += unit_len(previous)
            current = overlap
            current_len = sum(unit_len(u) for u in current)

        current.append(unit)
        current_len += unit_len(unit)

    if current:
        content = "\n\n".join(current)
        chunks.append(Chunk(index, content, estimate_tokens(content), dict(base_metadata)))

    return chunks
