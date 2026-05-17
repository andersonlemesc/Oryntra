from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
        extra="ignore",
    )

    internal_api_token: str = ""
    postgres_url: str = "postgresql://oryntra:oryntra_dev_pw@postgres:5432/oryntra"
    langgraph_checkpointer: str = "memory"
    log_level: str = "INFO"


settings = Settings()
