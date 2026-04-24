"""Basic smoke test for Portflow web-auth reuse on API and CLI routes.

Usage:
    python cli/scripts/auth_smoke.py \
        --url http://portflow.local/Portflow-DEV \
        --user marius \
        --password secret

The test checks three things:
1. valid Basic Auth reaches the generic API without redirect/401
2. invalid Basic Auth is rejected with 401
3. valid Basic Auth reaches the CLI route and yields a JSON application error
   (typically 422 because the payload is intentionally incomplete), not a
   redirect or 401
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from portflow_cli.api import ping  # noqa: E402
from portflow_cli.config import Config  # noqa: E402


def build_config(args: argparse.Namespace, password: str) -> Config:
    return Config(
        base_url=args.url,
        username=args.user,
        password=password,
        verify_tls=not args.insecure,
    )


def format_body(response: requests.Response) -> str:
    try:
        body = response.json()
    except ValueError:
        body = response.text
    if isinstance(body, (dict, list)):
        return json.dumps(body, ensure_ascii=True)
    return str(body)


def assert_cli_route(cfg: Config) -> None:
    response = requests.post(
        cfg.base_url.rstrip("/") + "/api/cli/record_link",
        json={},
        auth=(cfg.username, cfg.password),
        headers={"Accept": "application/json"},
        timeout=10.0,
        verify=cfg.verify_tls,
        allow_redirects=False,
    )

    if response.status_code in (301, 302, 303, 307, 308):
        raise RuntimeError(
            f"CLI route redirected unexpectedly to {response.headers.get('Location', '?')}"
        )
    if response.status_code == 401:
        raise RuntimeError("CLI route rejected valid credentials with 401")
    if response.status_code < 400:
        raise RuntimeError(
            f"CLI route unexpectedly accepted empty payload: HTTP {response.status_code}"
        )

    content_type = (response.headers.get("Content-Type") or "").lower()
    if "json" not in content_type:
        raise RuntimeError(
            f"CLI route returned non-JSON content type: {content_type or 'unknown'}"
        )

    print(f"[ok] CLI route auth passed through to application logic: HTTP {response.status_code}")
    print(f"     body: {format_body(response)[:220]}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Smoke-test API and CLI Basic Auth")
    parser.add_argument("--url", required=True, help="Portflow base URL including sub-path")
    parser.add_argument("--user", required=True, help="Existing Portflow username")
    parser.add_argument("--password", required=True, help="Password for the given user")
    parser.add_argument("--insecure", action="store_true", help="Disable TLS verification")
    args = parser.parse_args()

    good_cfg = build_config(args, args.password)
    bad_cfg = build_config(args, args.password + "__wrong")

    ok, message = ping(good_cfg)
    if not ok:
        raise RuntimeError(f"Valid auth ping failed: {message}")
    print("[ok] Generic API accepted valid credentials")

    ok, message = ping(bad_cfg)
    if ok or "401 Unauthorized" not in message:
        raise RuntimeError(f"Invalid auth ping did not fail with 401: {message}")
    print("[ok] Generic API rejects invalid credentials with 401")

    assert_cli_route(good_cfg)
    print("[ok] Auth smoke test completed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())