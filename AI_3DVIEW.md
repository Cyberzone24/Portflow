# AI 3D View Plan (Three.js, Rack + Components)

## 1. Goal
Build a modular 3D visualization for racks and devices in ITAM using Three.js.

- Data source: existing database (`location`, `device`, `device_port`)
- Unit system: millimeters (mm)
- First deliverable: working mockup (Phase 1)
- Architecture target: modular data model for future extensions (weight, load, ports, cables, real assets)

## 2. Scope and Constraints

### In Scope (now)
- Render one rack geometry from location data.
- Render device boxes inside rack from device position data.
- Basic camera controls (orbit, zoom, pan).
- Lazy loading of 3D resources (load only on explicit user action).

### Out of Scope (now)
- Complex collision solver.
- Cable routing engine.
- Real-world mesh assets.
- Port-level visualization with patching logic.

## 3. Data Contract (MVP)

### Rack (from `location`)
Expected JSON object in location geometry field:

```json
{
  "outer": { "x": 600, "y": 2200, "z": 1000 },
  "between": {
    "x_left": 25,
    "x_right": 25,
    "y_front": 40,
    "y_back": 40,
    "z_bottom": 60,
    "z_top": 60
  },
  "inner": { "x": 550, "y": 2100, "z": 900 }
}
```

Notes:
- All values are mm.
- `outer` is rack shell dimensions.
- `inner` is usable volume.
- `between` is wall/offset spacing details.

### Device (from `device`)
Expected position JSON object:

```json
{ "x": 0, "y": 1500, "z": 0 }
```

Recommended additional shape fields for MVP rendering:
- `size` JSON: `{ "x": 445, "y": 44, "z": 300 }` (mm)
- `rotation` JSON: `{ "x": 0, "y": 0, "z": 0 }` (degrees)

If `size` is missing, fallback defaults are used in viewer.

## 4. Why This Is Feasible
- Three.js is suitable for parametric geometry rendering from JSON.
- Existing DB relations already map to rack/device entities.
- mm-based dimensions make validation and future engineering features easier.
- Modular JSON supports future extension without breaking existing data.

## 5. Phased Implementation

### Phase 0: Data Contract and Validation
- Define strict schema for rack/device JSON.
- Add validation before save and before render.
- Provide clear fallback defaults for missing fields.

### Phase 1: Mockup Viewer (current next step)
- Standalone mockup page with Three.js.
- Render rack shell + inner volume + sample devices.
- Add labels and basic controls.
- Implement lazy loading: no Three.js load before explicit "Open 3D" action.

### Phase 2: Live DB Integration
- Fetch rack/device data from API.
- Transform DB fields to normalized render model.
- Show data quality warnings (missing/malformed JSON).

### Phase 3: Editor UX
- Form-based input for dimensions/position/rotation.
- Live preview panel next to form.
- Snap and bounds checks (device inside inner volume).

### Phase 4: Performance and UX Hardening
- Route-level lazy loading for 3D bundle.
- Optional model/scene caching.
- Frustum culling and instancing for large scenes.

### Phase 5: Engineering Extensions
- Weights and rack load capacities.
- Port-level overlays.
- Cable routing and path layers.
- Thermal and power overlays.

### Phase 6: Visual Extensions
- Real component images and/or glTF meshes.
- Front/rear rack views.
- Hybrid mode: parametric + real assets.

### Phase 7: Room Survey and Multi-Rack Layout
- Capture room dimensions and structural constraints (walls, doors, pathways).
- Place multiple racks in one shared room coordinate system.
- Add aisle clearances and collision/walkway checks.
- Support room-level camera/navigation presets.

Performance guidance for Phase 7:
- Feasible with current stack when using lightweight parametric meshes.
- Recommended baseline for smooth interaction:
  - 10-30 racks with box-based devices/ports should be performant on standard office GPUs.
  - Use frustum culling, visibility toggles, and optional LOD for larger scenes.
- Scaling strategy for larger environments:
  - Chunked room loading and selective detail rendering (rack focus mode).
  - Keep heavy overlays optional (ports/heat/labels toggles).

## 6. Input and Configuration UX

### Recommended UX
- Standard mode: guided form fields for non-technical users.
- Advanced mode: JSON editor for power users.
- Always provide preview + validation message.

### Required Validation Rules (MVP)
- Numeric values only, non-negative.
- `inner` dimensions must be <= `outer` dimensions.
- Device `position + size` must not exceed rack `inner` bounds.

## 7. Lazy Loading Strategy
Use explicit user intent trigger:
1. Page loads without Three.js runtime.
2. User clicks "Open 3D Viewer".
3. Dynamically import Three.js and controls.
4. Initialize scene and render.

Benefits:
- Lower initial page weight.
- Better responsiveness for users who do not need 3D.
- Cleaner separation of 3D and non-3D workflows.

## 8. Acceptance Criteria (Phase 1)
- Mockup page opens without loading Three.js by default.
- Clicking load initializes a 3D scene.
- Rack geometry is visible and dimensionally plausible.
- Device boxes appear at expected positions.
- Orbit controls work smoothly.
- No blocking JS errors in console.

## 9. Future Compatibility Requirements
- Keep JSON object modular and versionable.
- Avoid hardcoding device types in renderer core.
- Support optional extension blocks:
  - `weight`
  - `loadLimit`
  - `ports`
  - `cables`
  - `assets`

## 10. Immediate Next Action
Proceed with the next open expansion block (post-Phase-7 cable foundation):
- Add cable presets (Debug Trunks / Power only / Full / Minimal focus)
- Add optional trunk waypoint controls (tray height/clearance)
- Add route stress warnings (too long / high bend density)

## 11. Status Log

### 2026-04-19 - Control Expansion (Snap Views + Axis Lock + Cable Presets)
- ITAM 3D-Control-Panel erweitert:
  - Neue Kamera-Presets: `Right`, `Top` (zusätzlich zu Front/Rear/Left/Iso).
  - Neuer Achsen-Lock-Modus: `Frei` oder `Horizontal Orbit`.
  - Neues Kabel-Preset: `Alle`, `Nur Power`, `Nur Fiber`, `Nur Copper`, `Minimal Fokus`.
- Viewer-Core erweitert:
  - `setCameraPreset` unterstützt jetzt `right` und `top`.
  - `setAxisLock(mode)` setzt Orbit-Einschränkungen dynamisch.
  - `setCablePreset(preset)` bündelt Kabelfilter in einem UI-tauglichen Preset-Interface.
- Ziel: schnellere Diagnose/Navigation in der Rack-Ansicht bei konsistentem Bedienmodell.

### 2026-04-19 - Stability + Placement + Mockup-Control Parity Fixes
- Runtime-Fix: `too much recursion` behoben (ursächlich war ein versehentlicher Selbstaufruf in `setAxisLock`).
- Placement-Fix: Device-Placement auf Mockup-Semantik umgestellt:
  - `x` als zentrierter Offset,
  - `y` als bottom-origin,
  - `z` als front-origin.
- Geometrie-Fix: Front/Back-Offets (`z_front`, `z_back`) korrekt auf Innenraumgrenzen gemappt.
- Visual-Parity:
  - Rackohren wieder im ITAM-Viewer aktivierbar.
  - Label-Toggles (Port/Kabel) wieder als Schalter vorhanden.
  - Zusätzliche Mockup-nahe Schalter integriert (Cable-Meta/Fiber/Copper/Power/Trunk/rear-aware/CoM-Marker).

### 2026-04-17 - Phase 1 Mockup Implemented
- `Mockup.html` erstellt/überschrieben mit Rack+Device-Preview.
- JSON-basierte Testdaten integriert.
- Viewer lädt 3D erst per Button-Klick (Lazy Loading).

### 2026-04-17 - Import Fix for Browser Module Resolution
- Fehlerbild: "Der Spezifizierer 'three' war ein blanker Spezifizierer ..." beim Laden von `OrbitControls`.
- Ursache: CDN-Modulkette enthielt in abhängigen Modulen Bare-Specifier-Auflösung, die im Browser ohne Mapping scheitern kann.
- Fix: dynamische Imports in `Mockup.html` auf `esm.sh` umgestellt:
  - `https://esm.sh/three@0.164.1`
  - `https://esm.sh/three@0.164.1/examples/jsm/controls/OrbitControls.js`
- Erwartung: `OrbitControls` und `three` werden konsistent aufgelöst, Viewer lädt ohne Bare-Specifier-Fehler.

### 2026-04-17 - Device Placement Aligned to Rack Interior
- Rückmeldung aus Test: Komponenten schwebten über dem Rack.
- Ursache: Device-`placement.y` wurde als Weltkoordinate interpretiert, nicht als rack-lokaler Wert.
- Fix in `Mockup.html`:
  - `createDeviceMesh` nutzt jetzt Rack-Geometrie (`outer`/`between`) für die Y-Basis.
  - `placement.y` wird als Distanz vom inneren Rack-Boden interpretiert.
  - Geräte werden dadurch im Rack positioniert statt über dem Rack.
- Nebenwirkung: Bestehende Mockup-JSONs mit rack-lokalen mm-Werten rendern konsistenter.

### 2026-04-17 - Rack Floor Alignment Fix (Side View)
- Rückmeldung aus Seitenansicht: Rack stand teilweise im Boden.
- Ursache: Rack-Gruppe war um den Ursprung zentriert, während das Grid die Bodenebene bei `y=0` darstellt.
- Fix in `Mockup.html`:
  - Rack-Gruppe wird um `outer.y / 2` nach oben verschoben (`g.position.y`).
  - Kamera-Target auf Rack-Mitte (`outer.y * 0.5`) angepasst.
- Ergebnis: Rack steht auf dem Boden statt im Boden; Geräte bleiben relativ korrekt im Innenraum positioniert.

### 2026-04-17 - Phase 1.1 Started (Live DB Loading)
- Mockup um Live-DB-Modus erweitert:
  - Button `Racks aus DB laden`
  - Rack-Auswahl (`select`) nach erfolgreichem Laden
- Datenfluss:
  - `location` wird geladen und auf Rack-Kandidaten (`type=8` + gültige `size`-JSON) normalisiert.
  - `device` wird geladen und per `location`-UUID dem gewählten Rack zugeordnet.
- Ergebnisdarstellung:
  - Gewähltes Rack + zugehörige Geräte werden als JSON in den Editor übernommen.
  - Bei aktivem 3D-Viewer sofortiges Re-Rendering.
- Ziel: Phase-2-Vorbereitung durch frühes Live-Daten-Mapping bei weiterhin isoliertem Mockup.

### 2026-04-17 - Phase 1.1 URL-Fix for Mockup.html
- Validierungsfund: API-URL in `Mockup.html` nutzte PHP-Interpolation in einer `.html`-Datei.
- Risiko: Bei statischer Auslieferung wird `<?php ... ?>` nicht aufgelöst und DB-Laden schlägt fehl.
- Fix in `Mockup.html`:
  - `fetchApiTable` baut die URL jetzt browserseitig relativ auf (`new URL('./api/', window.location.href)`).
  - Query-Parameter (`table`, `limit`) werden per `searchParams` gesetzt.
- Ergebnis: Live-DB-Laden funktioniert unabhängig davon, ob die Mockup-Datei als PHP interpretiert wird.

### 2026-04-17 - Y-up Conversion and Rack-Ear Defer
- Koordinatensystem:
  - Das Mockup arbeitet jetzt auf einem echten Y-up-Modell.
  - `y` ist die Höhe, `z` ist die Tiefe.
- Datenmodell:
  - Die `between`-Keys wurden auf `x_left`, `x_right`, `y_bottom`, `y_top`, `z_front`, `z_back` umgestellt.
  - Die sichtbare Debug-Ausgabe zeigt die normalisierten Rackdaten weiterhin direkt im UI.
- Rack-Ohren:
  - Die zusätzlichen 16 mm Overhangs wurden vorerst entfernt.
  - Rack-Ohren bleiben ein späteres Feature, wenn die Basisgeometrie stabil ist.
- Ziel: Die Viewer-Geometrie und die DB-Geometrie sollen nun direkt im selben Koordinatensystem sprechen.

### 2026-04-17 - Phase 2 Live DB Integration with Y-up and Validation
- Live-DB-Laden wurde bereits in Phase 1.1 implementiert.
- Phase 2 erweitert das um vollständige Datenvalidierung:
  - Rack-Geometrie-Checks: inner muss ≤ outer sein, Abmessungen müssen vollständig sein.
  - Device-Bounds-Checks: Prüfung, ob Geräte ins inner-Volume passen (Y-up interpretation).
  - Feedback im UI: Validierungswarungen mit konkreten Problem-Beschreibungen.
- Y-up Konsistenz: Die Datenvalidierung nutzt das neue Y-up Koordinatensystem durchgehend.
- Debug-Ausgabe zeigt jetzt einen `validation`-Block mit Warnings und Zusammenfassung.
- Status-Message zeigt Validierungsergebnis (✓ OK oder ⚠️ N Probleme).

### 2026-04-17 - Phase 3 Editor UX (Guided Form + Live JSON Sync)
- Guided-Form-Modus in `Mockup.html` ergänzt:
  - Rack `outer`, `inner`, `between` Felder als numerische Eingaben.
  - Geräte-Editor mit Device-Auswahl und Feldern für `placement` + `size`.
- Workflow-Buttons ergänzt:
  - `Form aus JSON` synchronisiert Eingabefelder aus dem aktuellen JSON.
  - `Form ins JSON übernehmen` schreibt Formularwerte zurück ins JSON und rendert neu.
- Ziel erreicht: schnelleres Bearbeiten ohne manuelles JSON-Tippen, bei beibehaltener Advanced-JSON-Option.

### 2026-04-17 - Phase 4 Performance Hardening
- Performance-Optionen in `Mockup.html` ergänzt:
  - `Auto-Preview (debounced)` für weichere Live-Updates bei Eingaben.
  - `Scene-Cache` überspringt Rebuilds bei unverändertem Modell.
  - `Pause bei inaktivem Tab` stoppt den Render-Loop bei verstecktem Dokument.
- Implementierungsdetails:
  - Debounce-Renderpfad mit kurzer Verzögerung für Form/JSON-Eingaben.
  - Modellsignatur (`rack.geometry` + `devices`) zur Change-Erkennung.
  - Render-Loop Start/Stop entkoppelt (`startRenderLoop`, `stopRenderLoop`) für kontrollierte Wiederaufnahme.
- Ergebnis: merklich weniger unnötige Rebuilds und niedrigere Last bei längeren Edit-Sessions.

### 2026-04-17 - Styling Pass Before Phase 5 (Rack Ears)
- Styling-Verbesserung in `Mockup.html` umgesetzt:
  - Geräte erhalten optionale Rackohren als kurze Frontflansche.
  - Maße: 2 mm Stärke, bewusst nicht über die gesamte Gerätetiefe.
- UX:
  - Neuer Toggle `Rackohren anzeigen (2 mm, kurz)` im Performance/Style-Bereich.
  - Umschalten triggert sofortiges Re-Rendering.
- Ziel: bessere visuelle Lesbarkeit der Front-Montage, ohne die Geometriedatenlogik zu verändern.

### 2026-04-18 - Inner-Origin Device Alignment (Y-up)
- Gerätekoordinaten wurden auf den inneren Rack-Ursprung umgestellt.
- Neues Placement-Modell:
  - `placement.x = 0` an linker Innenwand
  - `placement.y = 0` am Innenboden
  - `placement.z = 0` an vorderer Innenwand
- Umsetzung in `Mockup.html`:
  - Device-Position wird jetzt aus der tatsächlichen inneren Box abgeleitet (`innerMin + placement + size/2`).
  - Bounds-Validierung verwendet dasselbe Modell (`placement >= 0`, `placement + size <= inner`).
- Ergebnis: Position `0/0/0` entspricht jetzt sichtbar vorne-links-unten im Mounting-Bereich.

### 2026-04-18 - XZ Mirror Correction for Placement Perspective
- Rückmeldung: Geräte erschienen auf der gegenüberliegenden Seite der inneren Box (auf XZ-Ebene um 180° gedreht).
- Fix in `Mockup.html`:
  - Placement-Mapping für X/Z wurde gespiegelt.
  - `posX` und `posZ` werden jetzt aus `innerMax - placement - size/2` berechnet.
- Ergebnis: Device-Offsets landen auf der erwarteten Front/Left-Orientierung im Viewer.

### 2026-04-18 - Device Facing Orientation Fix
- Rückmeldung: Position war korrekt, aber das Gerät zeigte noch in die falsche Richtung.
- Fix in `Mockup.html`:
  - In `createDeviceMesh` wurde ein Basis-Yaw von 180° ergänzt.
  - Der nutzerseitige `rotation.y` Wert bleibt als zusätzlicher Offset erhalten.
- Ergebnis: Gerät ist auf der richtigen Seite und zeigt nun auch in die erwartete Richtung.

### 2026-04-18 - X-Axis Centered Placement + Phase 5 Kickoff
- Placement-Feinschliff:
  - `placement.x` ist nun mittig ausgerichtet (0 = Center der inneren Rack-Breite).
  - `placement.y` und `placement.z` bleiben inner-origin-basiert (unten/vorne).
- Validierung angepasst:
  - X-Bounds werden jetzt symmetrisch um die Mitte geprüft (`-inner.x/2` bis `+inner.x/2`).
- Phase 5 gestartet (Engineering Extensions):
  - Erstes Rack-Load-Overlay ergänzt (`Gesamtlast`, `Rack-Limit`, `Auslastung`).
  - Warn-/Error-Hervorhebung bei hoher oder überschrittener Last.
  - Device-Gewichte werden aus Daten übernommen, sonst typbasiert geschätzt.

### 2026-04-18 - Phase 5 Load Distribution (Vertical Segments)
- Rack-Load-Overlay um Lastverteilung über die Höhe erweitert.
- Neue Kennzahlen in `Mockup.html`:
  - Lastschwerpunkt auf Y (`Schwerpunkt Y` in mm).
  - Segmentlast unten->oben (5 vertikale Segmente der inneren Höhe).
- Datenquelle:
  - Gerätemasse aus `weightKg`/`weight_kg`, sonst typbasierte Fallback-Schätzung.
  - Segmentzuordnung über Device-Y-Mittelpunkt.
- Ergebnis: Schnellere Erkennung von Last-Hotspots und Schwerpunktlage im Rack.

### 2026-04-18 - Phase 5 Visual Heat Overlay
- Last-Hotspots werden jetzt zusätzlich direkt im 3D-Rack visualisiert.
- Umsetzung in `Mockup.html`:
  - Halbtransparente, farbkodierte Höhensegmente im inneren Rackvolumen.
  - Farblogik je Segmentlast (grün = niedriger, rot = höher relativ zur Max-Segmentlast).
  - Neuer Toggle `Last-Heat-Overlay anzeigen` für Ein/Aus.
- Ergebnis: Lastverteilung ist als Zahlen + räumliche Heat-Darstellung sofort interpretierbar.

### 2026-04-18 - Port Implementation (Device Port Overlay)
- Port-Visualisierung in `Mockup.html` ergänzt:
  - Ports werden als kleine Front-Elemente pro Gerät gerendert.
  - Neuer Toggle `Ports anzeigen` für Ein/Aus.
- Live-DB-Integration erweitert:
  - Zusätzliches Laden von `device_port` über API.
  - Zuordnung von Ports über `device_port.device` auf Geräte-UUID.
- Datenmodell:
  - Pro Gerät wird `ports[]` normalisiert (`name`, `placement`, `size`, `type`, `color`).
  - Fallback-Positionen/-Größen für fehlende Portdaten.
- Debug/Abgleich:
  - Geräteausgabe enthält jetzt UUID und Portanzahl je Gerät.

### 2026-04-18 - Port Coordinate Space Auto-Detection
- Rückmeldung: Port-Positionen wirkten wie vom inneren Rack-Root statt vom Gerät aus berechnet.
- Fix in `Mockup.html`:
  - Auto-Erkennung des Port-Koordinatenraums ergänzt.
  - Device-lokale Portdaten werden direkt verwendet.
  - Rack-/Global-ähnliche Portdaten werden vor dem Rendern in Geräteraum umgerechnet.
- Ergebnis: Port-Overlay ist robuster gegenüber unterschiedlichen Datenquellen/Koordinatenkonventionen.

### 2026-04-18 - Port Front/Back Depth Fix
- Rückmeldung: Ports erschienen trotz korrekter Zuordnung noch auf der Rückseite.
- Fix in `Mockup.html`:
  - Port-Tiefenachse auf Front-Referenz umgestellt (`z=0` an Gerätefront).
  - Port-Z wird nun als `-d/2 + z + depth/2` berechnet (statt rückseitiger Ableitung).
- Ergebnis: Ports werden von der Vorderseite aus ins Gerät gelegt.

### 2026-04-18 - Port Left-Origin + Foreground Highlight
- Rückmeldung:
  - `x=0` lag visuell rechts statt links.
  - Negative `z`-Werte konnten Ports nicht vor die Front führen.
  - Ports sollten zur Hervorhebung im Vordergrund liegen.
- Fix in `Mockup.html`:
  - X-Mapping für Ports gespiegelt (kompatibel mit 180° Device-Basisyaw), damit `x=0` links ist.
  - Front-Clamp auf Z entfernt: Ports können bei negativem `z` vor der Gerätefront starten.
  - Port-Meshes erhalten höhere Render-Priorität (`renderOrder`) und deaktivierten Depth-Test für bessere Sichtbarkeit.
- Ergebnis: Port-Positionierung folgt der erwarteten Links/Front-Logik und bleibt deutlich sichtbar.

### 2026-04-18 - Rear Ports Support (No Back Clamp)
- Rückmeldung: Rückseiten-Ports (z. B. Strom) sollen nicht durch Back-Clamp begrenzt sein.
- Fix in `Mockup.html`:
  - Z-Clamp auf der Rückseite entfernt (Port-Z wird nicht mehr hart begrenzt).
  - Side-aware Mapping ergänzt: Ports unterstützen jetzt `side = front|rear`.
  - Front/Rear-Z-Referenz wird entsprechend berechnet (beide Seiten mit eigenem Ursprung).
  - Debug-Ausgabe zeigt zusätzlich Port-Statistik (`total`, `front`, `rear`).
- Ergebnis: Ports können kontrolliert auf Vorder- und Rückseite modelliert werden, inkl. freier Tiefe ohne Rückseiten-Clamping.

### 2026-04-18 - UX Polishing: MMB Pan + Toggle Sync + Port Side Editor
- Kamera-Steuerung:
  - Mittlere Maustaste (`MIDDLE`) steuert nun Pan in OrbitControls.
- Toggle-Konsistenz beim Start:
  - Runtime-Flags werden jetzt aus den tatsächlichen Checkbox-States initialisiert.
  - Behebt Fälle, in denen Browser-Form-Restore und Rendering-Status auseinanderlaufen (z. B. Heat-Overlay).
- Port-Editor im Formbereich:
  - Port-Auswahl je Gerät + Feld `side (front/rear)` ergänzt.
  - Button `Port-Seite übernehmen` schreibt die Änderung zurück ins JSON und rendert sofort neu.

### 2026-04-18 - Phase 5 Next Step: Center-of-Mass Marker
- Lastanalyse wurde um einen visuellen Schwerpunktmarker erweitert.
- Umsetzung in `Mockup.html`:
  - Neuer Toggle `Last-Schwerpunktmarker anzeigen`.
  - Marker wird aus `centerOfMassY` berechnet und als horizontale Linie + Markerpunkt im inneren Rack dargestellt.
- Ergebnis: Schwerpunktlage ist nicht nur numerisch, sondern direkt räumlich erkennbar.

### 2026-04-18 - Phase 7 Foundation: Room Mode (Multi-Rack)
- `Mockup.html` um Room-Modus erweitert:
  - Toggle `Room-Modus (mehrere Racks)`.
  - Raumparameter (`room.width`, `room.depth`, `aisle`) im UI editierbar.
  - Mehrere geladene DB-Racks werden in einem gemeinsamen Raumkoordinatensystem als Grid platziert.
- Zusätzliche Auswertung:
  - `Room Summary` mit Rackanzahl, Geräteanzahl, Flächennutzung und Performance-Einschätzung.
- Ziel: Technisches Fundament für die nächste Ausbaustufe (Kollisionen, Wege, fokussierte Detailstufen).

### 2026-04-18 - Phase 7 Performance: Proximity, Chunking, Limits
- Room-Renderer für große Datenmengen gehärtet:
  - Auto-Near lädt Geräte nur für kameranahe Racks.
  - Chunking begrenzt gerenderte Rack-Mengen auf nahe Raumsegmente.
  - Harte Render-Budgets für Racks und Geräte (`rack.render.limit`, `device.render.limit`).
- Datenpfad optimiert:
  - DB-Geräte werden beim Laden pro Rack vorindiziert/cached (`liveDevicesByRack`).
  - Verhindert teure Neu-Filterung über komplette Device-Listen pro Renderlauf.
- Stabilität:
  - Sticky/Hysterese für Auto-Near reduziert Umschalt-Churn beim Kamerafahren.

### 2026-04-18 - Phase 7 Safety Checks: Clearance + Collision
- Room-Layout um Sicherheitsprüfung erweitert:
  - Mindestabstand (`min.clearance`) konfigurierbar.
  - Paarweise Clearance-Analyse für gerenderte Racks.
  - Kollisionserkennung bei überlappenden Rack-Footprints.
- Ergebnisdarstellung in `Room Summary`:
  - Ist-/Soll-Clearance, Anzahl Kollisionen, Anzahl Clearance-Verstöße.
  - Warn-/Error-Hervorhebung reagiert jetzt auch auf Safety-Verletzungen.

### 2026-04-18 - Phase 5/7 Cable Routing Integration (DB `connection`)
- Schemaabgleich gegen `includes/core/db_tables.json` durchgeführt.
- `Mockup.html` erweitert um Kabelrouting:
  - DB-Laden liest jetzt zusätzlich Tabelle `connection`.
  - Normalisierung für `device_port_source`/`device_port_destination` (inkl. Fallback auf `expected_*`).
  - Port-UUIDs werden in Port-Modellen geführt und für Verbindungsanker verwendet.
  - 3D-Kabelpfade werden als Kurven zwischen Portankern gerendert.
- Performance/UX:
  - Toggle `Kabel anzeigen` ergänzt.
  - Budget `cable.render.limit` ergänzt.
  - Room-Summary zeigt gerenderte Kabelanzahl.

### 2026-04-18 - Layout UX Update (Viewer 100% + Sidebar Scroll)
- Viewer-Höhe auf volle verfügbare Höhe gestellt.
- Seitenleiste (`panel`) auf vertikales Scrollen umgestellt.
- Mobil-Breakpoint bleibt mit reduzierter Panelhöhe und 58vh-Viewport nutzbar.

### 2026-04-18 - Cable Visibility + Next Routing Steps
- Kabelsichtbarkeit im Mockup verbessert:
  - Default-JSON enthält jetzt Beispiel-Port-UUIDs und Beispiel-`connections`.
  - Kabel werden dadurch auch ohne DB-Daten sofort sichtbar.
  - Kabel-Linien rendern mit Vordergrund-Priorität (ähnlich Port-Overlay).
- Nächste Ausbauschritte begonnen:
  - Rear-aware Routing-Option (`Kabel rear-aware Routing`) ergänzt.
  - Cable Summary/Legend ergänzt (Counts je Typ, Crossover, Speed-Range, gerendert/gesamt).

### 2026-04-18 - Rear Ports + Inter-Rack Fiber Bundle Showcase
- Default-Beispieldaten erweitert um Rear-Ports:
  - Rear-SFP/Fiber-Ports an Switch/Patchpanel.
  - Rear-Power-Ports am Firewall-Beispiel.
- Beispiel-Connections erweitert:
  - Strom- und Fiber-Verbindungen inkl. `item_group` für Bundle-Darstellung.
- Room-Sample-Modus erweitert:
  - Sample-Racks erhalten eindeutige Port-UUIDs pro Rack.
  - Zusätzliche synthetische Fiber-Trunks zwischen benachbarten Sample-Racks.
  - Bundle-Gruppen teilen sich Routing-Lanes für besser lesbare Glasfaser-Bündel.

### 2026-04-18 - Cable Labels + Downward Trunk Routing
- Neue UI-Toggles in `Mockup.html`:
  - `Port-Beschriftung anzeigen`
  - `Kabel-Beschriftung anzeigen`
- Label-Rendering ergänzt:
  - Ports und Kabel werden optional als Sprite-Labels im 3D-Viewport dargestellt.
  - Label-Material/Texture werden beim Scene-Cleanup explizit freigegeben.
- Trunk-Routing angepasst:
  - Inter-Rack-Trunks nutzen `routeProfile: downward` statt Overhead.
  - Pfadführung senkt Trunks auf ein bodennahes Tray-Niveau vor dem Horizontalabschnitt.

### 2026-04-18 - Next Expansion: Cable Filter Controls
- Kabel-Filter ergänzt, um komplexe Room-Szenen gezielt zu analysieren:
  - `Fiber`, `Copper/CAT`, `Power`, `Trunks` einzeln ein-/ausblendbar.
- Routing-Engine (`createCableRoutingGroup`) filtert vor dem Mesh-Bau.
- Cable Summary erweitert um `Ausgeblendet (Filter)`.
- Render-Zählung korrigiert:
  - Kabel-Counts basieren auf tatsächlichen Kabelmeshes (`renderedCount`) statt auf Gruppenkindern (damit Labels nicht als Kabel mitgezählt werden).

### 2026-04-18 - Phase 5 Open Points Implemented (Power/Thermal + Cable Metadata)
- Last-Overlay wurde von reinem Gewichts-Heatmap-Modus auf umschaltbare Metriken erweitert:
  - `Gewicht (kg)`
  - `Power (W)`
  - `Thermal (W)`
- Datenpfad erweitert:
  - Geräte können nun `powerW`/`power_w` und `thermalW`/`thermal_w` führen.
  - Bei fehlenden Feldern werden typbasierte Fallback-Werte verwendet.
- Rack-Load-Summary erweitert:
  - `Power gesamt` und `Thermal gesamt` werden im Summary angezeigt.
  - Segmentdaten enthalten jetzt parallel `kg`, `powerW`, `thermalW`.
- Kabel-Metadaten in Labels erweitert:
  - Optionales Label-Suffix mit `Typ`, `Speed`, `Länge`.
  - Toggle `Kabel-Metadaten in Labels` ergänzt.

### 2026-04-18 - Phase 5 Budget Warnings (Weight/Power/Thermal)
- Rack-Modell unterstützt jetzt zusätzlich:
  - `powerLimitW`
  - `thermalLimitW`
- Load-Summary erweitert:
  - Auslastung für Gewicht, Power und Thermal mit Limitvergleich.
  - Warn-/Error-Zustände reagieren jetzt auf alle drei Budgets.
  - Segmentausgabe zeigt pro Segment: `kg`, `W`, `thermal W`.
  - Segment-Peaks als schnelle Hotspot-Kennzahl ergänzt.

### 2026-04-19 - Phase 5 Debug Presets & Trunk Waypoints
- **Debug Presets** in `Mockup.html` implementiert:
  - Vier vordefinierte Ansichten hinzugefügt: `Full`, `Debug Trunks`, `Power Only`, `Minimal`.
  - Presets setzen alle Filter-, Label-, Overlay-Toggles atomar mit `applyPreset()`.
  - Ermöglicht schnelles Umschalten zwischen Debugging-Ansichten bei komplexen Room-Layouts.
- **Trunk Waypoints** jetzt einstellbar:
  - Neue UI-Eingabefelder im `Trunk Waypoints`-Abschnitt:
    - `downward floor (mm)`: Höhe des Unterschleif-Trays (default: 6 mm).
    - `downward clearance (mm)`: Abstand zu Geräten nach unten (default: 260 mm).
    - `overhead height (mm)`: Höhe über Rack-Oberseite für overhead-Trunks (default: 260 mm).
  - Änderungen triggern sofortiges Re-Rendering ohne Scene-Cache-Bypass.
  - Tray-Höhe wird direkt in `createCableRoutingGroup()` genutzt (sowohl Single-Rack als auch Room-Mode).

## 12. Open Points (as of 2026-04-19)

### Program Goal (authoritative)
- **Portflow soll die komplette Funktionalität aus `Mockup.html` abbilden** (Feature-Parity, nicht nur Teilintegration).
- Umsetzung erfolgt schrittweise mit priorisiertem Backlog, damit bestehende Workflows stabil bleiben.

### High Priority
- Cable Routing QoL:
  - ✅ Presets für schnelle Filter-/Debug-Ansichten: **DONE** - Full, Debug Trunks, Power Only, Minimal.
  - ✅ Trunk-Waypoint/Tray-Parameter als UI-Regler: **DONE** - downward floor, clearance, overhead height.
  - Automatische Kollisionsvermeidung auf Segment-Ebene (Segment-vs-AABB) ist noch nicht implementiert.

### High Priority - Device & Port Modeling UX (new)
- Device 3D-Maske in Portflow analog zur Location-Maske:
  - [x] Gefuehrte Eingaben fuer `size`, `position`, `rotation` statt manueller JSON-Pflege.
  - [x] RU-basierte Eingabe (`startRU`, `heightRU`) inkl. automatischer Umrechnung nach mm.
  - [x] Live-Writeback in bestehende JSON-Felder (backward-kompatibel).
- Port-Layout als Hybrid (Preset + Regeln):
  - [ ] Presets als Schnellstart (24x1, 2x12, 48x1, 2x24, rear PSU etc.).
  - [ ] Regelbasierte Gruppen-Layout-Engine (rows/cols/pitch/anchor/side/mirror/label pattern).
  - [ ] Front/Rear-Side pro Port-Gruppe konsistent im Viewer sichtbar.
- Auto-Port-Funktion erhalten und erweitern:
  - [x] Bestehende Auto-Port-Erstellung beibehalten.
  - [x] Erweiterung um Port-Typfamilien: copper/RJ45, SFP/SFP+, QSFP, power-in/out, mgmt/console.
  - [x] Optionale Typ-spezifische Defaults (size/depth/color/labeling).

### Medium Priority
- Engineering Extensions (Phase 5):
  - Thermal/Power-Overlay ist noch rudimentär bzw. nicht vollständig datengetrieben.
  - Port-/Kabel-Metadaten (z. B. Länge, Typ, Speed) als optionale In-Scene-Legend/Tooltip können erweitert werden.

### Medium Priority - Port Authoring UX (new)
- 2D-Panel-Editor für Gerätefront/-rückseite:
  - [ ] Port-Gruppen visuell auf Front/Rear platzieren (Grid/Canvas).
  - [ ] Numerische Feineingabe + Snap/Grid + Preview.
  - [ ] Speicherung als layoutbasierte Definition, daraus Generierung einzelner `device_port`-Einträge.

### Medium/Long Term
- Visual Extensions (Phase 6):
  - Real component images / glTF assets noch offen.
  - Front/Rear view presets für schnellen Perspektivwechsel fehlen.
  - Hybrid mode (parametric + real assets) ist noch offen.

### Long Term
- Room Survey Expansion (Phase 7+):
  - Raumstruktur-Objekte (Wände/Türen/Trassen) als echte Constraints fehlen.
  - Walkway-/Pathway-Analyse jenseits rack-rack clearance ist noch offen.

## 13. Recommended Next Build Step
Strategie fuer die naechste Umsetzungsphase (priorisiert):

1. **Device-3D-Maske + RU-Workflow (Portflow Forms)**
  - Guided Inputs fuer Device-Geometrie, Position und Rotation.
  - RU-zu-mm Mapping fuer hoehenbezogene Platzierung im Rack.
2. **Auto-Port 2.0 (typed)**
  - Bestehende Auto-Port-Logik erweitern um Typfamilien inkl. SFP/Power.
  - Presets fuer haeufige Portanordnungen in einem Schritt erzeugen.
3. **Port-Layout-Engine (Preset + Regeln)**
  - Gruppenbasierte Regeldefinitionen fuer wiederverwendbare Front/Rear-Layouts.
4. **2D Front/Rear Editor (Ausbaustufe)**
  - Visuelle Platzierung als Authoring-UI, JSON/Port-Generierung automatisiert.

Danach optional:
- Segment-level Collision Avoidance
- glTF/Real-Assets
- Hybrid Mode

## 14. Integration Details (2026-04-19)

### Architecture
- **PortflowViewer3D.js**: Standalone ES6 module in `js/PortflowViewer3D.js`
- **Mockup.html**: Remains as development/testing tool
- **itam.php**: Location-Details include 3D tab for type=8 (Rack) **und** type=6 (Room)

### Integration Points

**In itam.php (Location Details):**
1. Details popup structure extended with tab navigation
2. New Tab: "3D Ansicht" (appears for type=8 and type=6 locations)
3. `switchDetailsTab()` function handles tab switching
4. `initiate3DViewer()` dynamically imports and initializes the viewer
5. Room mode uses `loadRoom(...)`, rack mode uses `loadRack(...)`
5. Tab state reset on close to prevent stale viewer instances

### Usage Flow
1. User opens Location Details for a Rack (type=8) oder Room (type=6)
2. "3D Ansicht" tab appears automatically
3. Click tab → 3D Viewer loads rack data oder multi-rack room data
4. Controls:
   - Overlay-Metrik selector (Gewicht/Power/Thermal)
   - Toggle Ports/Cables visibility
   - Orbit camera controls in 3D view

### File Changes
- Created: `/js/PortflowViewer3D.js` (ES6 module, ~250 lines)
- Created: `/includes/3d-viewer-modal.html` (reference template)
- Modified: `/itam.php` (added tab navigation, viewer initialization)

### Why This Approach
✅ **Modular**: PortflowViewer3D can be reused in other Portflow pages  
✅ **Non-intrusive**: No breaking changes to existing ITAM workflow  
✅ **Lazy-loaded**: 3D module only imported when 3D tab is clicked  
✅ **Responsive**: Works on desktop; collapse on mobile  
✅ **Maintainable**: UI logic separated from 3D viewer logic

## 15. Mockup Parity Audit & Implementation Todo (2026-04-19)

### Current State Comment
- Rack-Viewer is integrated in ITAM and functional, but visual/UX parity with the mockup is incomplete.
- Main gaps observed in current integration:
  - Editing masks did not always reflect persisted rack values reliably.
  - Rack appeared as a simplified outer shell without strong inner-volume readback.
  - Viewer viewport was too small compared to mockup layout.
  - Camera UX lacked explicit middle-mouse pan behavior.
  - Performance felt degraded due to repeated redraw pressure and suboptimal viewport sizing.

### ToDo List to Reach Mockup Feature Parity
- Data fidelity & form mapping:
  - [ ] Ensure edit mode always preloads persisted JSON (`position`, `rotation`, `size`) regardless of source shape.
  - [ ] Keep backward compatibility for older `between` key naming (`y_front/y_back/z_bottom/z_top`).
  - [ ] Keep rack limits editable in form and available to overlay logic.
- Rack geometry fidelity:
  - [ ] Render both outer and inner rack boundaries from saved dimensions.
  - [ ] Respect offsets from `between` consistently for device placement.
  - [ ] Improve material contrast on dark background (grey/translucent defaults).
  - [ ] Provide interactive open/close states for doors and side panels.
- Viewer UX/layout parity:
  - [ ] Move controls to left sidebar and maximize viewer height.
  - [ ] Add explicit camera control hints and middle-mouse pan mapping.
  - [ ] Keep responsive behavior for smaller screens.
- Performance hardening:
  - [ ] Avoid unnecessary full rerenders when possible.
  - [ ] Improve resize handling (observer-based renderer/camera resize sync).
  - [ ] Keep render cache signature aligned with all geometry-affecting toggles.
- Remaining open points (next execution queue):
  - [~] Room chunking controls from mockup as real ITAM controls (chunk sizing / target chunks / concurrency now in ITAM; nearby count, render limits, min clearance still open).
  - [~] Port layout engine with reusable rules (rows/cols/pitch/anchor/side/mirror/label patterns) plus presets. Device form + auto-port rule engine now implemented; visual editor and richer presets still open.
  - [~] Visual 2D front/rear port editor for authoring with snap/grid and numeric fine-tuning. Live 2D preview is now available in the device form; direct drag/drop editing is still open.
  - [ ] Segment-based cable collision avoidance (segment-vs-obstacle) to reduce visual overlap in dense rooms.
  - [ ] Optional real assets mode (glTF/component models) and hybrid rendering (parametric + asset).

### 2026-04-20 - Bugfix: Empty Details Popup Overlay
- ITAM fix in `itam.php`:
  - Added robust guard in `openDetailsPopup(...)` for empty/invalid row payloads.
  - Forced deterministic tab reset to `info` on popup open to avoid stale hidden-content states.
  - Cleared stale 3D panel content when opening/closing details popup.
  - Explicitly hide tab navigation/3D button on close to prevent ghost UI state.
  - Added `#detailsPopup.hidden { display: none; }` CSS fallback to guarantee hidden behavior.

### 2026-04-20 - Viewer Height + Cable Rendering
- ITAM 3D panel updated:
  - Redundant headline above the viewer removed.
  - Viewer layout corrected so the canvas fills the available content-box height on desktop.
  - Room expert controls for fetch tuning added (`chunk size`, `target chunks`, `max concurrency`) and trigger room reload.
- `PortflowViewer3D.js` updated:
  - Real cable geometry is now rendered from normalized connection data.
  - Connections now fall back to `expected_device_port_source` / `expected_device_port_destination` when direct endpoints are empty.
  - Cable routing works across neighboring racks using computed port anchors and visible tube geometry.

### 2026-04-20 - Port Layout Engine (Rule-Based Auto Ports)
- Device form extended with layout controls:
  - `port_layout_preset`, `rows`, `cols`, `pitch_x`, `pitch_y`, `side`, `anchor`, `mirror`, `label_pattern`.
- Auto-port generation now uses a reusable ruleset instead of profile-only placement.
- Layout side is persisted schema-compatible inside the port `position` JSON and read back by the 3D viewer.
- Existing `port_profile` flow remains compatible and maps to default layout presets automatically.
- Device form now also includes a live 2D port-layout preview so front/rear distribution and mirroring are visible before saving.

### Implementation Progress (started)
- [x] Form extended with `position` in `location_details` flow (`forms.json`).
- [x] Location Room/Rack masks extended with position + rack limits and JSON writeback.
- [x] Viewer layout changed to mockup-like split: left controls, large right viewport.
- [x] Orbit controls now include middle-mouse panning.
- [x] Rack renderer now draws inner frame boundary and improved material contrast.
- [x] Viewer resize observer added to keep canvas/camera synced to container size.
- [x] Legacy `between` key compatibility added in rack normalization.

### Next Steps
- Finalize geometry readback validation against a real saved rack dataset (inner/outer/between exactness).
- Add optional perspective presets (front/rear/isometric) and camera-reset utility.
- Continue reducing redraw overhead for large device counts.

### 2026-04-19 - Strategy Sync (Mockup -> Portflow Full Parity)
- Zielbild festgelegt: Portflow soll den kompletten Mockup-Funktionsumfang erhalten.
- Umsetzungsstrategie in priorisierten ToDo-Block ueberfuehrt:
  1) Device-3D-Maske mit RU-Eingabe,
  2) Auto-Port-Erweiterung fuer SFP/Power/typed ports,
  3) Port-Layout-Hybrid aus Presets + Regel-Engine,
  4) optionaler 2D Front/Rear Port-Editor als Authoring-Stufe.
- Bestehende Auto-Port-Funktion bleibt explizit erhalten und wird kompatibel erweitert.

### 2026-04-19 - Phase 1 Started (Device 3D Mask)
- In `itam.php` wurde fuer `device_details` eine Device-3D-Maske aktiviert.
- JSON-Textfelder `size`, `position`, `rotation` werden im Formular ausgeblendet und durch gefuehrte Eingaben ersetzt.
- RU-Workflow implementiert: `startRU` + `heightRU` werden automatisch in mm nach `position.y` und `size.y` umgerechnet.
- Bestehende Datensaetze (Edit Mode, Templates) werden rueckwaertskompatibel aus vorhandenen JSON-Werten in die Maske eingelesen.

### 2026-04-19 - Phase 2 Started (Auto-Port typed scaffolding)
- In `forms.json` wurden additive Felder fuer Auto-Port-Profile und Port-Typfamilien eingefuehrt.
- In `itam.php` nutzt der Auto-Port-Flow nun Profile (`switch-24`, `switch-48`, `switch-2x12`, `switch-2x24`, `psu-dual`) als Count-Defaults.
- Typfamilien (`copper`, `sfp`, `qsfp`, `power`, `mgmt`, `console`) setzen initiale `device_port`-Defaults (`type`, `size`, `position`, `rotation`) bei der automatischen Port-Erzeugung.
- Backward-Kompatibilitaet bleibt erhalten: bestehende Felder `port_count`/`port_start_label` funktionieren weiterhin.

### 2026-04-19 - Continued Implementation (Mockup Parity Workstream)
- Form prefill reliability improved:
  - Object-valued fields are now serialized to JSON before being written into form inputs.
  - Prevents `[object Object]` regressions when loading existing rack/location data.
- Viewer UX upgraded:
  - Left control sidebar now includes camera preset buttons (`Front`, `Rear`, `Left`, `Iso`).
  - Middle mouse panning is explicitly mapped in OrbitControls.
  - Large viewer viewport retained (`72vh`, min-height guard).
- Rack rendering fidelity improved:
  - Added clearer frame representation with corner posts and top/bottom rails.
  - Inner rack frame remains visible for dimension readback and placement context.
- Performance/robustness updates:
  - API debug logging is now gated behind `options.debug`.
  - ResizeObserver keeps renderer and camera in sync with container size changes.

### 2026-04-19 - DB Data Source Fix for Rack Viewer
- Root cause identified for wrong rack dimensions in 3D view:
  - Viewer used `where=uuid=...` style query strings that were not reliably interpreted by API filtering.
  - Result: potentially unfiltered/first-row rack data used in rendering.
- Fix in `js/PortflowViewer3D.js`:
  - Rack load now uses explicit UUID parameter: `location?uuid=<rackUuid>`.
  - Device load now uses explicit column filter: `device?location=<rackUuid>`.
  - `_toRowsArray` now also accepts single-object API responses as one-row arrays.
- Expected outcome:
  - 3D viewer uses the exact selected rack dataset (including `location_size` geometry) instead of fallback/foreign rows.
