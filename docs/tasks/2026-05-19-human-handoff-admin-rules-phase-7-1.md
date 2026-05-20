# Human Handoff Admin Rules Phase 7.1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Let operators configure, from Filament, the situations where a specialist should transfer a conversation to a human, and replace the Python handoff sentinel with structured specialist decisions.

**Architecture:** Store per-specialist handoff policy in `agent_specialists.handoff_config` as JSON. Laravel owns UI, validation, tenancy, and runtime payload. Python receives the policy and deterministically requests `request_human_handoff` when an enabled rule matches the customer messages. For LLM-driven decisions, Python uses structured output (`SpecialistDecision`) with explicit actions like `respond_text` and `request_human_handoff`, keeping the old sentinel only as a temporary compatibility fallback.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL jsonb, Pest, FastAPI/Pydantic, LangGraph, pytest.

---

## Tasks

- [x] Add `handoff_config` jsonb column to `agent_specialists` with model cast and factory defaults.
- [x] Add Filament specialist fields for enabling human transfer, default message/priority, and keyword rules.
- [x] Normalize specialist form data so enabling handoff automatically adds `request_human_handoff` to `tools_allowlist`.
- [x] Include `handoff_config` in `AgentRuntimeClient` specialist payload.
- [x] Add Python Pydantic schema for handoff rules/config.
- [x] Execute configured handoff rules in Python before normal specialist response.
- [x] Add `SpecialistDecision` structured output for specialist LLMs with actions `respond_text` and `request_human_handoff`.
- [x] Route structured `request_human_handoff` decisions through the Laravel tool gateway, enforcing the specialist allowlist.
- [x] Add Laravel and Python tests for persistence, payload, UI action data, and runtime handoff.
- [x] Run Pint, Pest, Larastan, ruff, mypy, and pytest.
