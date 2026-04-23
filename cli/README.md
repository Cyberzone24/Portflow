# Portflow CLI (`pfcli`)

Lightweight on-site tool for mapping office wall outlets to their upstream
switch ports. Plug a laptop into the outlet, listen for one LLDP frame from
the switch, type the outlet label, optionally note which device normally
lives there — done. Records are buffered locally and synced to the Portflow
server when the laptop is back inside the corporate firewall.

```
laptop ──[LLDP]── switch                    Portflow server
   │                                              ▲
   │   pfcli record (offline OK, queued)          │
   │   …                                          │   pfcli sync
   └──────────────── SQLite queue ────────────────┘
```

## Install (development)

### Linux / macOS

```bash
cd cli
python3 -m venv .venv && source .venv/bin/activate
python -m pip install --upgrade pip          # editable installs need pip ≥ 21.3
pip install -e .
```

Linux additionally needs raw-socket capability for the Python interpreter
(one-time):

```bash
sudo setcap cap_net_raw,cap_net_admin=eip "$(readlink -f "$(which python3)")"
```

### Windows

1. Install [Npcap](https://npcap.com/) with the "WinPcap API-compatible mode"
   checkbox enabled (required for scapy to capture LLDP frames).
2. Make sure you have **Python 3.10 or newer** installed. The CLI uses
   modern type-hint syntax (`str | None`, `list[...]`) and will refuse to
   install on older versions with:
   `ERROR: Package 'portflow-cli' requires a different Python: 3.9.x not in '>=3.10'`.

   Get a current build from <https://www.python.org/downloads/windows/> (tick
   *"Add python.exe to PATH"* during setup) or via winget:

   ```powershell
   cdcdcd
   ```

   Then verify:

   ```powershell
   py -3.12 --version
   ```

3. Open a PowerShell or `cmd` **as Administrator** (raw-socket capture needs
   it on Windows) and run:

   ```powershell
   cd path\to\Portflow-DEV\cli
   python -m venv .venv
   .\.venv\Scripts\activate
   python -m pip install --upgrade pip setuptools wheel
   pip install -e .
   ```

   > Important: `cd` into the **`cli`** subfolder before `pip install -e .`.
   > Running it from the repo root yields
   > `ERROR: File "setup.py" or "setup.cfg" not found` because the
   > `pyproject.toml` lives in `cli/`.
   >
   > If you still see that error after `cd cli`, your pip is too old for
   > PEP 660 editable installs — the `python -m pip install --upgrade pip`
   > step above fixes it.

## Usage

```bash
# 1) one-time login (credentials stored in OS keyring, fallback file is chmod 600)
#    --url must point at the Portflow app root, i.e. the URL where you reach
#    the web UI. If the app lives in a sub-path (typical for Apache vhosts),
#    include it -- e.g. http://portflow.local/Portflow-DEV
pfcli login --url http://portflow.local/Portflow-DEV --user marius

# 2) on site, plug into outlet, run capture
pfcli record --iface eth0 --outlet 1.4/I.18
# ...prompts for the "expected device" if you didn't pass --expected-device

# Want to capture many in a row without server contact?
#   pfcli record --iface eth0 --outlet 1.OG-12-A
#   pfcli record --iface eth0 --outlet 1.OG-12-B
#   pfcli record --iface eth0 --outlet 1.OG-12-C
# Each is queued in SQLite at the OS data dir.

# 3) at end of day, back on the corp network:
pfcli sync

# Other useful commands:
pfcli status            # config + queue summary
pfcli list              # all records
pfcli list --status failed
pfcli purge             # remove already-synced records
```

### Non-interactive (e.g. for scripting)

```bash
pfcli record \
    --iface eth0 \
    --outlet 1.OG-12-A \
    --expected-device PC-OFFICE-12 \
    --expected-type computer \
    --expected-mac aa:bb:cc:dd:ee:ff \
    --comment "rolled out 2026-04-23" \
    --sync
```

## Server side

The CLI talks to two endpoints:

| Method | URL                       | Purpose                                                                 |
|-------:|---------------------------|-------------------------------------------------------------------------|
| GET    | `/api/?table=device`      | Auth ping (HTTP Basic against the existing user account)                |
| POST   | `/api/cli/record_link`    | Upsert patchpanel↔switch connection + optional expected end-device      |

Authentication is HTTP Basic, validated against the `users` table (same
`password_hash` that the web UI uses). No new account or token is required.

`/api/cli/record_link` resolves:

1. **Patchpanel port** by `outlet_caption` (matches against `device_port`
   captions; if only the `net_outlet` matches, walks the existing
   net_outlet↔patchpanel connection).
2. **Switch device** by `lldp.sys_name`, `lldp.mgmt_address`, then
   `lldp.chassis_id` (MAC).
3. **Switch port** by `lldp.port_id` / `lldp.port_desc` against the device's
   port captions.

If the switch or its port cannot be matched the call returns 404 — the CLI
keeps the record in `failed` state so you can resolve naming and re-sync.

A pre-existing different connection on either side returns 409. Re-run with
`pfcli record … --force` to overwrite.

## File locations

| Purpose       | Linux                                       | Windows                                      |
|---------------|---------------------------------------------|----------------------------------------------|
| Config        | `~/.config/portflow-cli/config.json`        | `%APPDATA%\portflow-cli\config.json`         |
| Offline queue | `~/.local/share/portflow-cli/queue.sqlite3` | `%LOCALAPPDATA%\portflow-cli\queue.sqlite3`  |

## Packaging single binaries

```bash
pip install pyinstaller
pyinstaller --onefile -n pfcli portflow_cli/__main__.py
```

Resulting `dist/pfcli` (or `dist/pfcli.exe`) runs without a Python install,
but still needs raw-socket privileges for LLDP capture.
