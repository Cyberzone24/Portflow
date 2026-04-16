# Portflow AI Model Overview

## Zweck
Portflow ist ein leichtgewichtiges ITAM und Port-Management System.
Der Fokus liegt auf schneller Datenerfassung, hoher Datenqualität und einer einfachen, wartbaren Architektur.

## Leitprinzipien
- Backend so schlank wie möglich halten.
- Vorhandene Tabellen und Views bevorzugen, statt neue Komplexität einzuführen.
- UI auf schnelle Operator-Workflows ausrichten.
- Änderungen in kleinen, klaren Schritten umsetzen.
- Bestehende Semantik und Feldnamen erhalten.

## Technischer Stack
- PHP Anwendung mit eigener API in [api/index.php](api/index.php).
- PostgreSQL als Datenbank.
- Tailwind CSS und Vanilla JavaScript im Frontend.
- Formularlogik über Konfiguration in [forms.json](forms.json).
- Tabellen und Schema in [includes/core/db_tables.json](includes/core/db_tables.json).
- Read-Model Views in [includes/core/db_views.txt](includes/core/db_views.txt).
- Sprachausgaben in [includes/lang/de-DE.php](includes/lang/de-DE.php) und [includes/lang/en-EN.php](includes/lang/en-EN.php).

## Architekturüberblick
- UI und Interaktion liegen zentral in [itam.php](itam.php).
- Die API ist generisch aufgebaut und arbeitet ressourcenbasiert auf Tabellen und Views.
- Persistenz läuft über den Adapter in [includes/core/db_adapter.php](includes/core/db_adapter.php).
- Konfiguration kommt aus der Umgebung über [includes/core/config.php](includes/core/config.php) und .env.

## Datenmodell in Kurzform
- Metadata ist die Basis für Caption, Status, Beschreibung und Tags.
- Location ist hierarchisch aufgebaut: Region bis Raum und Rack.
- Device hängt an Location.
- Device Port hängt an Device.
- Connection verbindet Source und Destination Device Port.

Wichtig:
- Keine zweite Rack-Wahrheit einführen.
- Raumkontext aus der bestehenden Location-Hierarchie ableiten.
- Für Anzeige und Suche bevorzugt Views nutzen.

## UI Rahmenbedingungen
- Primäre Arbeitsoberfläche ist [itam.php](itam.php).
- Tabellenansicht plus Suchfeld und Pagination bleiben schlank.
- Neue Eingaben laufen über dynamisch generierte Formulare aus [forms.json](forms.json).
- Für Verbindungen gibt es zwei Modi:
- Manuell für direkte Auswahl.
- Vorschläge für schnelle Erstellung aus same-room und same-label Paaren.
- Suchergebnisse sollen disambiguieren, z. B. Raum plus Device plus Portname.

## Aktuelle UX Muster
- Auto-Port-Erzeugung im Device-Flow über port_count und port_start_label.
- Verbindungsvorschläge berücksichtigen unverbundene Ports und schließen bereits belegte Ports aus.
- Anzeige priorisiert klare Labels statt technischer UUIDs.

## Regeln für KI-Änderungen
- Wenn ein Feld fachlich neu ist, konsistent in allen Schichten ergänzen:
- Schema in [includes/core/db_tables.json](includes/core/db_tables.json).
- View-Ausgabe in [includes/core/db_views.txt](includes/core/db_views.txt), falls für UI relevant.
- Form-Konfiguration in [forms.json](forms.json).
- Sprachlabels in [includes/lang/de-DE.php](includes/lang/de-DE.php) und [includes/lang/en-EN.php](includes/lang/en-EN.php).
- Frontend-Verhalten in [itam.php](itam.php).

- Keine stillen Breaking Changes bei bestehenden API-Feldern.
- Bool, Zahl und Null sauber typisiert behandeln.
- Bei API Fehlern zuerst Typbindung und Payload prüfen.
- SQL nur über vorbereitete Statements ausführen.

## Qualitätscheck vor Abschluss
- PHP Syntax prüfen für geänderte Dateien.
- JSON Validität prüfen für [forms.json](forms.json).
- Bei UI Änderungen mindestens den betroffenen Create-Flow manuell testen.
- Bei View-Änderungen sicherstellen, dass die View-Definitionen im Zielsystem neu erstellt wurden.

## Typische Fehlerbilder
- PostgreSQL Typfehler bei Boolean oder Float durch leere Strings.
- Unklare Suchergebnisse bei identischen Portnamen ohne Raumkontext.
- Inkonsistente Labels, wenn Sprachdateien nicht mitgezogen wurden.

## Empfohlener Arbeitsmodus für KI-Modelle
- Erst Ist-Zustand lesen, dann minimal-invasiv ändern.
- Änderungen an Datenmodell und UI immer gemeinsam denken.
- Bei Performance-Fragen zuerst einfache Heuristiken nutzen statt komplexe neue Entitäten.
- Immer mit Blick auf Operator-Geschwindigkeit entscheiden.
