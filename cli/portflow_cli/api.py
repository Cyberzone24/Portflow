"""Thin HTTP client for the Portflow API."""

from __future__ import annotations

from contextlib import AbstractContextManager
from typing import Any

import requests

from .config import Config


class ApiError(RuntimeError):
    def __init__(self, status: int, body: Any):
        super().__init__(f"HTTP {status}: {body}")
        self.status = status
        self.body = body


def _url(cfg: Config, path: str) -> str:
    base = cfg.base_url.rstrip("/")
    if not path.startswith("/"):
        path = "/" + path
    return base + path


def _decode_response_body(response: requests.Response) -> Any:
    try:
        return response.json()
    except ValueError:
        return response.text


class ApiClient(AbstractContextManager["ApiClient"]):
    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.session = requests.Session()
        self.session.auth = (cfg.username, cfg.password)
        self.session.headers.update({"Accept": "application/json"})
        self.session.verify = cfg.verify_tls

    def __enter__(self) -> "ApiClient":
        return self

    def __exit__(self, exc_type, exc, exc_tb) -> None:
        self.close()

    def close(self) -> None:
        self.session.close()

    def post_record_link(self, payload: dict, timeout: float = 15.0) -> dict:
        response = self.session.post(
            _url(self.cfg, "/api/cli/record_link"),
            json=payload,
            timeout=timeout,
        )
        body = _decode_response_body(response)
        if response.status_code >= 400:
            raise ApiError(response.status_code, body)
        return body if isinstance(body, dict) else {"raw": body}

    def ping(self, timeout: float = 8.0) -> tuple[bool, str]:
        """Authenticate once and keep the API session cookie for follow-up requests."""
        url = _url(self.cfg, "/api/?table=device")
        try:
            response = self.session.get(
                url,
                timeout=timeout,
                allow_redirects=False,
            )
        except requests.RequestException as e:
            return False, f"network error contacting {url}: {e}"

        if response.status_code in (301, 302, 303, 307, 308):
            target = response.headers.get("Location", "?")
            return False, (
                f"server redirected ({response.status_code}) to {target}. "
                f"This usually means the URL is missing the app sub-path. "
                f"Try: --url {self.cfg.base_url.rstrip('/')}/Portflow-DEV  (or wherever Portflow is mounted)."
            )
        if response.status_code == 401:
            return False, "401 Unauthorized — username or password rejected."
        if response.status_code == 404:
            return False, f"404 Not Found at {url} — wrong --url or app not deployed there."
        if response.status_code >= 400:
            return False, f"HTTP {response.status_code}: {response.text[:200]}"
        ctype = (response.headers.get("Content-Type") or "").lower()
        if "json" not in ctype:
            return False, (
                f"server returned non-JSON ({ctype or 'unknown'}). "
                "The URL probably points at the web UI, not the API."
            )
        return True, "ok"


def post_record_link(cfg: Config, payload: dict, timeout: float = 15.0) -> dict:
    with ApiClient(cfg) as client:
        return client.post_record_link(payload, timeout=timeout)


def ping(cfg: Config, timeout: float = 8.0) -> tuple[bool, str]:
    """Cheap auth check by hitting the API."""
    with ApiClient(cfg) as client:
        return client.ping(timeout=timeout)
