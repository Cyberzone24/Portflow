# Portflow SNMP Discovery & Drift Reporting

## Zweck
Portflow ist die Source-of-Truth fuer Netzwerkkonfigurationen.
SNMP-Scans ermitteln den aktuellen Ist-Zustand der Switches und schreiben ihn in die bestehenden "current"-Spalten.
Die "expected"-Spalten bleiben Operator-Wissen und werden nur ueber bewusste Aktionen veraendert.
Abweichungen werden als Drift-Reports angezeigt; pro Abweichung kann der Operator entweder den Soll-Wert anpassen oder die Konfiguration auf dem Switch korrigieren.

## Leitprinzipien
- Bestehende Tabellen, Views und Sprachdateien bevorzugen.
- Keine zweite Wahrheit fuer Konfigurationsdaten anlegen.
- Neue Tabellen nur fuer Audit (Scan-Lauf) und reine Scan-Caches (z. B. Counter-Diff fuer "heute genutzt").
- Drift wird live aus `*` vs `expected_*` per View berechnet.
- Korrekturen laufen ausschliesslich ueber die bestehende `pending_changes` Pipeline und Templates aus `automation.json`.
- Schreibzugriffe vom Scan auf Konfigurationsdaten ausschliesslich auf "current"-Spalten.

## Vereinbarte Antworten
- VLAN-Modell wird auf 1:n umgestellt: `device_port_vlan` referenziert direkt `device_port`.
- Port-Status (`metadata.status` der Ports) darf vom Scan geschrieben werden, aber nur basierend auf Counter-Diff zwischen zwei Scans ("heute genutzt"), nicht aus einer Momentaufnahme von admin/oper.
- Stack-Modell: Variante A. Pro Stack-Member existiert ein eigenes `device`. Stack-Member sind ueber `device.item_group` gruppiert. Im Switch-Inventar zeigt `device_id` auf das Management-Device (Unit 1).
- Port-Captions sind aktuell identisch zur SNMP-Bezeichnung. Eine spaetere Profil-Normalisierung (Cisco-Kurzformen etc.) wird als Erweiterungspunkt vorbereitet.
- Eigene Audit-Tabelle fuer Scan-Laeufe wird angelegt.

## Datenmodell-Aenderungen

### VLAN 1:n
- `device_port_vlan` bekommt `device_port UUID NOT NULL REFERENCES device_port(uuid) ON DELETE CASCADE`.
- `device_port.device_port_vlan` wird entfernt (1:1-Beziehung wird abgeloest).
- Mehrere Eintraege pro Port erlaubt:
  - 0..1 untagged Eintrag (PVID)
  - 0..n tagged Eintraege (Trunk-Allowed-VLANs)
- Bestehende Spalten `vlan/expected_vlan` und `tagged/expected_tagged` bleiben.
- Folgewirkungen (Daten existieren noch nicht und werden im selben Schritt aktualisiert):
  - `forms.json`: `device_port_details` entfernt das Feld `device_port_vlan` und bekommt einen eigenen Sub-Editor `device_port_vlan_details` als Liste pro Port.
  - `db_views.txt`: Join-Beschreibungen werden umgestellt; `portview` waehlt den untagged Eintrag als primaere VLAN-Anzeige.
  - `itam.php`: VLAN-Verwaltung pro Port als Liste (Hinzufuegen/Entfernen).
  - `lang/*.php`: bestehende `device_port_device_port_vlan_*`-Schluessel werden auf `device_port_vlan_*` umgestellt.
- Diese Folgewirkungen werden inkrementell umgesetzt; das Schema wird zuerst angepasst.

### Neue Tabellen

`snmp_scan_run`:
- `uuid UUID PK`
- `users UUID NULL REFERENCES users(uuid) ON DELETE SET NULL` (Operator, falls manuell)
- `switch_name VARCHAR(255) NOT NULL` (Name aus `automation/settings.json` Inventar)
- `device UUID NULL REFERENCES device(uuid) ON DELETE SET NULL` (Mgmt-Device aus Inventar)
- `trigger VARCHAR(20) NOT NULL DEFAULT 'manual'` (`manual`, `scheduler`, `api`)
- `started TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP`
- `finished TIMESTAMP WITH TIME ZONE NULL`
- `status VARCHAR(20) NOT NULL DEFAULT 'running'` (`running`, `success`, `partial`, `failed`)
- `message TEXT NULL`
- `interfaces_seen INT DEFAULT 0`
- `vlans_seen INT DEFAULT 0`
- `findings_total INT DEFAULT 0`

`device_port_snmp_state` (reiner Scan-Cache fuer "heute genutzt" und Reconciliation-Hinweise):
- `uuid UUID PK`
- `device_port UUID NOT NULL REFERENCES device_port(uuid) ON DELETE CASCADE UNIQUE`
- `last_scan_run UUID NULL REFERENCES snmp_scan_run(uuid) ON DELETE SET NULL`
- `if_index INT NULL`
- `if_name VARCHAR(255) NULL`
- `if_alias VARCHAR(255) NULL`
- `if_admin_status INT NULL`
- `if_oper_status INT NULL`
- `if_last_change_ticks BIGINT NULL`
- `last_in_octets NUMERIC(20,0) NULL`
- `last_out_octets NUMERIC(20,0) NULL`
- `last_seen_active DATE NULL` (Tag, an dem zuletzt Counter-Differenz > 0 oder oper=up)
- `updated TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP`

Begruendung: Counter-Differenz braucht den letzten Wert. `metadata.status` und Reports koennen daraus heuristisch abgeleitet werden, ohne dass Scan-Caches die Source-of-Truth-Spalten ueberschreiben.

### Bestehende "current" und "expected" Spalten (werden vom Scan genutzt)
- `device_port.speed` / `device_port.expected_speed`
- `device_port.mac_address` (read-only Befund, kein expected vorgesehen)
- `device_port_vlan.vlan` / `expected_vlan` und `tagged` / `expected_tagged` (jetzt 1:n)
- `device_port_ip.ip` / `expected_ip`, `hostname` / `expected_hostname`, `dhcp_address` / `expected_dhcp_address`
  - Quelle bevorzugt SNMP; falls der Hersteller Port-IP-Daten nicht sinnvoll per SNMP liefert, ist ein read-only CLI-Fallback erlaubt (z. B. Huawei), der ebenfalls nur die `current`-Spalten schreibt.
- `device.location` / `expected_location` (Drift, falls SNMP-Topologie abweicht; vorerst nur Report)
- `connection.device_port_source` / `expected_device_port_source` und Pendant fuer destination (LLDP/CDP-Nachbarn)
- `metadata.status` der Ports: vom Scan nur ueber "heute genutzt"-Heuristik gesetzt

## Drift-Klassen (MVP)
1. Speed mismatch: `device_port.speed != expected_speed`
2. PVID/Untagged-VLAN mismatch: bei untagged-Eintrag `vlan != expected_vlan`
3. Tagged-VLAN-Liste mismatch: Set-Vergleich pro Port
4. IP/Hostname/DHCP mismatch
5. LLDP/CDP-Nachbar mismatch zur `connection`
6. Unbekannter Port am Switch: kein passender `device_port` per Caption gefunden
7. Verwaister Port in Portflow: in Inventar erwartet, aber nicht im Scan
8. Status-Drift: Port seit X Tagen nicht "aktiv genutzt", aber konfiguriert (`expected_vlan` gesetzt etc.)

## Reconciliation-Regeln
- Mapping SNMP -> Portflow:
  - Stack-Erkennung: SNMP-`ifName` Pattern `^[A-Za-z]+\s*(\d+)/\d+/\d+$` -> `unit`.
  - Suchraum: alle `device` mit gleicher `item_group` wie das Inventar-Device. Wenn `unit` parsebar, das Member-Device mit passender Unit selektieren (Heuristik: `device.metadata.caption` oder `device.tags` enthaelt `unit-<n>` / `Stack 1/2/3`); ansonsten Fallback auf das Inventar-Device.
  - In gewaehltem Device per `device_port.metadata.caption == ifName` matchen.
  - Wenn nichts matcht: Drift "Unbekannter Port" (Read-only Befund), keine Schreiboperationen.
- Schreiboperationen ausschliesslich auf "current"-Spalten.
- Counter-Diff fuer "heute genutzt":
  - Bei Folge-Scan am gleichen Tag: in/out-Octets-Delta > 0 ODER oper=up -> `last_seen_active = current_date`.
  - Wenn `last_seen_active < current_date - <inactivity_days>` UND `expected_vlan` gesetzt -> Drift "Port konfiguriert, aber lange inaktiv".
- VLAN-Reconciliation:
  - PVID-Eintrag (untagged) wird als 0..1 Zeile gefuehrt, Trunk-Allowed-VLANs als n Zeilen mit `tagged=true`.
  - `expected_*` wird beim Drift nicht automatisch ueberschrieben.
- Port-IP-Reconciliation:
  - Primaer ueber `IP-MIB::ipAddrTable`, konkret `ipAdEntAddr` (`1.3.6.1.2.1.4.20.1.1`) und `ipAdEntIfIndex` (`1.3.6.1.2.1.4.20.1.2`).
  - CLI-Fallback ist dafuer im MVP nicht mehr erforderlich und nur noch ein spaeterer Notnagel fuer Sonderfaelle, in denen `ipAddrTable` auf einem Geraet unvollstaendig ist.
  - Importiert werden nur beobachtete Werte in `device_port_ip.*`; `expected_*` bleibt unberuehrt.
- Topologie-Reconciliation:
  - LLDP/CDP-Nachbarn werden als Faktenbasis fuer Uplink-Kanten gesammelt.
  - Die Topologie-Karte ist read-only und visualisiert zuerst nur erkannte Uplink-Beziehungen; spaetere Schreibaktionen auf `connection` bleiben davon getrennt.

## SNMP OID-Sets (MVP)
- System: `1.3.6.1.2.1.1.5.0` sysName, `1.3.6.1.2.1.1.1.0` sysDescr
- Interfaces: `IF-MIB::ifIndex`, `ifName`, `ifAlias`, `ifAdminStatus`, `ifOperStatus`, `ifSpeed/ifHighSpeed`, `ifPhysAddress`, `ifLastChange`, `ifInOctets/ifOutOctets` (oder `ifHC*`)
- IP-Adressen: `IP-MIB::ipAddrTable`, insbesondere `ipAdEntAddr` und `ipAdEntIfIndex`
- Bridge/VLAN: `Q-BRIDGE-MIB::dot1qPvid`, `dot1qVlanCurrentEgressPorts`, `dot1qVlanStaticEgressPorts`, `dot1qVlanStaticUntaggedPorts`
- LLDP: `LLDP-MIB::lldpRemTable` (chassisId, portId, sysName)
- CDP (optional, Cisco): `CISCO-CDP-MIB::cdpCacheTable`
- ARP/MAC (optional, spaeter): `BRIDGE-MIB::dot1dTpFdbTable`, primaer `IP-MIB::ipNetToPhysicalTable`, nur als Fallback `IP-MIB::ipNetToMediaTable`

## Architektur und neue Dateien
- `includes/core/snmp_scanner.php` (neu): Klasse `SnmpScanner` mit
  - `scanSwitch(string $switchName, string $trigger = 'manual'): array` (legt `snmp_scan_run` an, ruft Module, schreibt `device_port_snmp_state`, gibt Reconciliation-Ergebnis zurueck).
  - Helper `runSnmpWalk(...)` und `runSnmpGet(...)` mit der bereits bestehenden Algorithmus-Normalisierung.
  - Port-IP-Import erfolgt direkt aus `ipAddrTable`; herstellerspezifische Fallback-Collector sind dafuer vorerst nicht Teil des MVP.
- `includes/core/port_reconciler.php` (neu): Funktionen
  - `applyInterfaceFacts($db, $deviceUuid, $facts, $runUuid)`
  - `applyVlanFacts($db, $deviceUuid, $facts, $runUuid)`
  - `applyIpFacts($db, $deviceUuid, $facts, $runUuid)`
  - `applyStatusHeuristics($db, $deviceUuid, $now, $config)`
- `includes/core/snmp_naming.php` (neu, Erweiterungspunkt): `normalizePortName(string $ifName, array $profile): string` (default: identity).
- `settings.php`: pro Switch im Inventar zusaetzlicher Button "Scan jetzt" (Cyan, Icon `radar`), zusaetzlich globaler Button "Alle scannen".
- `reports.php` (neu) ODER neuer Tab in `itam.php`: Drift-Uebersicht.
- `reports.php` oder eigener Reports-Abschnitt: Topologie-Karte fuer erkannte Uplink-Beziehungen zwischen Switches auf Basis von LLDP/CDP und Inventar-Mapping.
- `automation.json`: zusaetzliche Templates fuer `set_port_pvid`, `set_trunk_allowed_vlans`, `set_port_description`, `port_shutdown`, `port_no_shutdown`.
- `scheduler.php`: Task `snmp_scan_all` mit Intervall aus `automation/settings.json -> snmp_scan.interval_minutes`.
- `lang/de-DE.php`, `lang/en-EN.php`: neue Schluessel fuer Drift-Klassen, Buttons, Severity.
- `data/automation/settings.json`: zusaetzlicher Block `snmp_scan` (Intervall, `inactivity_days`, `oid_modules`-Toggles).

## UI Skizzen
### Settings -> Scripts -> Switch
- Pro Switch-Zeile: zusaetzlicher Button "Scan jetzt".
- Oberhalb der Tabelle: Button "Alle scannen", Status der letzten Scans und naechster geplanter Scan.

### Drift-Uebersicht
- Filter: Switch, Drift-Klasse, Severity, Status (offen/geloest/ignoriert).
- Tabelle: Device, Port, Klasse, Soll, Ist, letzter Scan, Aktionen.
- Aktionen pro Zeile (icon-only nach Designguide):
  - Gruen `check`: "Expected angleichen" -> setzt expected = actual via DB.
  - Orange `wrench`: "Korrigieren" -> erzeugt Pending Change (Template + Variablen).
  - Grau `eye-off`: "Ignorieren" (nur fuer aktuelle Drift-Anzeige; bei naechstem Scan neu bewertet).

### Topologie-Karte
- Visualisiert erkannte Uplink-Beziehungen zwischen Switches aus LLDP/CDP-Fakten.
- Knoten: Switch bzw. Stack-Mgmt-Device; Kanten: erkannte Uplinks inkl. lokalem Port, Remote-Port, letzter Scan.
- Erste Ausbaustufe read-only; spaeter optional Verlinkung in Drift-Details oder Portansichten.

## Sicherheit
- SNMP-Credentials kommen aus `AutomationStore` (verschluesselt).
- Discovery ist read-only (snmpget/snmpwalk); Korrekturen ausschliesslich ueber bestehende SSH/Pending-Changes-Pipeline.
- Keine Passphrasen ins Frontend echoen; `runAutomationSnmpTest`-Maskierung wird im Scanner wiederverwendet.

## Implementations-Reihenfolge
1. Schema-Anpassung: VLAN 1:n, `snmp_scan_run`, `device_port_snmp_state` in `db_tables.json`.
2. View-Anpassungen in `db_views.txt`:
   - `device_port`-Joins ueber neue VLAN-Beziehung.
   - `portview` waehlt PVID-Eintrag als primaere VLAN-Anzeige.
   - Neue View `device_port_drift` (UNION ALL pro Drift-Klasse).
3. SNMP Discovery Engine (`snmp_scanner.php` + `snmp_naming.php`).
4. Reconciler (`port_reconciler.php`).
5. Port-IP-Import: zuerst SNMP-basiert, bei Bedarf mit optionalem CLI-Fallback pro Hersteller (startend mit Huawei).
6. "Scan jetzt"-Button in `settings.php` Inventar plus "Alle scannen".
7. Reports-UI (zuerst read-only).
8. Topologie-Karte der Uplinks auf Basis von LLDP/CDP und Inventar-Mapping.
9. Aktion "Expected angleichen".
10. Aktion "Korrigieren" via `pending_changes` mit neuen Templates.
11. Scheduler-Task `snmp_scan_all`.
12. Folge-PR: itam-Forms/Lang fuer VLAN 1:n nachziehen.
13. LLDP/CDP-Nachbarn und `connection`-Drift.
14. ARP/MAC-Reports.

## Qualitaetscheck pro Schritt
- `php -l` fuer geaenderte PHP-Dateien.
- JSON-Validierung fuer `db_tables.json`, `forms.json`, `automation.json`.
- Manueller Smoke-Test: Schema-Init, ein manueller Scan, ein Drift-Befund, "Expected angleichen", "Korrigieren".
- Sprache und Designguide-Konformitaet (Tailwind, Lucide, icon-only Buttons mit `title`).
