from functools import lru_cache
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    app_name: str = "PBN Detector"
    environment: str = "development"
    log_level: str = "INFO"
    redis_url: str | None = None
    whois_base_url: str = "https://www.whoisxmlapi.com/whoisserver/WhoisService"
    whois_api_key: str | None = None
    ipinfo_token: str | None = None
    classifier_model_path: str = "models/pbn_lr.joblib"
    minhash_threshold: float = 0.8
    high_risk_threshold: float = 0.75
    medium_risk_threshold: float = 0.5

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"


@lru_cache
def get_settings() -> Settings:
    return Settings()

