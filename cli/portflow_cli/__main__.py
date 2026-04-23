"""Command line entry point: pfcli ..."""

from __future__ import annotations

import argparse
import sys
from typing import Any

from rich import box
from rich.console import Console
from rich.prompt import Confirm, Prompt
from rich.table import Table

from . import __version__, api, config, lldp, queue

console = Console()

# Device types offered when recording an "expected end device". Mirrors the
# dropdown in includes/forms.json so the CLI matches the web UI.
DEVICE_TYPES: list[tuple[str, str]] = [
    ("notebook",     "Notebook"),
    ("desktop",      "Desktop"),
    ("thinclient",   "Thinclient"),
    ("phone",        "Phone"),
    ("printer",      "Printer"),
    ("accesspoint",  "Accesspoint"),
    ("server",       "Server"),
    ("switch",       "Switch"),
    ("router",       "Router"),
    ("firewall",     "Firewall"),
    ("loadbalancer", "Loadbalancer"),
    ("storage",      "Storage"),
    ("sensor",       "Sensor"),
    ("ups",          "USV (UPS)"),
    ("pdu",          "PDU"),
]


def _pick_device_type(default: str = "notebook") -> str:
    """Interactive picker for device type. Falls back to numbered list if
    questionary is unavailable or stdin is not a TTY."""
    try:
        import questionary
        from questionary import Choice

        choices = [
            Choice(title=f"{label}  ({value})", value=value)
            for value, label in DEVICE_TYPES
        ]
        picked = questionary.select(
            "Device type", choices=choices, default=default
        ).ask()
        return picked or default
    except Exception:
        console.print("[bold]Device type:[/]")
        for i, (val, label) in enumerate(DEVICE_TYPES, 1):
            marker = " *" if val == default else "  "
            console.print(f" {marker}{i:>2}. {label}  [dim]({val})[/]")
        ans = Prompt.ask("Choose number or value", default=default)
        if ans.isdigit() and 1 <= int(ans) <= len(DEVICE_TYPES):
            return DEVICE_TYPES[int(ans) - 1][0]
        return ans or default


# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------
def _print_neighbour(n: lldp.LldpNeighbour) -> None:
    t = Table(title="LLDP neighbour", box=box.SIMPLE_HEAVY, show_header=False)
    rows = [
        ("System name", n.sys_name or "—"),
        ("Mgmt address", n.mgmt_address or "—"),
        ("Chassis ID", n.chassis_id or "—"),
        ("Port ID", n.port_id or "—"),
        ("Port description", n.port_desc or "—"),
        ("PVID", str(n.pvid) if n.pvid is not None else "—"),
    ]
    for k, v in rows:
        t.add_row(f"[bold]{k}[/]", v)
    console.print(t)


def _build_payload(
    outlet: str,
    n: lldp.LldpNeighbour,
    expected_device: dict | None,
    comment: str,
    force: bool,
) -> dict:
    payload: dict[str, Any] = {
        "outlet_caption": outlet,
        "lldp": n.as_lldp_dict(),
        "comment": comment,
    }
    if expected_device:
        payload["expected_device"] = expected_device
    if force:
        payload["force"] = True
    return payload


# ---------------------------------------------------------------------------
# subcommands
# ---------------------------------------------------------------------------
def cmd_login(args: argparse.Namespace) -> int:
    cfg = config.Config.load()
    if args.url:
        cfg.base_url = args.url.rstrip("/")
    if args.user:
        cfg.username = args.user
    if not cfg.base_url:
        cfg.base_url = Prompt.ask("Portflow base URL").rstrip("/")
    if not cfg.username:
        cfg.username = Prompt.ask("Username")
    cfg.password = Prompt.ask("Password", password=True)
    cfg.verify_tls = not args.insecure
    ok, msg = api.ping(cfg)
    if ok:
        cfg.save()
        console.print(f"[green]✓[/] Login OK as {cfg.username} @ {cfg.base_url}")
        return 0
    console.print(f"[red]✗ Login failed:[/] {msg}")
    console.print("[dim]Credentials not saved.[/]")
    return 2


def cmd_logout(_: argparse.Namespace) -> int:
    cfg = config.Config.load()
    if cfg.username:
        try:
            import keyring  # type: ignore

            keyring.delete_password(config.APP_NAME, cfg.username)
        except Exception:
            pass
    p = config.CONFIG_PATH()
    if p.exists():
        p.unlink()
    console.print("[green]✓[/] Logged out, config cleared.")
    return 0


def cmd_record(args: argparse.Namespace) -> int:
    cfg = config.Config.load()
    iface = args.iface
    if not iface:
        ifaces = lldp.list_interfaces()
        if not ifaces:
            console.print("[red]No network interfaces detected.[/]")
            return 2
        try:
            import questionary
        except ImportError:
            iface = ifaces[0]
        else:
            iface = questionary.select("Select capture interface", choices=ifaces).ask()
            if not iface:
                return 1

    console.print(f"[cyan]Listening for LLDP on {iface} (timeout {args.timeout}s)…[/]")
    try:
        n = lldp.capture(iface, timeout=args.timeout)
    except PermissionError as e:
        console.print(f"[red]Permission denied: {e}[/]\nOn Linux: sudo setcap cap_net_raw,cap_net_admin=eip $(readlink -f $(which python3))")
        return 2
    except TimeoutError as e:
        console.print(f"[yellow]{e}[/]")
        return 1
    _print_neighbour(n)

    outlet = args.outlet or Prompt.ask("Outlet / patchpanel port caption")
    if not outlet:
        console.print("[red]outlet caption required[/]")
        return 1

    expected: dict | None = None
    if args.expected_device or Confirm.ask(
        "Add an expected end device for this outlet?", default=False
    ):
        cap = args.expected_device or Prompt.ask("Device caption (e.g. PC-OFFICE-12)")
        if cap:
            dtype = args.expected_type or _pick_device_type()
            mac = args.expected_mac or Prompt.ask("MAC (optional)", default="")
            expected = {"caption": cap, "type": dtype}
            if mac:
                expected["mac"] = mac

    comment = args.comment or Prompt.ask("Comment (optional)", default="")
    payload = _build_payload(outlet, n, expected, comment, args.force)

    rec_id = queue.enqueue(payload)
    console.print(f"[green]✓[/] Queued as record #{rec_id}.")

    if args.sync:
        return cmd_sync(argparse.Namespace(only=rec_id, dry_run=False))
    if cfg.base_url and cfg.username and cfg.password:
        if Confirm.ask("Try to sync now?", default=True):
            return cmd_sync(argparse.Namespace(only=rec_id, dry_run=False))
    else:
        console.print("[yellow]Not logged in — record stored offline. Run `pfcli sync` later.[/]")
    return 0


def cmd_sync(args: argparse.Namespace) -> int:
    cfg = config.Config.load()
    config.require_configured(cfg)
    items = queue.pending()
    if args.only:
        items = [r for r in items if r.id == args.only]
    if not items:
        console.print("Nothing to sync.")
        return 0
    ok = 0
    fail = 0
    for r in items:
        if args.dry_run:
            console.print(f"[dim]would sync #{r.id}: {r.payload.get('outlet_caption')}[/]")
            continue
        try:
            res = api.post_record_link(cfg, r.payload)
            queue.mark_synced(r.id, str(res.get("summary") or "ok"))
            console.print(f"[green]✓[/] #{r.id}  {res.get('summary') or 'ok'}")
            ok += 1
        except api.ApiError as e:
            queue.mark_failed(r.id, f"HTTP {e.status}: {e.body}")
            console.print(f"[red]✗[/] #{r.id}  HTTP {e.status}  {e.body}")
            fail += 1
        except Exception as e:
            queue.mark_failed(r.id, str(e))
            console.print(f"[red]✗[/] #{r.id}  {e}")
            fail += 1
    console.print(f"[bold]Done.[/] synced={ok} failed={fail}")
    return 0 if fail == 0 else 1


def cmd_status(_: argparse.Namespace) -> int:
    s = queue.stats()
    cfg = config.Config.load()
    t = Table(box=box.SIMPLE_HEAVY)
    t.add_column("Field"); t.add_column("Value")
    t.add_row("Server", cfg.base_url or "—")
    t.add_row("User",   cfg.username or "—")
    t.add_row("Pending", str(s.get("pending", 0)))
    t.add_row("Failed",  str(s.get("failed", 0)))
    t.add_row("Synced",  str(s.get("synced", 0)))
    t.add_row("Queue DB", queue.db_path())
    console.print(t)
    return 0


def cmd_list(args: argparse.Namespace) -> int:
    rows = queue.list_records(args.status)
    if not rows:
        console.print("No records.")
        return 0
    t = Table(box=box.SIMPLE_HEAVY)
    for c in ("ID", "When", "Outlet", "Switch", "Port", "Status", "Note"):
        t.add_column(c)
    for r in rows:
        lldp_d = r.payload.get("lldp", {})
        t.add_row(
            str(r.id),
            r.captured_at,
            str(r.payload.get("outlet_caption", "")),
            str(lldp_d.get("sys_name") or lldp_d.get("mgmt_address", "")),
            str(lldp_d.get("port_id") or lldp_d.get("port_desc", "")),
            r.status,
            (r.last_error or r.server_link or "")[:60],
        )
    console.print(t)
    return 0


def cmd_purge(_: argparse.Namespace) -> int:
    n = queue.purge_synced()
    console.print(f"Removed {n} synced record(s).")
    return 0


# ---------------------------------------------------------------------------
# parser
# ---------------------------------------------------------------------------
def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="pfcli", description="Portflow on-site CLI")
    p.add_argument("--version", action="version", version=f"pfcli {__version__}")
    sub = p.add_subparsers(dest="cmd", required=True)

    pl = sub.add_parser("login", help="Configure server URL + credentials")
    pl.add_argument("--url"); pl.add_argument("--user")
    pl.add_argument("--insecure", action="store_true", help="Disable TLS verification")
    pl.set_defaults(func=cmd_login)

    po = sub.add_parser("logout", help="Forget stored credentials")
    po.set_defaults(func=cmd_logout)

    pr = sub.add_parser("record", help="Capture LLDP and queue a new link record")
    pr.add_argument("--iface", help="Network interface to listen on")
    pr.add_argument("--timeout", type=int, default=120)
    pr.add_argument("--outlet", help="Outlet / patchpanel caption")
    pr.add_argument("--expected-device", help="Caption of the device normally on this port")
    pr.add_argument("--expected-type", default=None)
    pr.add_argument("--expected-mac", default=None)
    pr.add_argument("--comment", default="")
    pr.add_argument("--sync", action="store_true", help="Sync immediately after capture")
    pr.add_argument("--force", action="store_true", help="Overwrite conflicting connections on the server")
    pr.set_defaults(func=cmd_record)

    ps = sub.add_parser("sync", help="Push queued records to the server")
    ps.add_argument("--only", type=int, help="Sync just this record id")
    ps.add_argument("--dry-run", action="store_true")
    ps.set_defaults(func=cmd_sync)

    pst = sub.add_parser("status", help="Show queue and config summary")
    pst.set_defaults(func=cmd_status)

    pls = sub.add_parser("list", help="List queued records")
    pls.add_argument("--status", choices=["pending", "failed", "synced"])
    pls.set_defaults(func=cmd_list)

    pp = sub.add_parser("purge", help="Delete records that have been synced")
    pp.set_defaults(func=cmd_purge)

    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    return int(args.func(args) or 0)


if __name__ == "__main__":
    sys.exit(main())
