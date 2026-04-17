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

## Automation ToDo

### 1. Save strategy optimieren (Session-/Tagesende)
Status: In Progress
Priority: High

- Ziel: Nicht nach jeder Einzelaktion speichern, sondern Save gebuendelt am Ende einer Session oder per Tagesabschluss ausfuehren.
- Nutzen: Weniger Execution-Time und weniger Schreiboperationen auf den Switches.
- Technische Richtung: Queue fuer pending changes pro Switch aufbauen.
- Technische Richtung: Manueller "Save now"-Button und optionaler scheduler-basierter Auto-Save (z. B. taeglich 18:00).
- Technische Richtung: Sichtbarer Hinweis, wenn unsaved changes vorhanden sind.

**Implementiert (Phase 1):**
- Neue Datenbank-Tabelle `pending_changes` mit UUID, Nutzer, Switch, Profil, Template, Kommandos, Status
- PendingChangesQueue Manager-Klasse: `addPendingChange()`, `getPendingChanges()`, `getPendingSummary()`, `executeQueueForSwitch()`
- UI in automation.php mit zwei Tabs: "Automation" + "Warteschlange"
- "Zu Warteschlange hinzufuegen" vs "Sofort ausfuehren" Buttons
- Queue-Ansicht zeigt volle Befehlsliste pro Eintrag (nicht nur gekuerzt)
- Einzelstart pro Queue-Eintrag via "Ausfuehren"-Button
- Sammelstart pro Switch via "Alle ausfuehren"-Button
- Delete pro Queue-Eintrag
- Notification Badge zeigt Anzahl ausstehender Aenderungen

**Implementiert (Phase 2 - Scheduler):**
- CLI-Script `scheduler.php` zur Ausgefuehrung via Cronjob (z.B. `php /var/www/html/Portflow-DEV/scheduler.php`)
- Scheduler verarbeitet alle pending changes aller Nutzer/Switches sequenziell
- AutomationStore::`updateSchedulerStatus()` speichert Ausfuehrungsergebnisse (last_run, last_success, processed, succeeded, failed, message)
- Scheduler Status Widget in settings.php: 
	- Zeigt letzten Lauf, Status (OK/FEHLER), Statistiken
	- "Jetzt ausfuehren" Button fuer manuelle Trigger
	- Cronjob-Befehl zum Kopieren/Einfuegen (0 18 * * * php /var/www/html/Portflow-DEV/scheduler.php)
- Ausfuehrliche Logging: Scheduler loggt jeden Lauf, jeden Switch, Erfolge/Fehler
- Statuskennzeichnung: gruen bei 100% Erfolg, gelb bei teilweisem Fehler

### 2. SSH Batch-Execution (eine Session, mehrere Befehle)
Status: In Progress
Priority: High

- Ziel: SSH einmal oeffnen, mehrere Templates/Befehle nacheinander senden, SSH am Ende sauber beenden.
- Nutzen: Deutlich schneller fuer Batch-Aenderungen und spaetere Vollkonfiguration von Ports.
- Technische Richtung: Batch-Runner einbauen (Command-Pipeline pro Switch).
- Technische Richtung: Fehlerstrategie definieren: stop-on-error vs continue-with-report.
- Technische Richtung: Ergebnisreport pro Kommando und Gesamt-Exit-Status loggen.

**Implementiert (1. Ausbaustufe):**
- Batch-Interface-Liste im Automation-UI (eine Zeile pro Interface)
- Ausfuehrung in einer SSH-Session mit gemeinsamer Enter/Commit/Save-Phase
- Kombinierte Preview und Execute-Unterstuetzung inkl. Save-Strategie
- SSH-Session wird explizit mit `quit` beendet, um Timeout-Wartezeit zu vermeiden

**Implementiert (2. Ausbaustufe - Teil 1):**
- Multi-Template-Pipeline im Automation-UI (`pipeline_templates`, eine Zeile pro Template-ID)
- Preview, Execute und Queue unterstuetzen Pipeline-Reihenfolge in einer Session
- Pipeline verarbeitet pro Template optional Batch-Interfaces; bei fehlender `interface`-Variable erfolgt Fallback auf einmalige Ausfuehrung mit Warnhinweis
- Queue-Eintraege zeigen volle Commands und koennen einzeln gestartet werden
- Ausfuehrungsreport zeigt nummerierten Block `Commands Sent`

**Implementiert (2. Ausbaustufe - Teil 2):**
- Fehlerstrategie im UI: `continue_report` vs `stop_on_error`
- `continue_report`: Pipeline wird als eine Session ausgefuehrt und gesamter Report ausgegeben
- `stop_on_error`: Pipeline wird template-weise ausgefuehrt; bei erstem Fehler erfolgt Abbruch
- Stop-Mode erzeugt getrennten Report je Template inkl. Abbruchhinweis

**Implementiert (2. Ausbaustufe - Teil 3):**
- Pro-Kommando-Erfolgsmarker im Report (`Command Status (heuristisch)`)
- Marker pro Kommando: `OK`, `OK?`, `ERR?`, `SENT`
- Heuristik basiert auf Exit-Code und erkannten Error-Pattern im CLI-Output
- Implementiert fuer Sofort-Ausfuehrung und Queue-Ausfuehrung

**Implementiert (2. Ausbaustufe - Teil 4):**
- Neue Fehlerstrategie `stop_with_rollback` im Automation-UI
- Bei Fehler wird die Pipeline gestoppt und fuer bereits ausgefuehrte Templates ein Rollback versucht
- Rollback nutzt optionale Template-Definition `rollback_commands` (wenn vorhanden)
- Rollback-Report wird zusammen mit der normalen Ausfuehrung ausgegeben

**Phase 2 (Geplant):**
- Multi-Template-Pipelines: mehrere verschiedene Templates mit verschiedenen Variablen auf einem Switch
- Error-Handling-Strategien: stop-on-error (abbricht bei erstem Fehler) vs continue-with-report (sammelt Fehler)
- Pro-Kommando-Reports: detaillierter Output fuer jedes Commands (+Erfolgsstatus)
- Rollback-Logik (optional): bei Fehler automatisch letzte Konfiguration wiederherstellen

### 3. Berechtigungen fuer Automation-Tab (Opt-In pro Nutzer)
Status: Done ✅
Priority: Medium

- Ziel: Zugriff auf Automation-Funktionen pro Nutzer explizit freigeben.
- Nutzen: Mehr Kontrolle und geringeres Risiko von Fehlbedienung.
- Technische Richtung: Neues Recht in Rollen/ACL (z. B. can_use_automation).
- Technische Richtung: Sichtbarkeit von Tab und Execute-Action daran koppeln.
- Technische Richtung: Admin-UI fuer Rechtevergabe erweitern.

**Implementiert:**
- `Auth::checkResourceAccess()` in auth.php
- Execute-Prüfung in automation.php
- Tab-Sichtbarkeit in header.php (bedingt auf Berechtigung)
- Initialiserung der automation-Ressource im Setup
- Automation Permissions UI im access-Admin-Tab

### 4. Template-Shortcuts in Port-Uebersicht
Status: In Progress
Priority: Medium

- Ziel: Direkte Aktionsbuttons in der Portansicht (z. B. PoE powercycle).
- Nutzen: Schnellere Standardoperationen ohne Wechsel in den Automation-Bereich.
- Technische Richtung: Konfigurierbare Shortcut-Buttons je Port.
- Technische Richtung: Mapping Button -> Template + vorbelegte Variablen.
- Technische Richtung: Sicherheitsabfrage und Logging je Shortcut-Execution.

**Implementiert (1. Ausbaustufe):**
- Shortcut-Buttons direkt in der Aktionsspalte von `portview.php`:
	- Shutdown Port (`shutdown_port`)
	- Cleanup Port (`cleanup_port`)
- Sicherheitsabfrage vor Ausfuehrung (Confirm-Dialog mit Switch/Port)
- Backend-Endpoint `?action=shortcut_execute` mit:
	- CSRF-Pruefung
	- Berechtigungspruefung auf Resource `automation`
	- Mapping Port-Eintrag -> Switch-Inventar -> Template-Render
	- SSH-Ausfuehrung auf Zielswitch
	- Logger-Eintrag pro Shortcut-Run
- Rueckmeldung an Benutzer mit Ergebnis, Warnungen und CLI-Ausgabe

**Implementiert (2. Ausbaustufe):**
- Shortcut-Buttons sind jetzt konfigurierbar statt fest codiert
- Konfiguration erfolgt ueber `scripts_json` Overrides unter `shortcuts.portview`
- Frontend rendert die Buttons dynamisch aus der Konfiguration (Label, Icon, Farbe, Confirm-Text)
- Backend mappt `shortcut_id` auf erlaubte Templates und validiert gegen Konfiguration

**Implementiert (3. Ausbaustufe):**
- Queue-Modus fuer Port-Shortcuts in `portview.php`
- Pro Shortcut zusaetzlicher Queue-Button (Uhr-Icon) in der Aktionsspalte
- Backend nimmt `mode=queue` an und schreibt den Shortcut als `pending_change`
- Queue-Eintraege enthalten Shortcut-Kontext (`shortcut_id`, `port_uuid`, `interface`)

Beispiel fuer `scripts_json`:
```json
{
	"shortcuts": {
		"portview": [
			{
				"id": "shutdown",
				"label": "Shutdown Port",
				"template_id": "shutdown_port",
				"icon": "fa-solid fa-power-off",
				"button_class": "text-orange-500 hover:text-orange-700",
				"confirm": "Shutdown Port ausfuehren?",
				"enabled": true
			},
			{
				"id": "cleanup",
				"label": "Cleanup Port",
				"template_id": "cleanup_port",
				"icon": "fa-solid fa-broom",
				"button_class": "text-purple-500 hover:text-purple-700",
				"confirm": "Cleanup Port ausfuehren?",
				"enabled": true
			}
		]
	}
}
```

### 5. Switch-Inventar mit ITAM-Geraet verknuepfen
Status: Done ✅
Priority: High

- Ziel: Pro Switch-Eintrag im Inventar eine eindeutige Verknuepfung zu einem ITAM-Geraet speichern.
- Nutzen: Konsistente Datenbasis zwischen Automatisierung, Portansicht und Asset-Management.
- Technische Richtung: Neues Feld fuer ITAM-Device-Referenz im Switch-Inventar (z. B. device_id).
- Technische Richtung: Validierung bei Speichern (existiert das referenzierte ITAM-Geraet?).
- Technische Richtung: Anzeige der ITAM-Verknuepfung im Automation-UI und spaeter in Reports.

**Implementiert:**
- `device_id` als optionales Feld in Switch-Inventar-Eintraegen
- Validierung in AutomationStore::saveSettings()
- UI-Update in settings.php mit device_id-Dokumentation
- Basis für zukünftige ITAM-Integrationsfunktionen

### 6. Grafische Verwaltung fuer Switch-Inventar und Skripte
Status: In Progress
Priority: Medium

- Ziel: JSON-Freifelder langfristig durch eine strukturierte UI fuer Inventar- und Skriptverwaltung ersetzen.
- Nutzen: Weniger Eingabefehler, bessere Bedienbarkeit und schnellere Pflege.
- Technische Richtung: CRUD-Maske fuer Switch-Eintraege mit Formularvalidierung.
- Technische Richtung: Skript-/Template-Editor mit Vorschau und Eingabepruefungen.
- Technische Richtung: Aenderungshistorie (wer hat was wann geaendert) fuer Nachvollziehbarkeit.

**Implementiert (1. Ausbaustufe):**
- Grafische CRUD-Verwaltung fuer Switch-Inventar in `settings.php` (Liste + Add/Delete)
- Serverseitige Validierung und Speicherung ueber `AutomationStore` ohne Schema-Bruch
- Grafischer Template-Override-Editor fuer `scripts_json.templates` (Add/Update/Delete)
- JSON-Fallback bleibt erhalten (`Advanced JSON Bearbeitung`) fuer volle Rueckwaertskompatibilitaet
- SSH-Test ist direkt in der Inventar-Tabelle pro Switch verfuegbar und prueft die jeweilige Management-IP
- Scripts-UI ist in zwei Untertabs getrennt: `Switch/SSH` und `Template Overrides`

**Implementiert (2. Ausbaustufe):**
- Aktive Scripts-Tab-Auswahl wird bei Aktionen gespeichert und nach Redirect wiederhergestellt (`switch`, `templates`, `history`)
- Neue Historie-Ansicht im Scripts-Bereich (eigener Tab) mit den letzten Aenderungen an Inventory/Templates/Settings
- Historie wird in `changelog` unter `changed_table = automation_settings` protokolliert

**Implementiert (3. Ausbaustufe):**
- Switch-Inventar unterstuetzt jetzt Edit/Update direkt aus der Tabelle (nicht nur Add/Delete)
- Historie-Tab besitzt einen Live-Filter fuer Benutzer/Operation/Action/Details

### 7. Ausführliches Logging
Status: In Progress
Priority: Low

- Ziel: Ausführliches Logging (Debug-Level) der Automatisierung
- Nutzen: Nachvollziehbare Zugriffe/Nutzung und Fehlermeldungen
- Technische Richtung: Nutzung des Loggers (core/logger.php)

**Implementiert (1. Ausbaustufe):**
- Detailliertere Execution-Logs in `automation.php` (Strategie, Pipeline-Gruppen, Batch-Interfaces, Command-Anzahl)
- Ergebnis-Logs nach Ausfuehrung inkl. OK/FAILED-Marker
- Zusätzliche Schritt-/Rollback-Logs fuer `stop_with_rollback`

## Aktuelle Bugfixes

- Rechtepruefung fuer Automation-Execute basiert jetzt auf Execute-Bit statt nur `access_right > 0` (`checkResourceAccess(..., 'execute')`)
- SSH-Test in `settings.php` beendet Sessions wieder sauber mit `quit` und wertet Timeout-Exitcodes transparenter aus
- "Zu Warteschlange hinzufuegen" in `automation.php` uebernimmt jetzt immer die aktuellen Formularwerte (auch ohne vorherigen Preview-Submit)
- Beim Loeschen eines ITAM-Ports werden zugehoerige Verbindungen in der API (`device_port` delete) mit entfernt