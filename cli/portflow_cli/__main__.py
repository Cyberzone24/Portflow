"""Command line entry point: pfcli ..."""

from __future__ import annotations

import argparse
import re
import sys
from typing import Any

from rich import box
from rich.console import Console
from rich.prompt import Confirm, Prompt
from rich.table import Table

from . import __version__, api, config, lldp, queue

console = Console()

DEVICE_TYPES: list[tuple[str, str]] = [
    ("notebook", "Notebook"),
    ("desktop", "Desktop"),
    ("thinclient", "Thinclient"),
    ("phone", "Telefon"),
    ("printer", "Drucker"),
    ("accesspoint", "Accesspoint"),
    ("server", "Server"),
    ("switch", "Switch"),
    ("router", "Router"),
    ("firewall", "Firewall"),
    ("loadbalancer", "Loadbalancer"),
    ("storage", "Storage"),
    ("sensor", "Sensor"),
    ("ups", "USV"),
    ("pdu", "PDU"),
]
DEVICE_TYPE_LABELS = {value: label for value, label in DEVICE_TYPES}


def _device_label(device_type: str) -> str:
    normalized = str(device_type or "").strip().lower()
    return DEVICE_TYPE_LABELS.get(normalized, normalized or "Gerät")


def _pick_select(message: str, choices: list[tuple[str, str]], default: str | None = None) -> str | None:
    try:
        import questionary
        from questionary import Choice

        return questionary.select(
            message,
            choices=[Choice(title=title, value=value) for value, title in choices],
            default=default,
        ).ask()
    except Exception:
        console.print(f"[bold]{message}:[/]")
        for index, (value, title) in enumerate(choices, 1):
            marker = " *" if default is not None and value == default else "  "
            console.print(f" {marker}{index:>2}. {title}")
        answer = Prompt.ask("Auswahl", default=default or "")
        if answer.isdigit() and 1 <= int(answer) <= len(choices):
            return choices[int(answer) - 1][0]
        return answer or default


def _pick_device_type(default: str = "notebook") -> str:
    choices = [(value, f"{label} ({value})") for value, label in DEVICE_TYPES]
    return _pick_select("Gerätetyp", choices, default=default) or default


def _expand_outlet_ports(raw_outlet: str) -> list[str]:
    text = re.sub(r"\s+", "", str(raw_outlet or "").strip())
    if text == "":
        return []
    match = re.match(r"^(?P<prefix>.*?)(?P<start>\d+)-(?P<end>\d+)$", text)
    if not match:
        return [text]
    prefix = match.group("prefix")
    start_text = match.group("start")
    end_text = match.group("end")
    start = int(start_text)
    end = int(end_text)
    step = 1 if end >= start else -1
    width = max(len(start_text), len(end_text))
    return [f"{prefix}{number:0{width}d}" for number in range(start, end + step, step)]


def _infer_paired_outlet(raw_outlet: str) -> tuple[str, list[str]] | None:
    text = re.sub(r"\s+", "", str(raw_outlet or "").strip())
    match = re.match(r"^(?P<prefix>.*?)(?P<number>\d+)$", text)
    if text == "" or match is None:
        return None
    prefix = match.group("prefix")
    number_text = match.group("number")
    number = int(number_text)
    first = number if number % 2 == 1 else number - 1
    second = first + 1
    if first <= 0:
        return None
    width = len(number_text)
    ports = [f"{prefix}{first:0{width}d}", f"{prefix}{second:0{width}d}"]
    caption = f"{prefix}{first:0{width}d}-{second:0{width}d}"
    return caption, ports


def _normalize_outlet(raw_outlet: str, preferred_recorded_port: str | None = None) -> tuple[str, list[str], str]:
    text = re.sub(r"\s+", "", str(raw_outlet or "").strip())
    outlet_ports = _expand_outlet_ports(text)
    if not outlet_ports:
        return "", [], ""
    if len(outlet_ports) == 1:
        paired = _infer_paired_outlet(outlet_ports[0])
        if paired is not None:
            outlet_caption, outlet_ports = paired
            recorded_outlet_port = preferred_recorded_port or text or outlet_ports[0]
            if recorded_outlet_port not in outlet_ports:
                recorded_outlet_port = outlet_ports[0]
            return outlet_caption, outlet_ports, recorded_outlet_port
    recorded_outlet_port = preferred_recorded_port or outlet_ports[0]
    if recorded_outlet_port not in outlet_ports:
        recorded_outlet_port = outlet_ports[0]
    return text, outlet_ports, recorded_outlet_port


def _choose_recorded_port(outlet_ports: list[str], default: str | None = None) -> str:
    if not outlet_ports:
        return ""
    if len(outlet_ports) == 1:
        return outlet_ports[0]
    choices = [(port, port) for port in outlet_ports]
    picked = _pick_select(
        "Welcher Port der Dose wurde gerade aufgenommen?",
        choices,
        default=default or outlet_ports[0],
    )
    return picked or default or outlet_ports[0]


def _print_neighbour(neighbour: lldp.LldpNeighbour) -> None:
    table = Table(title="LLDP neighbour", box=box.SIMPLE_HEAVY, show_header=False)
    rows = [
        ("System name", neighbour.sys_name or "—"),
        ("Mgmt address", neighbour.mgmt_address or "—"),
        ("Chassis ID", neighbour.chassis_id or "—"),
        ("Port ID", neighbour.port_id or "—"),
        ("Port description", neighbour.port_desc or "—"),
        ("PVID", str(neighbour.pvid) if neighbour.pvid is not None else "—"),
    ]
    for key, value in rows:
        table.add_row(f"[bold]{key}[/]", value)
    console.print(table)


def _build_payload(
    room: str,
    outlet_caption: str,
    recorded_outlet_port: str,
    outlet_ports: list[str],
    neighbour: lldp.LldpNeighbour | None,
    expected_device: dict[str, Any] | None,
    force: bool,
) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "schema_version": 2,
        "room": room,
        "outlet_caption": outlet_caption,
        "recorded_outlet_port": recorded_outlet_port,
        "outlet_ports": outlet_ports,
        "reserved_outlet_ports": [port for port in outlet_ports if port != recorded_outlet_port],
        "lldp": neighbour.as_lldp_dict() if neighbour is not None else {},
    }
    if expected_device:
        payload["expected_device"] = expected_device
    if force:
        payload["force"] = True
    return payload


def _record_summary(record: queue.Record) -> dict[str, str]:
    payload = record.payload or {}
    lldp_data = payload.get("lldp", {}) if isinstance(payload, dict) else {}
    expected = payload.get("expected_device") if isinstance(payload, dict) else None
    outlet_ports = payload.get("outlet_ports") if isinstance(payload, dict) else None
    outlet_caption = str(payload.get("outlet_caption") or "")
    if outlet_caption == "" and isinstance(outlet_ports, list) and outlet_ports:
        outlet_caption = ", ".join(str(port) for port in outlet_ports)
    return {
        "id": str(record.id),
        "captured_at": record.captured_at,
        "room": str(payload.get("room") or ""),
        "outlet": outlet_caption,
        "recorded_port": str(payload.get("recorded_outlet_port") or payload.get("outlet_caption") or ""),
        "switch": str(lldp_data.get("sys_name") or lldp_data.get("mgmt_address") or ""),
        "switch_port": str(lldp_data.get("port_id") or lldp_data.get("port_desc") or ""),
        "device": str((expected or {}).get("caption") or ""),
        "status": record.status,
        "note": str(record.last_error or record.server_link or ""),
    }


def _print_record(record: queue.Record) -> None:
    summary = _record_summary(record)
    payload = record.payload or {}
    table = Table(title=f"Record #{record.id}", box=box.SIMPLE_HEAVY, show_header=False)
    table.add_row("[bold]Status[/]", summary["status"])
    table.add_row("[bold]Erfasst am[/]", summary["captured_at"])
    table.add_row("[bold]Raum[/]", summary["room"] or "—")
    table.add_row("[bold]Dose[/]", summary["outlet"] or "—")
    table.add_row("[bold]Aufgenommener Port[/]", summary["recorded_port"] or "—")
    table.add_row("[bold]Vorgemerkte Ports[/]", ", ".join(payload.get("reserved_outlet_ports") or []) or "—")
    table.add_row("[bold]Switch[/]", summary["switch"] or "—")
    table.add_row("[bold]Switch-Port[/]", summary["switch_port"] or "—")
    table.add_row("[bold]Endgerät[/]", summary["device"] or "—")
    table.add_row("[bold]Hinweis[/]", summary["note"] or "—")
    console.print(table)


def _pick_interface(preferred_iface: str | None) -> str | None:
    if preferred_iface:
        return preferred_iface
    interfaces = lldp.list_interfaces()
    if not interfaces:
        console.print("[red]No network interfaces detected.[/]")
        return None
    return _pick_select("Capture interface", [(iface, iface) for iface in interfaces], default=interfaces[0])


def _collect_expected_device(
    args: argparse.Namespace,
    *,
    existing: dict[str, Any] | None = None,
    interactive: bool = True,
) -> dict[str, Any] | None:
    arg_requested = any(value not in (None, "") for value in (args.expected_device, args.expected_type))
    if interactive and not arg_requested:
        if not Confirm.ask("Endgerät an dieser Dose erfassen?", default=existing is not None):
            return None
    elif not interactive and not arg_requested and existing is None:
        return None

    dtype_default = str(args.expected_type or (existing or {}).get("type") or "notebook")
    dtype = args.expected_type or (_pick_device_type(dtype_default) if interactive else dtype_default)
    caption_default = str(args.expected_device or (existing or {}).get("caption") or _device_label(dtype))
    caption = args.expected_device or (Prompt.ask("Gerätename", default=caption_default) if interactive else caption_default)
    caption = str(caption or "").strip()
    if caption == "":
        return None

    return {"caption": caption, "type": str(dtype).strip().lower()}


def _payload_from_record_interactive(record: queue.Record, args: argparse.Namespace) -> dict[str, Any]:
    payload = dict(record.payload or {})
    room = str(args.room or payload.get("room") or Prompt.ask("Raumnummer", default=str(payload.get("room") or ""))).strip()
    outlet_input = str(args.outlet or payload.get("recorded_outlet_port") or payload.get("outlet_caption") or Prompt.ask("Dose / Netzwerkport", default=str(payload.get("recorded_outlet_port") or payload.get("outlet_caption") or ""))).strip()
    outlet_caption, outlet_ports, recorded_outlet_port = _normalize_outlet(
        outlet_input,
        preferred_recorded_port=str(args.recorded_port or payload.get("recorded_outlet_port") or ""),
    )
    if not outlet_ports:
        raise SystemExit("Dose / Netzwerkport darf nicht leer sein.")
    if len(outlet_ports) > 1 and not args.recorded_port and recorded_outlet_port == outlet_ports[0] and outlet_input == outlet_caption:
        recorded_outlet_port = _choose_recorded_port(outlet_ports, recorded_outlet_port)
    elif len(outlet_ports) > 1 and not args.recorded_port and outlet_input == recorded_outlet_port:
        pass
    elif len(outlet_ports) > 1 and not args.recorded_port:
        recorded_outlet_port = _choose_recorded_port(outlet_ports, recorded_outlet_port)
    expected_device = _collect_expected_device(
        args,
        existing=payload.get("expected_device") if isinstance(payload.get("expected_device"), dict) else None,
        interactive=True,
    )

    payload.update(
        {
            "schema_version": 2,
            "room": room,
            "outlet_caption": outlet_caption,
            "recorded_outlet_port": recorded_outlet_port,
            "outlet_ports": outlet_ports,
            "reserved_outlet_ports": [port for port in outlet_ports if port != recorded_outlet_port],
        }
    )
    if expected_device is None:
        payload.pop("expected_device", None)
    else:
        payload["expected_device"] = expected_device
    return payload


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
    path = config.CONFIG_PATH()
    if path.exists():
        path.unlink()
    console.print("[green]✓[/] Logged out, config cleared.")
    return 0


def cmd_record(args: argparse.Namespace) -> int:
    room = str(args.room or Prompt.ask("Raumnummer", default="")).strip()
    if room == "":
        console.print("[red]Raumnummer required.[/]")
        return 1
    outlet_input = str(args.outlet or Prompt.ask("Dose / Netzwerkport", default="")).strip()
    if outlet_input == "":
        console.print("[red]Dose / Netzwerkport required.[/]")
        return 1
    outlet_caption, outlet_ports, recorded_outlet_port = _normalize_outlet(
        outlet_input,
        preferred_recorded_port=args.recorded_port,
    )
    if len(outlet_ports) > 1 and not args.recorded_port and outlet_input == outlet_caption:
        recorded_outlet_port = _choose_recorded_port(outlet_ports, recorded_outlet_port)
    if not recorded_outlet_port:
        console.print("[red]Kein aufgenommener Port gewählt.[/]")
        return 1

    expected_device = _collect_expected_device(args, interactive=True)
    wants_lldp = Confirm.ask(
        "LLDP-Scan für diesen Port ausführen?",
        default=expected_device is not None,
    )
    neighbour: lldp.LldpNeighbour | None = None
    if wants_lldp:
        iface = _pick_interface(args.iface)
        if not iface:
            return 2

        console.print(f"[cyan]Listening for LLDP on {iface} (timeout {args.timeout}s)…[/]")
        try:
            neighbour = lldp.capture(iface, timeout=args.timeout)
        except PermissionError as exc:
            console.print(
                f"[red]Permission denied: {exc}[/]\n"
                "On Linux: sudo setcap cap_net_raw,cap_net_admin=eip $(readlink -f $(which python3))"
            )
            return 2
        except TimeoutError as exc:
            console.print(f"[yellow]{exc}[/]")
            return 1
        _print_neighbour(neighbour)
    else:
        console.print("[dim]LLDP-Scan übersprungen.[/]")

    payload = _build_payload(
        room=room,
        outlet_caption=outlet_caption,
        recorded_outlet_port=recorded_outlet_port,
        outlet_ports=outlet_ports,
        neighbour=neighbour,
        expected_device=expected_device,
        force=args.force,
    )
    record_id = queue.enqueue(payload)
    console.print(f"[green]✓[/] Queued as record #{record_id}.")

    if args.sync:
        return cmd_sync(argparse.Namespace(only=record_id, dry_run=False))
    cfg = config.Config.load()
    if not (cfg.base_url and cfg.username and cfg.password):
        console.print("[yellow]Not logged in — record stored offline. Run `pfcli sync` later.[/]")
    return 0


def cmd_sync(args: argparse.Namespace) -> int:
    cfg = config.Config.load()
    config.require_configured(cfg)
    items = queue.pending()
    if args.only:
        items = [record for record in items if record.id == args.only]
    if not items:
        console.print("Nothing to sync.")
        return 0

    ok = 0
    fail = 0
    for record in items:
        summary = _record_summary(record)
        if args.dry_run:
            console.print(f"[dim]would sync #{record.id}: {summary['recorded_port'] or summary['outlet']}[/]")
            continue
        try:
            response = api.post_record_link(cfg, record.payload)
            queue.mark_synced(record.id, str(response.get("summary") or "ok"))
            console.print(f"[green]✓[/] #{record.id}  {response.get('summary') or 'ok'}")
            ok += 1
        except api.ApiError as exc:
            queue.mark_failed(record.id, f"HTTP {exc.status}: {exc.body}")
            console.print(f"[red]✗[/] #{record.id}  HTTP {exc.status}  {exc.body}")
            fail += 1
        except Exception as exc:
            queue.mark_failed(record.id, str(exc))
            console.print(f"[red]✗[/] #{record.id}  {exc}")
            fail += 1
    console.print(f"[bold]Done.[/] synced={ok} failed={fail}")
    return 0 if fail == 0 else 1


def cmd_status(_: argparse.Namespace) -> int:
    stats = queue.stats()
    cfg = config.Config.load()
    table = Table(box=box.SIMPLE_HEAVY)
    table.add_column("Field")
    table.add_column("Value")
    table.add_row("Server", cfg.base_url or "—")
    table.add_row("User", cfg.username or "—")
    table.add_row("Pending", str(stats.get("pending", 0)))
    table.add_row("Failed", str(stats.get("failed", 0)))
    table.add_row("Synced", str(stats.get("synced", 0)))
    table.add_row("Queue DB", queue.db_path())
    console.print(table)
    return 0


def cmd_list(args: argparse.Namespace) -> int:
    rows = queue.list_records(args.status)
    if not rows:
        console.print("No records.")
        return 0
    table = Table(box=box.SIMPLE_HEAVY)
    for column in ("ID", "When", "Room", "Outlet", "Recorded Port", "Switch", "Switch Port", "Status", "Device"):
        table.add_column(column)
    for record in rows:
        summary = _record_summary(record)
        table.add_row(
            summary["id"],
            summary["captured_at"],
            summary["room"] or "—",
            summary["outlet"] or "—",
            summary["recorded_port"] or "—",
            summary["switch"] or "—",
            summary["switch_port"] or "—",
            summary["status"],
            summary["device"] or "—",
        )
    console.print(table)
    return 0


def cmd_show(args: argparse.Namespace) -> int:
    record = queue.get_record(args.record_id)
    if record is None:
        console.print(f"[red]Record #{args.record_id} not found.[/]")
        return 1
    _print_record(record)
    return 0


def cmd_edit(args: argparse.Namespace) -> int:
    record = queue.get_record(args.record_id)
    if record is None:
        console.print(f"[red]Record #{args.record_id} not found.[/]")
        return 1
    updated_payload = _payload_from_record_interactive(record, args)
    queue.update_payload(record.id, updated_payload, status="pending")
    console.print(f"[green]✓[/] Record #{record.id} updated and reset to pending.")
    return 0


def cmd_delete(args: argparse.Namespace) -> int:
    record = queue.get_record(args.record_id)
    if record is None:
        console.print(f"[red]Record #{args.record_id} not found.[/]")
        return 1
    if not args.yes:
        _print_record(record)
        if not Confirm.ask(f"Record #{record.id} wirklich löschen?", default=False):
            console.print("Aborted.")
            return 1
    if not queue.delete_record(record.id):
        console.print(f"[red]Record #{record.id} could not be deleted.[/]")
        return 1
    console.print(f"[green]✓[/] Deleted record #{record.id}.")
    return 0


def cmd_purge(_: argparse.Namespace) -> int:
    count = queue.purge_synced()
    console.print(f"Removed {count} synced record(s).")
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="pfcli", description="Portflow on-site CLI")
    parser.add_argument("--version", action="version", version=f"pfcli {__version__}")
    sub = parser.add_subparsers(dest="cmd", required=True)

    login_parser = sub.add_parser("login", help="Configure server URL + credentials")
    login_parser.add_argument("--url")
    login_parser.add_argument("--user")
    login_parser.add_argument("--insecure", action="store_true", help="Disable TLS verification")
    login_parser.set_defaults(func=cmd_login)

    logout_parser = sub.add_parser("logout", help="Forget stored credentials")
    logout_parser.set_defaults(func=cmd_logout)

    record_parser = sub.add_parser("record", help="Capture LLDP and queue a new link record")
    record_parser.add_argument("--iface", help="Network interface to listen on")
    record_parser.add_argument("--timeout", type=int, default=120)
    record_parser.add_argument("--room", help="Room caption, e.g. 9.440")
    record_parser.add_argument("--outlet", help="Network outlet caption or paired outlet, e.g. 1.4/G.15-16")
    record_parser.add_argument("--recorded-port", help="Which concrete outlet port was recorded")
    record_parser.add_argument("--expected-device", help="Caption of the device normally on this port")
    record_parser.add_argument("--expected-type", default=None)
    record_parser.add_argument("--sync", action="store_true", help="Sync immediately after capture")
    record_parser.add_argument("--force", action="store_true", help="Overwrite conflicting connections on the server")
    record_parser.set_defaults(func=cmd_record)

    sync_parser = sub.add_parser("sync", help="Push queued records to the server")
    sync_parser.add_argument("--only", type=int, help="Sync just this record id")
    sync_parser.add_argument("--dry-run", action="store_true")
    sync_parser.set_defaults(func=cmd_sync)

    status_parser = sub.add_parser("status", help="Show queue and config summary")
    status_parser.set_defaults(func=cmd_status)

    list_parser = sub.add_parser("list", help="List queued records")
    list_parser.add_argument("--status", choices=["pending", "failed", "synced"])
    list_parser.set_defaults(func=cmd_list)

    show_parser = sub.add_parser("show", help="Show one queued record in detail")
    show_parser.add_argument("record_id", type=int)
    show_parser.set_defaults(func=cmd_show)

    edit_parser = sub.add_parser("edit", help="Edit a queued record locally")
    edit_parser.add_argument("record_id", type=int)
    edit_parser.add_argument("--room")
    edit_parser.add_argument("--outlet")
    edit_parser.add_argument("--recorded-port")
    edit_parser.add_argument("--expected-device")
    edit_parser.add_argument("--expected-type")
    edit_parser.set_defaults(func=cmd_edit)

    delete_parser = sub.add_parser("delete", help="Delete a queued record locally")
    delete_parser.add_argument("record_id", type=int)
    delete_parser.add_argument("--yes", action="store_true", help="Delete without confirmation")
    delete_parser.set_defaults(func=cmd_delete)

    purge_parser = sub.add_parser("purge", help="Delete records that have been synced")
    purge_parser.set_defaults(func=cmd_purge)

    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    return int(args.func(args) or 0)


if __name__ == "__main__":
    sys.exit(main())
