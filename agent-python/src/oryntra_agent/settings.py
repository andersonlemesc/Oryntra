from pydantic import model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
        extra="ignore",
    )

    internal_api_token: str = ""
    laravel_internal_base_url: str = "http://laravel-app"
    agent_runtime_internal_token: str = ""
    postgres_url: str = "postgresql://oryntra:oryntra_dev_pw@postgres:5432/oryntra"
    langgraph_checkpointer: str = "memory"
    log_level: str = "INFO"

    # Max concurrent agent runs executed per uvicorn worker process. With N
    # workers the effective cap is N * agent_max_concurrency (the semaphore is
    # per-process, not shared across workers).
    agent_max_concurrency: int = 8
    # Timeout (seconds) for posting the run result back to Laravel.
    callback_timeout_seconds: float = 10.0
    # Load-test only: inject artificial latency into a run to emulate the
    # external LLM call without real BYOK keys. 0 disables it.
    agent_fake_latency_ms: int = 0

    @model_validator(mode="after")
    def fallback_agent_runtime_token(self) -> "Settings":
        if self.agent_runtime_internal_token == "":
            self.agent_runtime_internal_token = self.internal_api_token

        return self


settings = Settings()
