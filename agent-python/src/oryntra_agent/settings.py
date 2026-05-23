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

    @model_validator(mode="after")
    def fallback_agent_runtime_token(self) -> "Settings":
        if self.agent_runtime_internal_token == "":
            self.agent_runtime_internal_token = self.internal_api_token

        return self


settings = Settings()
