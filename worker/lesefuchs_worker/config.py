"""Zentrale Konfiguration über pydantic-settings (.env / Umgebungsvariablen)."""
from __future__ import annotations

from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="LF_", env_file=".env", env_file_encoding="utf-8", extra="ignore"
    )

    # Ollama
    ollama_url: str = "http://127.0.0.1:11434"
    ollama_model: str = "gemma3:12b"
    ollama_timeout_s: int = 300

    # Fish-Speech
    fish_url: str = "http://127.0.0.1:8080"
    fish_reference_id: str = ""
    fish_seed: int = 42
    fish_timeout_s: int = 600

    # Rücktranskriptions-Check
    verify_model: str = "small"
    verify_wer_threshold: float = 0.15
    verify_max_attempts: int = 3

    # Alignment
    align_device: str = "cuda"

    # Audio
    pause_between_paragraphs_ms: int = 350
    opus_bitrate: str = "24k"
    opus_sample_rate: int = 22050

    # Paket
    reading_level: int = 2
    voice_label: str = "fish-s2pro"
    language: str = "de-DE"

    # Verzeichnisse
    work_dir: Path = Path("work")
    out_dir: Path = Path("out")


def get_settings() -> Settings:
    return Settings()
