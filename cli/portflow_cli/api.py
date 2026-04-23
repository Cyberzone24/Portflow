"""Thin HTTP client for the Portflow API."""

from __future__ import annotations

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


def post_record_link(cfg: Config, payload: dict, timeout: float = 15.0) -> dict:
    r = requests.post(
        _url(cfg, "/api/cli/record_link"),
        json=payload,
        auth=(cfg.username, cfg.password),
        headers={"Accept": "application/json"},
        timeout=timeout,
        verify=cfg.verify_tls,
    )
    try:
        body = r.json()
    except ValueError:
        body = r.text
    if r.status_code >= 400:
        raise ApiError(r.status_code, body)
    return body if isinstance(body, dict) else {"raw": body}


def ping(cfg: Config, timeout: float = 8.0) -> tuple[bool, str]:
    """Cheap auth check by hitting the API.

    Returns (ok, message). On failure the message explains why so the user
    sees something more useful than "HTTP error".
    """
    url = _url(cfg, "/api/?table=device")
    try:
        r = requests.get(
            url,
            auth=(cfg.username, cfg.password),
            headers={"Accept": "application/json"},
            timeout=timeout,
            verify=cfg.verify_tls,
            allow_redirects=False,  # never follow into the HTML login page
        )
    except requests.RequestException as e:
        return False, f"network error contacting {url}: {e}"

    if r.status_code in (301, 302, 303, 307, 308):
        target = r.headers.get("Location", "?")
        return False, (
            f"server redirected ({r.status_code}) to {target}. "
            f"This usually means the URL is missing the app sub-path. "
            f"Try: --url {cfg.base_url.rstrip('/')}/Portflow-DEV  (or wherever Portflow is mounted)."
        )
    if r.status_code == 401:
        return False, "401 Unauthorized — username or password rejected."
    if r.status_code == 404:
        return False, f"404 Not Found at {url} — wrong --url or app not deployed there."
    if r.status_code >= 400:
        return False, f"HTTP {r.status_code}: {r.text[:200]}"
    # 2xx: confirm we actually reached the API (JSON), not some HTML page.
    ctype = (r.headers.get("Content-Type") or "").lower()
    if "json" not in ctype:
        return False, (
            f"server returned non-JSON ({ctype or 'unknown'}). "
            "The URL probably points at the web UI, not the API."
        )
    return True, "ok"
