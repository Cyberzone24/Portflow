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
Status: Open
Priority: High

- Ziel: Nicht nach jeder Einzelaktion speichern, sondern Save gebuendelt am Ende einer Session oder per Tagesabschluss ausfuehren.
- Nutzen: Weniger Execution-Time und weniger Schreiboperationen auf den Switches.
- Technische Richtung: Queue fuer pending changes pro Switch aufbauen.
- Technische Richtung: Manueller "Save now"-Button und optionaler scheduler-basierter Auto-Save (z. B. taeglich 18:00).
- Technische Richtung: Sichtbarer Hinweis, wenn unsaved changes vorhanden sind.

### 2. SSH Batch-Execution (eine Session, mehrere Befehle)
Status: Open
Priority: High

- Ziel: SSH einmal oeffnen, mehrere Templates/Befehle nacheinander senden, SSH am Ende sauber beenden.
- Nutzen: Deutlich schneller fuer Batch-Aenderungen und spaetere Vollkonfiguration von Ports.
- Technische Richtung: Batch-Runner einbauen (Command-Pipeline pro Switch).
- Technische Richtung: Fehlerstrategie definieren: stop-on-error vs continue-with-report.
- Technische Richtung: Ergebnisreport pro Kommando und Gesamt-Exit-Status loggen.

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
Status: Open
Priority: Medium

- Ziel: Direkte Aktionsbuttons in der Portansicht (z. B. PoE powercycle).
- Nutzen: Schnellere Standardoperationen ohne Wechsel in den Automation-Bereich.
- Technische Richtung: Konfigurierbare Shortcut-Buttons je Port.
- Technische Richtung: Mapping Button -> Template + vorbelegte Variablen.
- Technische Richtung: Sicherheitsabfrage und Logging je Shortcut-Execution.

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
Status: Open
Priority: Medium

- Ziel: JSON-Freifelder langfristig durch eine strukturierte UI fuer Inventar- und Skriptverwaltung ersetzen.
- Nutzen: Weniger Eingabefehler, bessere Bedienbarkeit und schnellere Pflege.
- Technische Richtung: CRUD-Maske fuer Switch-Eintraege mit Formularvalidierung.
- Technische Richtung: Skript-/Template-Editor mit Vorschau und Eingabepruefungen.
- Technische Richtung: Aenderungshistorie (wer hat was wann geaendert) fuer Nachvollziehbarkeit.
