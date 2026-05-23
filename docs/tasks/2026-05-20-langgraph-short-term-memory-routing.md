# LangGraph Short-Term Memory Routing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `langgraph-persistence` and `langgraph-implementation` while implementing this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make supervisor conversations remember prior turns and keep the active specialist across related messages.

**Architecture:** LangGraph remains the source of short-term conversation state through its checkpointer and stable `thread_id`. The runtime stores an accumulated conversation history and `active_specialist_id` in `SupervisorState`; the supervisor only routes when no active specialist exists or when the active specialist signals reroute/low confidence.

**Tech Stack:** Python 3.12, FastAPI, LangGraph `StateGraph`, `PostgresSaver`/`InMemorySaver`, Pydantic, pytest.

---

## Files

- Modify: `agent-python/src/oryntra_agent/agent/supervisor.py`
  - Add thread-scoped conversation history and active specialist state.
  - Route to supervisor only when needed.
  - Pass recent conversation history to supervisor and specialist prompts.
- Modify: `agent-python/src/oryntra_agent/api/schemas.py`
  - Extend structured specialist decisions with reroute support if needed.
- Modify: `agent-python/tests/test_supervisor_runtime.py`
  - Add regression tests for active specialist persistence and repeated-question prevention.

## Tasks

### Task 1: Persist Conversation History In LangGraph State

- [x] **Step 1: Write a failing test**

Add a test to `agent-python/tests/test_supervisor_runtime.py`:

```python
def test_runtime_accumulates_conversation_history_for_same_thread() -> None:
    thread_id = "workspace:1:account:5:conversation:memory-history"

    run_chatwoot_runtime(supervisor_payload("Preciso comprar uma bike", thread_id=thread_id))
    run_chatwoot_runtime(
        supervisor_payload("Uso para ir ao trabalho cerca de 5km", thread_id=thread_id)
    )

    state = get_runtime_graph().get_state(runtime_config(supervisor_payload(thread_id=thread_id))).values

    assert [message["content"] for message in state["conversation_messages"]] == [
        "Preciso comprar uma bike",
        "Uso para ir ao trabalho cerca de 5km",
    ]
```

- [x] **Step 2: Run the test**

Run:

```bash
docker compose exec agent-python ./.venv/bin/pytest tests/test_supervisor_runtime.py::test_runtime_accumulates_conversation_history_for_same_thread -q
```

Expected: fails because `conversation_messages` does not exist.

- [x] **Step 3: Implement state accumulation**

In `supervisor.py`, add `conversation_messages` to `SupervisorState`, append new payload messages at the start of each run, and cap history to a small limit:

```python
MAX_CONVERSATION_MESSAGES = 20

class SupervisorState(TypedDict, total=False):
    payload: dict[str, Any]
    conversation_messages: list[dict[str, Any]]
    active_specialist_id: int | None
    selected_specialist: dict[str, Any] | None
    confidence: float
    reason: str
    response: dict[str, Any]
    turn_count: int
```

- [x] **Step 4: Verify**

Run the single test again. Expected: pass.

### Task 2: Keep Active Specialist Across Related Turns

- [x] **Step 1: Write a failing test**

Add:

```python
def test_runtime_reuses_active_specialist_for_followup_without_rerouting(monkeypatch) -> None:
    thread_id = "workspace:1:account:5:conversation:active-specialist"
    calls = []

    def fake_choice(payload):
        calls.append(payload.messages[-1].content)
        return SpecialistChoice(specialist_id=6, confidence=0.9, reason="initial_sales")

    monkeypatch.setattr(supervisor, "choose_specialist_with_llm", fake_choice)

    first = run_chatwoot_runtime(supervisor_payload("Preciso comprar uma bike", thread_id=thread_id))
    second = run_chatwoot_runtime(
        supervisor_payload("Tenho 1,72 e peso 79kg", thread_id=thread_id)
    )

    assert first.specialist_id == 6
    assert second.specialist_id == 6
    assert calls == ["Preciso comprar uma bike"]
    assert second.trace[1].output["reason"] == "active_specialist_continuation"
```

- [x] **Step 2: Run the test**

Run:

```bash
docker compose exec agent-python ./.venv/bin/pytest tests/test_supervisor_runtime.py::test_runtime_reuses_active_specialist_for_followup_without_rerouting -q
```

Expected: fails because every turn routes through supervisor.

- [x] **Step 3: Implement active specialist continuation**

Update `route_node`:

```python
active_specialist = specialist_by_id(payload, state.get("active_specialist_id"))
if active_specialist is not None and not latest_message_requests_reroute(payload, active_specialist):
    return {
        "selected_specialist": active_specialist.model_dump(mode="json"),
        "confidence": 1.0,
        "reason": "active_specialist_continuation",
        "turn_count": turn_count,
        "active_specialist_id": active_specialist.id,
    }
```

- [x] **Step 4: Verify**

Run the single test. Expected: pass.

### Task 3: Give Specialists The Conversation History

- [x] **Step 1: Write a failing test**

Add:

```python
def test_specialist_prompt_receives_recent_conversation_history(monkeypatch) -> None:
    thread_id = "workspace:1:account:5:conversation:specialist-history"
    captured = {}

    class FakeChatModel:
        def invoke(self, messages):
            captured["human"] = messages[1][1]
            return SpecialistDecision(
                action="respond_text",
                content="Com 1,72 e trajeto de 5km, recomendo modelo urbano.",
                confidence=0.9,
            )

        def with_structured_output(self, _schema):
            return self

    monkeypatch.setattr(
        supervisor,
        "chat_model_for_credential",
        lambda credential, temperature: FakeChatModel(),
    )

    payload = supervisor_payload("Preciso comprar uma bike", thread_id=thread_id)
    payload.specialists[1].llm_provider = "openai"
    payload.specialists[1].llm_model = "gpt-4.1-mini"
    payload.specialists[1].llm_api_key = SecretStr("sk-test")
    payload.supervisor.llm_provider = "openai"
    payload.supervisor.llm_model = "gpt-4.1-mini"
    payload.supervisor.llm_api_key = SecretStr("sk-test")
    run_chatwoot_runtime(payload)

    followup = supervisor_payload("Minha altura é 1,72", thread_id=thread_id)
    followup.specialists[1].llm_provider = "openai"
    followup.specialists[1].llm_model = "gpt-4.1-mini"
    followup.specialists[1].llm_api_key = SecretStr("sk-test")
    run_chatwoot_runtime(followup)

    assert "Preciso comprar uma bike" in captured["human"]
    assert "Minha altura é 1,72" in captured["human"]
```

- [x] **Step 2: Implement prompt history**

Change `specialist_decision_messages`, `specialist_response_messages`, and `supervisor_route_messages` to use recent `conversation_messages` from the active state rather than only `payload.messages`.

- [x] **Step 3: Verify**

Run:

```bash
docker compose exec agent-python ./.venv/bin/pytest tests/test_supervisor_runtime.py -q
```

Expected: all supervisor runtime tests pass.

### Task 4: Manual Validation Against Chatwoot Conversation 18

- [x] **Step 1: Restart runtime**

Run:

```bash
docker compose restart agent-python
docker compose restart laravel-horizon
```

- [x] **Step 2: Test with a fresh conversation**

Send:

```text
Preciso comprar uma bike
Uso para ir ao trabalho cerca de 5km
Minha altura é 1,72 tenho 79kg
Trajeto é asfalto
```

- [x] **Step 3: Verify database state**

Run:

```bash
docker compose exec laravel-app php artisan tinker --execute 'echo json_encode(App\Models\AgentRun::query()->latest("id")->limit(5)->get(["id","thread_id","status","output"])->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);'
```

Expected:

- Follow-up turns keep `specialist_id=6`.
- Later responses do not ask again for values already provided in earlier turns.
- LangGraph state has `conversation_messages` containing multiple user and assistant turns.

