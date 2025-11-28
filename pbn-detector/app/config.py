from functools import lru_cache
from pathlib import Path
import os
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    app_name: str = "PBN Detector"
    environment: str = "development"
    log_level: str = "INFO"

    redis_url: str | None = None
    redis_host: str | None = None
    redis_port: int = 6379
    redis_password: str | None = None
    redis_username: str | None = None
    redis_db: int = 0

    ipinfo_token: str | None = None

    classifier_model_path: str = "models/pbn_lr.joblib"
    minhash_threshold: float = 0.8
    high_risk_threshold: float = 0.75
    medium_risk_threshold: float = 0.5
    use_ensemble: bool = True
    use_enhanced_features: bool = True
    use_parallel_processing: bool = True
    parallel_workers: int = 4

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"
        case_sensitive = False
        env_prefix = ""

    def __init__(self, **kwargs):
        super().__init__(**kwargs)
        if not self.redis_url and self.redis_host:
            auth_part = ""
            if self.redis_username and self.redis_password:
                auth_part = f"{self.redis_username}:{self.redis_password}@"
            elif self.redis_password:
                auth_part = f":{self.redis_password}@"

            self.redis_url = f"redis://{auth_part}{self.redis_host}:{self.redis_port}/{self.redis_db}"


@lru_cache
def get_settings() -> Settings:
    return Settings()

