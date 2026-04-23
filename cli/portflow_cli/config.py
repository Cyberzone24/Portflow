"""Persistent configuration helpers (server URL + credentials)."""

from __future__ import annotations

import json
import os
from dataclasses import asdict, dataclass
from pathlib import Path

from platformdirs import user_config_dir, user_data_dir

APP_NAME = "portflow-cli"


def config_dir() -> Path:
    p = Path(user_config_dir(APP_NAME))
    p.mkdir(parents=True, exist_ok=True)
    return p


def data_dir() -> Path:
    p = Path(user_data_dir(APP_NAME))
    p.mkdir(parents=True, exist_ok=True)
    return p


CONFIG_PATH = lambda: config_dir() / "config.json"  # noqa: E731


@dataclass
class Config:
    base_url: str = ""
    username: str = ""
    # Password is stored in OS keyring when available, or here as a fallback
    # (only if the user explicitly opts in via `pfcli login --save-plain`).
    password: str = ""
    verify_tls: bool = True

    def save(self) -> None:
        data = asdict(self)
        # Never persist the password if the keyring is in use.
        if data.get("password"):
            try:
                import keyring  # type: ignore

                keyring.set_password(APP_NAME, self.username, data["password"])
                data["password"] = ""
            except Exception:
                # Fall back to plain storage with restrictive perms.
                pass
        path = CONFIG_PATH()
        path.write_text(json.dumps(data, indent=2))
        try:
            os.chmod(path, 0o600)
        except OSError:
            pass

    @classmethod
    def load(cls) -> "Config":
        path = CONFIG_PATH()
        if not path.exists():
            return cls()
        raw = json.loads(path.read_text() or "{}")
        cfg = cls(**{k: v for k, v in raw.items() if k in cls.__annotations__})
        if not cfg.password and cfg.username:
            try:
                import keyring  # type: ignore

                stored = keyring.get_password(APP_NAME, cfg.username)
                if stored:
                    cfg.password = stored
            except Exception:
                pass
        return cfg


def require_configured(cfg: Config) -> None:
    if not cfg.base_url or not cfg.username or not cfg.password:
        raise SystemExit(
            "Not configured yet. Run:  pfcli login --url https://portflow.example/ --user <name>"
        )
