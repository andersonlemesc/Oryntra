"""Tests for the Google Calendar StructuredTool builders + Pydantic args schemas."""

from __future__ import annotations

import pytest
from pydantic import ValidationError

from oryntra_agent.agent.tool_runtime import (
    GCAL_TOOLS,
    GcalCreateEventArgs,
    GcalDeleteEventArgs,
    GcalFindFreeSlotsArgs,
    GcalListEventsArgs,
    GcalUpdateEventArgs,
    ToolRuntimeContext,
    build_specialist_tools,
)


def _ctx() -> ToolRuntimeContext:
    return ToolRuntimeContext(
        workspace_id=1,
        agent_id=2,
        agent_run_id=3,
        specialist_id=4,
        contact_id=None,
        conversation_id=5,
    )


class TestArgsSchemas:
    def test_list_events_rejects_extra_fields(self) -> None:
        with pytest.raises(ValidationError):
            GcalListEventsArgs(
                time_min="2026-06-01T00:00:00Z", time_max="2026-06-02T00:00:00Z", evil="x"
            )  # type: ignore[call-arg]

    def test_create_event_requires_summary_start_end(self) -> None:
        with pytest.raises(ValidationError):
            GcalCreateEventArgs(summary="x")  # type: ignore[call-arg]

    def test_create_event_accepts_attendees_list(self) -> None:
        args = GcalCreateEventArgs(
            summary="Demo",
            start="2026-06-01T10:00:00-03:00",
            end="2026-06-01T11:00:00-03:00",
            attendees=["a@example.com", "b@example.com"],
        )
        assert args.attendees == ["a@example.com", "b@example.com"]

    def test_create_event_rejects_allow_conflicts(self) -> None:
        """allow_conflicts é config do especialista (Filament), não param da tool.
        Modelo não pode contornar bloqueio de sobreposição via prompt injection."""
        with pytest.raises(ValidationError):
            GcalCreateEventArgs(
                summary="Demo",
                start="2026-06-01T10:00:00-03:00",
                end="2026-06-01T11:00:00-03:00",
                allow_conflicts=True,  # type: ignore[call-arg]
            )

    def test_update_event_requires_event_id(self) -> None:
        with pytest.raises(ValidationError):
            GcalUpdateEventArgs(summary="x")  # type: ignore[call-arg]

    def test_delete_event_requires_event_id(self) -> None:
        with pytest.raises(ValidationError):
            GcalDeleteEventArgs()  # type: ignore[call-arg]

    def test_find_free_slots_requires_duration_and_range(self) -> None:
        with pytest.raises(ValidationError):
            GcalFindFreeSlotsArgs(duration_minutes=30)  # type: ignore[call-arg]

    def test_find_free_slots_enforces_min_duration(self) -> None:
        with pytest.raises(ValidationError):
            GcalFindFreeSlotsArgs(
                duration_minutes=1,
                range_start="2026-06-01T00:00:00Z",
                range_end="2026-06-02T00:00:00Z",
            )


class TestBuilders:
    def test_gcal_tools_constant_has_all_five(self) -> None:
        assert (
            frozenset(
                {
                    "gcal_list_events",
                    "gcal_create_event",
                    "gcal_update_event",
                    "gcal_delete_event",
                    "gcal_find_free_slots",
                }
            )
            == GCAL_TOOLS
        )

    def test_build_specialist_tools_includes_only_allowed_gcal_tools(self) -> None:
        tools = build_specialist_tools(
            allowed_tools=["gcal_list_events", "gcal_find_free_slots"],
            ctx=_ctx(),
        )
        names = {t.name for t in tools}
        assert "gcal_list_events" in names
        assert "gcal_find_free_slots" in names
        assert "gcal_create_event" not in names
        assert "gcal_update_event" not in names
        assert "gcal_delete_event" not in names

    def test_build_specialist_tools_excludes_all_gcal_when_disallowed(self) -> None:
        tools = build_specialist_tools(allowed_tools=["query_products"], ctx=_ctx())
        gcal_names = {t.name for t in tools if t.name.startswith("gcal_")}
        assert gcal_names == set()

    def test_build_all_five_gcal_tools_when_allowed(self) -> None:
        tools = build_specialist_tools(
            allowed_tools=list(GCAL_TOOLS),
            ctx=_ctx(),
        )
        names = {t.name for t in tools if t.name.startswith("gcal_")}
        assert names == set(GCAL_TOOLS)
