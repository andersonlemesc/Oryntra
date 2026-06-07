import pytest

from oryntra_agent import settings as settings_module
from oryntra_agent.agent import supervisor
from oryntra_agent.agent.supervisor import get_runtime_graph

# Pristine values captured before any test mutates the shared settings singleton.
_PRISTINE_POSTGRES_URL = settings_module.settings.postgres_url


@pytest.fixture(autouse=True)
def _isolate_runtime_state():
    """Reset the process-global runtime graph cache and checkpointer state.

    ``get_runtime_graph`` is ``lru_cache``d and the Postgres checkpointer pool is
    stored in a module global, so a test that switches to the Postgres
    checkpointer (or accumulates conversation state under a fixed thread_id) would
    otherwise leak into later tests. Forcing the in-memory checkpointer before and
    after each test keeps them independent.
    """

    def reset() -> None:
        get_runtime_graph.cache_clear()
        settings_module.settings.langgraph_checkpointer = "memory"
        settings_module.settings.postgres_url = _PRISTINE_POSTGRES_URL
        supervisor.close_runtime_checkpointer()

    reset()
    yield
    reset()
