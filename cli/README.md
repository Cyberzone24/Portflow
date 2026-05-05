# Portflow CLI (`pfcli`)

Lightweight on-site tool for mapping office wall outlets in a room. Plug a
laptop into the outlet, listen for one LLDP frame from the switch, enter the
room and outlet label, optionally note the office end device, and sync later.
Records are buffered locally and can be reviewed, edited, or deleted before
they are pushed to the Portflow server.

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
pfcli record --iface eth0 --room 9.440 --outlet 1.4/I.18
# ...prompts for the office end device if you want to store it

# Want to capture many in a row without server contact?
#   pfcli record --iface eth0 --room 9.440 --outlet 1.OG-12-A
#   pfcli record --iface eth0 --room 9.440 --outlet 1.OG-12-B
#   pfcli record --iface eth0 --room 9.440 --outlet 1.OG-12-C
# Each record stays editable in SQLite until sync.

# 3) at end of day, back on the corp network:
pfcli sync

# Other useful commands:
pfcli status            # config + queue summary
pfcli list              # all records
pfcli list --status failed
pfcli show 12           # inspect one local record in detail
pfcli edit 12           # re-edit all local data except the saved LLDP scan before retrying sync
pfcli delete 12         # remove a wrong local record
pfcli purge             # remove already-synced records
```

### Non-interactive (e.g. for scripting)

```bash
pfcli record \
    --iface eth0 \
   --room 9.440 \
   --outlet 1.4/G.15 \
   --expected-device Drucker \
   --expected-type printer \
    --sync
```

### Recording flow

`pfcli record` now behaves as follows:

1. Captures one LLDP neighbour on the selected interface.
2. Requires a room via `--room` or prompt input.
3. Requires an outlet label via `--outlet` or prompt input.
4. A single office port such as `1.4/G.15` is automatically normalised to the
   shared wall outlet `1.4/G.15-16`, so ports `15` and `16` land on the same
   `net_outlet` device.
5. If the outlet is entered directly as a two-port wall plate such as
   `1.4/G.15-16`, the CLI expands both concrete outlet ports and asks which one
   was just recorded.
6. If you choose to store an office device, the CLI asks for the device type
   first and then proposes the German short type label, such as `Drucker` or
   `Notebook`, as the default device name.
7. Before starting LLDP capture, the CLI asks whether an LLDP scan should be run at all. This avoids waiting for the timeout on deliberately empty ports.
8. If LLDP data is available, sync also resolves the upstream switch and creates the patchpanel-to-switch link alongside the patchpanel-to-room-outlet link.
9. The record is queued locally. There is no extra sync prompt after capture.

### Local editing and failed syncs

- Failed sync records stay in the local queue and can be corrected with `pfcli edit <id>`.
- `pfcli edit <id>` re-prompts all locally editable fields except LLDP data: room, outlet / recorded port, optional office device, and the overwrite flag.
- `pfcli show <id>` prints the stored room, outlet, recorded port, reserved sister port, and office device.
- If LLDP was skipped, the switch and switch-port fields remain empty in the local record and in `pfcli list`.
- `pfcli delete <id>` removes wrong local entries.
- Editing a record resets it to `pending`, so `pfcli sync` will retry it.

### Outlet and overwrite semantics

- The synced CLI data is treated as the authoritative source.
- If an existing physical switch-side, room-side, or expected office-device connection conflicts with the recorded data, the server replaces only the incompatible leg during sync and preserves the allowed second leg on passthrough ports.
- If the network outlet does not exist yet, the server creates it directly in the given room.
- Network outlets are modelled as one two-port device. A single office-port input such as `1.4/G.15` is normalised to `1.4/G.15-16`; both outlet ports are created on that single device, but only the actually recorded port is connected immediately.
- Patchpanel ports are modelled with two simultaneous connections: one toward the room outlet and one toward the switch.
- Office end devices are created or updated with one network port and linked to the recorded outlet port as an active current connection. The expected columns are kept in sync with that same link.

## Server side

The CLI talks to two endpoints:

| Method | URL                       | Purpose                                                                 |
|-------:|---------------------------|-------------------------------------------------------------------------|
| GET    | `/api/?table=device`      | Auth ping (HTTP Basic against the existing user account)                |
| POST   | `/api/cli/record_link`    | Upsert patchpanel↔room-outlet, optional patchpanel↔switch, and office-device links |

Authentication is HTTP Basic, validated against the `users` table (same
`password_hash` that the web UI uses). No new account or token is required.

The API does not maintain a separate CLI-specific auth stack. Instead it
reuses the same provider flow as the web sign-in in `includes/core/auth.php`:

- local users are verified against the existing `users.password` hash
- LDAP users are verified through the configured LDAP bind/search flow
- activation state and existing `login_attempts` handling stay aligned with
   the browser login logic
- API requests now return JSON `401 Unauthorized` instead of being redirected
   to the HTML login page

For a quick end-to-end smoke test of that shared auth path you can run:

```bash
python cli/scripts/auth_smoke.py \
   --url http://portflow.local/Portflow-DEV \
   --user marius \
   --password 'secret'
```

The script checks valid API auth, invalid API auth, and whether the CLI route
reaches application logic with valid credentials instead of redirecting back
to the login page.

For a quick schema smoke test on the server itself you can run:

```bash
php cli/scripts/schema_smoke.php
php cli/scripts/schema_smoke.php --repair
```

The script reports missing tables/columns/views, can optionally run the schema
repair, and returns a non-zero exit code only for blocking schema gaps by
default.

`/api/cli/record_link` resolves or creates:

1. **Patchpanel port** by `recorded_outlet_port` or `outlet_caption`.
2. **Switch port** from the optional LLDP block; if LLDP was captured, the
   server resolves the switch and creates or updates the patchpanel-to-switch link.
3. **Room outlet** in the room given via `room`; if it does not exist yet, the
   server creates one paired `net_outlet` device and the required outlet ports, then creates or updates the patchpanel-to-outlet link.
4. **Office end device** from `expected_device`, if present, with exactly one
   network port linked as the current office-side connection. The same link is
   mirrored into the expected columns so current and expected stay aligned.

If a room or patchpanel port cannot be matched the call returns 404 and the
CLI keeps the record in `failed` state so you can correct it locally and retry.

Conflicting older switch-side, room-side, or office-device entries are replaced
leg-wise by default, because the captured CLI data is considered newer.

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
