![Portflow](https://raw.githubusercontent.com/Cyberzone24/Portflow/main/includes/img/portflow.png)
# Portflow

Installer
```shell
bash <(curl -s https://raw.githubusercontent.com/Cyberzone24/Portflow/main/installer.sh)
```
### Dependencies
- PostgreSQL
- Tailwind CSS
- JQuery
- Lucide Icons
- PHPMailer
- (Lighttpd - if you are using our installer)

## Scheduler alle 5 Minuten laufen lassen!

## ITAM Port Type Codes

`device_port.type` is stored as `FLOAT` in the current schema.
For this reason, the frontend and auto-port workflow must send numeric codes (not text values like `"rj45"`).

### Mapping

| Code | Key | Label | Typical family |
| --- | --- | --- | --- |
| 0 | `power_c14` | C14 (Power) | power |
| 1 | `power_c20` | C20 (Power) | power |
| 10 | `rj45` | RJ45 | copper |
| 11 | `sfp` | SFP / SFP+ | sfp |
| 12 | `qsfp` | QSFP | qsfp |
| 13 | `mpo` | MPO | fiber |
| 20 | `mgmt_rj45` | Management (RJ45) | mgmt |
| 21 | `console_rj45` | Console (RJ45) | console |
| 30 | `fiber_lc` | Fiber LC | fiber |
| 31 | `fiber_sc` | Fiber SC | fiber |
| 40 | `coax_bnc` | Coax BNC | coax |
| 99 | `other` | Other | other |

### Notes

- The device-port form now uses these numeric options directly.
- Auto-port creation maps type families to these codes automatically.
- If the database schema is migrated from `FLOAT` to `TEXT` in the future, this table can remain as a compatibility contract.