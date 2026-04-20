/**
 * PortflowViewer3D - ES6 Module für 3D-Rack-Visualisierung
 * 
 * Einsatz:
 *   import { PortflowViewer3D } from './js/PortflowViewer3D.js';
 *   
 *   const viewer = new PortflowViewer3D('#container', { apiUrl: './api/' });
 *   await viewer.loadRack('location-uuid');
 *   viewer.setOverlayMetric('power');
 *   viewer.destroy(); // Cleanup
 */

export class PortflowViewer3D {
  constructor(containerSelector, options = {}) {
    this.container = document.querySelector(containerSelector);
    if (!this.container) {
      throw new Error(`Container ${containerSelector} not found`);
    }

    this.options = {
      apiUrl: options.apiUrl || './api/',
      width: options.width || this.container.clientWidth,
      height: options.height || this.container.clientHeight,
      debug: options.debug === true,
      fetchTuning: {
        minChunkSize: 40,
        maxChunkSize: 300,
        targetChunks: 8,
        minConcurrency: 1,
        maxConcurrency: 4,
        ...(options.fetchTuning || {})
      },
      ...options
    };

    this.state = {
      THREE: null,
      scene: null,
      camera: null,
      renderer: null,
      controls: null,
      loaded: false,
      renderLoopId: null,
      resizeObserver: null,
      
      // Toggles
      showRackEars: true,
      showLoadOverlay: false,
      loadOverlayMetric: 'weight',
      showPorts: true,
      showPortLabels: false,
      showCables: true,
      showCableLabels: false,
      showCableMetaLabels: true,
      cableFilterFiber: true,
      cableFilterCopper: true,
      cableFilterPower: true,
      cableFilterTrunk: true,
      cableRearAware: false,
      showComMarker: false,
      axisLockMode: 'free',
      rackTransparent: false,
      doorsOpen: true,
      sidePanelsOpen: false,
      
      // Data
      liveRacks: [],
      liveDevices: [],
      liveConnections: [],
      activeRack: null,
      sceneMode: 'rack',
      roomFocusRackUuid: '',
      roomFocusDevicesOnly: false,
      currentSceneUuid: null,
      
      lastModelSignature: null
    };

    this.statusCallback = options.onStatus || (() => {});
  }

  /**
   * Lädt einen einzelnen Rack (Single-Rack-Modus)
   */
  async loadRack(locationUuid, rackSeedRow = null) {
    try {
      this.statusCallback('Lade Rack-Daten...', false);
      
      // Three.js ggf. noch nicht geladen
      if (!this.state.THREE) {
        await this._init3D();
      }

      // Rack primary source: selected details row from UI (deterministic).
      // Fallback source: API lookup.
      let racks = [];
      if (rackSeedRow && typeof rackSeedRow === 'object') {
        const seedUuid = this._rowUuid(rackSeedRow);
        if (!seedUuid || seedUuid === String(locationUuid)) {
          racks = [rackSeedRow];
          if (this.options.debug) {
            console.log('[3D-DEBUG] Rack seed row accepted:', {
              seedUuid,
              requestedUuid: locationUuid,
              hasLocationSize: !!rackSeedRow.location_size,
              hasSize: !!rackSeedRow.size
            });
          }
        }
      }

      if (racks.length === 0) {
        const rackData = await this._fetchAllPages('location_details', { location_uuid: locationUuid, limit: 200 });
        racks = rackData;
      }

      if (!racks || racks.length === 0) {
        throw new Error(`Rack ${locationUuid} not found`);
      }

      const rackRow = this._selectRackRowByUuid(racks, locationUuid);
      const selectedUuid = this._rowUuid(rackRow);
      if (this.options.debug) {
        console.log('[3D-DEBUG] Rack load summary:', {
          requestedUuid: locationUuid,
          returnedRows: racks.length,
          returnedUuids: racks.map(r => this._rowUuid(r)).filter(Boolean),
          selectedUuid
        });
      }

      if (!rackRow) {
        throw new Error(`Rack row for ${locationUuid} not found in response`);
      }

      if (selectedUuid && selectedUuid !== String(locationUuid)) {
        this.statusCallback(`Warnung: Rack-Mismatch (requested ${locationUuid}, selected ${selectedUuid})`, true);
      }

      const devices = await this._fetchAllPages('device', {
        location: locationUuid,
        limit: 500
      });

      const deviceUuids = devices
        .map(row => String(row?.uuid || '').trim())
        .filter(Boolean);

      const ports = await this._fetchByInFilter('device_port', 'device', deviceUuids, { limit: 1000 });

      const portUuids = ports
        .map(row => String(row?.uuid || '').trim())
        .filter(Boolean);

      const [connectionsBySource, connectionsByDestination, connectionsByExpectedSource, connectionsByExpectedDestination] = await Promise.all([
        this._fetchByInFilter('connection', 'device_port_source', portUuids, { limit: 1000 }),
        this._fetchByInFilter('connection', 'device_port_destination', portUuids, { limit: 1000 }),
        this._fetchByInFilter('connection', 'expected_device_port_source', portUuids, { limit: 1000 }),
        this._fetchByInFilter('connection', 'expected_device_port_destination', portUuids, { limit: 1000 })
      ]);

      const connections = this._mergeRowsByUuid([
        ...connectionsBySource,
        ...connectionsByDestination,
        ...connectionsByExpectedSource,
        ...connectionsByExpectedDestination
      ]);

      const rackModel = this._normalizeRack(rackRow);
      const portsByDevice = new Map();
      for (const portRow of ports) {
        const normalizedPort = this._normalizePort(portRow);
        if (!normalizedPort) continue;
        const key = String(portRow.device || portRow.device_uuid || portRow.device_id || '').trim();
        if (!key) continue;
        if (!portsByDevice.has(key)) portsByDevice.set(key, []);
        portsByDevice.get(key).push(normalizedPort);
      }

      const deviceModels = devices
        .map(d => this._normalizeDevice(d, portsByDevice.get(String(d.uuid || '').trim()) || []))
        .filter(Boolean);
      const connectionModels = this._normalizeConnections(connections || []);

      if (this.options.debug) {
        console.log('[3D-DEBUG] Rack geometry used:', {
          uuid: rackModel.uuid,
          outer: rackModel.geometry.outer,
          inner: rackModel.geometry.inner,
          between: rackModel.geometry.between,
          limits: {
            loadLimitKg: rackModel.loadLimitKg,
            powerLimitW: rackModel.powerLimitW,
            thermalLimitW: rackModel.thermalLimitW
          }
        });
      }

      this.state.liveRacks = [rackModel];
      this.state.liveDevices = deviceModels;
      this.state.liveConnections = connectionModels;
      this.state.activeRack = rackModel;
      this.state.sceneMode = 'rack';
      this.state.currentSceneUuid = locationUuid;

      this.state.lastModelSignature = null;
      this._renderSingleRack();
      this.setCameraPreset('iso');
      
      this.statusCallback('Rack geladen', false);
    } catch (e) {
      this.statusCallback(`Fehler: ${e.message}`, true);
      throw e;
    }
  }

  /**
   * Lädt alle Racks eines Raumes
   */
  async loadRoom(roomUuid) {
    try {
      this.statusCallback('Lade Raum-Daten...', false);

      if (!this.state.THREE) {
        await this._init3D();
      }

      const rackRows = await this._fetchAllPages('location', {
        parent_location: roomUuid,
        type: 8,
        limit: 500
      });

      if (!rackRows.length) {
        throw new Error(`Keine Racks im Raum ${roomUuid} gefunden`);
      }

      const rackModels = rackRows.map(row => this._normalizeRack(row)).filter(Boolean);
      const rackUuidSet = new Set(rackModels.map(r => String(r.uuid || '').trim()).filter(Boolean));

      if (this.state.roomFocusRackUuid && !rackUuidSet.has(this.state.roomFocusRackUuid)) {
        this.state.roomFocusRackUuid = '';
      }

      const deviceRows = await this._fetchByInFilter('device', 'location', Array.from(rackUuidSet), { limit: 1000 });

      const deviceUuids = deviceRows
        .map(row => String(row?.uuid || '').trim())
        .filter(Boolean);

      const ports = await this._fetchByInFilter('device_port', 'device', deviceUuids, { limit: 1000 });

      const portUuids = ports
        .map(row => String(row?.uuid || '').trim())
        .filter(Boolean);

      const [connectionsBySource, connectionsByDestination, connectionsByExpectedSource, connectionsByExpectedDestination] = await Promise.all([
        this._fetchByInFilter('connection', 'device_port_source', portUuids, { limit: 1000 }),
        this._fetchByInFilter('connection', 'device_port_destination', portUuids, { limit: 1000 }),
        this._fetchByInFilter('connection', 'expected_device_port_source', portUuids, { limit: 1000 }),
        this._fetchByInFilter('connection', 'expected_device_port_destination', portUuids, { limit: 1000 })
      ]);
      const connections = this._mergeRowsByUuid([
        ...connectionsBySource,
        ...connectionsByDestination,
        ...connectionsByExpectedSource,
        ...connectionsByExpectedDestination
      ]);

      const portsByDevice = new Map();
      for (const portRow of ports) {
        const normalizedPort = this._normalizePort(portRow);
        if (!normalizedPort) continue;
        const key = String(portRow.device || portRow.device_uuid || portRow.device_id || '').trim();
        if (!key) continue;
        if (!portsByDevice.has(key)) portsByDevice.set(key, []);
        portsByDevice.get(key).push(normalizedPort);
      }

      const deviceModels = deviceRows
        .map(d => this._normalizeDevice(d, portsByDevice.get(String(d.uuid || '').trim()) || []))
        .filter(Boolean);

      this.state.liveRacks = rackModels;
      this.state.liveDevices = deviceModels;
      this.state.liveConnections = this._normalizeConnections(connections || []);
      this.state.activeRack = rackModels[0] || null;
      this.state.sceneMode = 'room';
      this.state.currentSceneUuid = roomUuid;

      this.state.lastModelSignature = null;
      this._renderSingleRack();
      this.setCameraPreset('iso');

      this.statusCallback(`Raum geladen (${rackModels.length} Racks)`, false);
    } catch (e) {
      this.statusCallback(`Fehler: ${e.message}`, true);
      throw e;
    }
  }

  /**
   * Setzt Overlay-Metrik (weight, power, thermal)
   */
  setOverlayMetric(metric) {
    if (['weight', 'power', 'thermal'].includes(metric)) {
      this.state.loadOverlayMetric = metric;
      this.state.lastModelSignature = null;
      if (this.state.loaded) this._renderSingleRack();
    }
  }

  /**
   * Toggle Kabel-Filter
   */
  setFiberVisible(visible) {
    this.state.cableFilterFiber = visible;
    this._invalidateCache();
  }

  setCopperVisible(visible) {
    this.state.cableFilterCopper = visible;
    this._invalidateCache();
  }

  setcopperVisible(visible) {
    this.setCopperVisible(visible);
  }

  setPowerCableVisible(visible) {
    this.state.cableFilterPower = visible;
    this._invalidateCache();
  }

  setCablePreset(preset = 'all') {
    const normalized = String(preset || 'all').toLowerCase();
    switch (normalized) {
      case 'power':
        this.state.cableFilterFiber = false;
        this.state.cableFilterCopper = false;
        this.state.cableFilterPower = true;
        this.state.cableFilterTrunk = false;
        break;
      case 'fiber':
        this.state.cableFilterFiber = true;
        this.state.cableFilterCopper = false;
        this.state.cableFilterPower = false;
        this.state.cableFilterTrunk = false;
        break;
      case 'copper':
        this.state.cableFilterFiber = false;
        this.state.cableFilterCopper = true;
        this.state.cableFilterPower = false;
        this.state.cableFilterTrunk = false;
        break;
      case 'minimal':
        this.state.cableFilterFiber = false;
        this.state.cableFilterCopper = true;
        this.state.cableFilterPower = false;
        this.state.cableFilterTrunk = false;
        this.state.showCables = false;
        break;
      case 'all':
      default:
        this.state.cableFilterFiber = true;
        this.state.cableFilterCopper = true;
        this.state.cableFilterPower = true;
        this.state.cableFilterTrunk = true;
        break;
    }

    if (normalized !== 'minimal') {
      this.state.showCables = true;
    }
    this._invalidateCache();
  }

  setDoorsOpen(open) {
    this.state.doorsOpen = !!open;
    this._invalidateCache();
  }

  setSidePanelsOpen(open) {
    this.state.sidePanelsOpen = !!open;
    this._invalidateCache();
  }

  setRackTransparent(transparent) {
    this.state.rackTransparent = !!transparent;
    this._invalidateCache();
  }

  setRackEarsVisible(visible) {
    this.state.showRackEars = !!visible;
    this._invalidateCache();
  }

  setLoadOverlayVisible(visible) {
    this.state.showLoadOverlay = !!visible;
    this._invalidateCache();
  }

  setPortsVisible(visible) {
    this.state.showPorts = !!visible;
    this._invalidateCache();
  }

  setPortLabelsVisible(visible) {
    this.state.showPortLabels = !!visible;
    this._invalidateCache();
  }

  setCablesVisible(visible) {
    this.state.showCables = !!visible;
    this._invalidateCache();
  }

  setCableLabelsVisible(visible) {
    this.state.showCableLabels = !!visible;
    this._invalidateCache();
  }

  setCableMetaLabelsVisible(visible) {
    this.state.showCableMetaLabels = !!visible;
    this._invalidateCache();
  }

  setTrunkVisible(visible) {
    this.state.cableFilterTrunk = !!visible;
    this._invalidateCache();
  }

  setRearAware(visible) {
    this.state.cableRearAware = !!visible;
    this._invalidateCache();
  }

  setComMarkerVisible(visible) {
    this.state.showComMarker = !!visible;
    this._invalidateCache();
  }

  setAxisLock(mode = 'free') {
    if (!this.state.controls || !this.state.camera) return;

    const lockMode = String(mode || 'free').toLowerCase();
    this.state.axisLockMode = lockMode;

    if (lockMode === 'horizontal') {
      const offset = this.state.camera.position.clone().sub(this.state.controls.target);
      const radius = Math.max(0.0001, offset.length());
      const polar = Math.acos(Math.min(1, Math.max(-1, offset.y / radius)));
      const epsilon = 0.0001;
      this.state.controls.minPolarAngle = Math.max(epsilon, polar - epsilon);
      this.state.controls.maxPolarAngle = Math.min(Math.PI - epsilon, polar + epsilon);
      this.state.controls.minAzimuthAngle = -Infinity;
      this.state.controls.maxAzimuthAngle = Infinity;
    } else {
      this.state.controls.minPolarAngle = 0;
      this.state.controls.maxPolarAngle = Math.PI;
      this.state.controls.minAzimuthAngle = -Infinity;
      this.state.controls.maxAzimuthAngle = Infinity;
    }

    this.state.controls.update();
    this._requestRender();
  }

  setCameraPreset(preset = 'iso') {
    if (!this.state.camera || !this.state.controls || !this.state.activeRack) return;

    if (this.state.sceneMode === 'room' && this.state.liveRacks.length > 1 && preset === 'iso' && !this.state.roomFocusRackUuid) {
      this._requestRender();
      return;
    }

    const rack = this.state.activeRack;
    const outerX = rack.geometry.outer.x / 1000;
    const outerY = rack.geometry.outer.y / 1000;
    const outerZ = rack.geometry.outer.z / 1000;
    const targetY = outerY * 0.5;

    this.state.controls.target.set(0, targetY, 0);

    switch (preset) {
      case 'front':
        this.state.camera.position.set(0, targetY, outerZ * 2.2);
        break;
      case 'rear':
        this.state.camera.position.set(0, targetY, -outerZ * 2.2);
        break;
      case 'left':
        this.state.camera.position.set(-outerX * 2.2, targetY, 0);
        break;
      case 'right':
        this.state.camera.position.set(outerX * 2.2, targetY, 0);
        break;
      case 'top':
        this.state.camera.position.set(0, outerY * 2.6, 0.001);
        break;
      case 'iso':
      default:
        this.state.camera.position.set(outerX * 1.6, targetY + outerY * 0.35, outerZ * 1.8);
        break;
    }

    this.state.controls.update();
    this.setAxisLock(this.state.axisLockMode || 'free');
    this._requestRender();
  }

  setRoomFocusRack(rackUuid = '') {
    this.state.roomFocusRackUuid = String(rackUuid || '').trim();
    this._invalidateCache();
  }

  setRoomFocusDevicesOnly(enabled) {
    this.state.roomFocusDevicesOnly = !!enabled;
    this._invalidateCache();
  }

  setFetchTuning(tuning = {}) {
    const current = this.options.fetchTuning || {};
    this.options.fetchTuning = {
      ...current,
      ...(tuning || {})
    };
  }

  /**
   * Cleanup
   */
  destroy() {
    if (this.state.renderLoopId) {
      cancelAnimationFrame(this.state.renderLoopId);
    }
    if (this.state.resizeObserver) {
      this.state.resizeObserver.disconnect();
      this.state.resizeObserver = null;
    }
    if (this.state.renderer) {
      this.state.renderer.dispose();
    }
    if (this.state.controls) {
      this.state.controls.dispose();
    }
    if (this.container) {
      this.container.innerHTML = '';
    }
    this.state = null;
  }

  // === Privat Private Methods ===

  async _init3D() {
    const THREE = await import('https://esm.sh/three@0.164.1');
    const OrbitControls = (await import('https://esm.sh/three@0.164.1/examples/jsm/controls/OrbitControls.js')).OrbitControls;

    this.state.THREE = THREE;
    this.state.scene = new THREE.Scene();
    this.state.scene.background = new THREE.Color(0x111827);

    // Camera
    const w = this.options.width;
    const h = this.options.height;
    this.state.camera = new THREE.PerspectiveCamera(60, w / h, 0.01, 100);
    this.state.camera.position.set(0.5, 1.2, 1.5);

    // Renderer
    this.state.renderer = new THREE.WebGLRenderer({ antialias: true });
    this.state.renderer.setSize(w, h);
    this.state.renderer.setPixelRatio(window.devicePixelRatio);
    this.container.appendChild(this.state.renderer.domElement);
    this._attachResizeObserver();

    // Controls
    this.state.controls = new OrbitControls(this.state.camera, this.state.renderer.domElement);
    this.state.controls.enableDamping = false;
    this.state.controls.screenSpacePanning = true;
    this.state.controls.mouseButtons = {
      LEFT: THREE.MOUSE.ROTATE,
      MIDDLE: THREE.MOUSE.PAN,
      RIGHT: THREE.MOUSE.ROTATE
    };
    this.state.controls.addEventListener('change', () => this._requestRender());

    // Lighting
    const light = new THREE.DirectionalLight(0xffffff, 0.9);
    light.position.set(0.5, 1.5, 1);
    this.state.scene.add(light);
    this.state.scene.add(new THREE.AmbientLight(0xffffff, 0.4));

    // Grid
    const gridHelper = new THREE.GridHelper(4, 40, 0x444444, 0x222222);
    this.state.scene.add(gridHelper);

    this.state.loaded = true;
    this._requestRender();
  }

  _renderSingleRack() {
    if (!this.state.loaded || !this.state.liveRacks.length) return;

    // Cache check
    const sig = JSON.stringify({
      racks: this.state.liveRacks.map(r => ({ uuid: r.uuid, geometry: r.geometry, position: r.position })),
      devices: this.state.liveDevices,
      connections: this.state.liveConnections,
      rackTransparent: this.state.rackTransparent,
      doorsOpen: this.state.doorsOpen,
      sidePanelsOpen: this.state.sidePanelsOpen,
      showCables: this.state.showCables,
      showCableLabels: this.state.showCableLabels,
      showCableMetaLabels: this.state.showCableMetaLabels,
      cableFilterFiber: this.state.cableFilterFiber,
      cableFilterCopper: this.state.cableFilterCopper,
      cableFilterPower: this.state.cableFilterPower,
      cableFilterTrunk: this.state.cableFilterTrunk,
      cableRearAware: this.state.cableRearAware,
      metric: this.state.loadOverlayMetric,
      sceneMode: this.state.sceneMode
    });
    if (this.state.lastModelSignature === sig) return;
    this.state.lastModelSignature = sig;

    // Clear scene (except lights/grid)
    while (this.state.scene.children.length > 3) {
      const child = this.state.scene.children[3];
      if (child.geometry) child.geometry.dispose();
      if (child.material) {
        if (Array.isArray(child.material)) {
          child.material.forEach(m => m.dispose());
        } else {
          child.material.dispose();
        }
      }
      this.state.scene.remove(child);
    }

    // Render rack(s)
    for (const rack of this.state.liveRacks) {
      const rackUuid = String(rack.uuid || '').trim();
      const isFocusedRack = !this.state.roomFocusRackUuid || this.state.roomFocusRackUuid === rackUuid;
      const includeDevices = !(this.state.sceneMode === 'room' && this.state.roomFocusDevicesOnly && !isFocusedRack);
      const rackDevices = includeDevices
        ? this.state.liveDevices.filter(d => String(d.location || '').trim() === rackUuid)
        : [];
      this._buildRackGeometry({ rack, devices: rackDevices, connections: this.state.liveConnections }, rack.position || { x: 0, y: 0, z: 0 });
    }

    if (this.state.showCables && Array.isArray(this.state.liveConnections) && this.state.liveConnections.length > 0) {
      const portAnchorMap = this._collectPortAnchors();
      this._buildCableGeometry(this.state.liveConnections, portAnchorMap);
    }

    // Camera adjust
    if (this.state.sceneMode === 'room' && this.state.liveRacks.length > 1) {
      const focusedRack = this._getRoomFocusedRack();
      if (focusedRack) {
        const pos = focusedRack.position || { x: 0, y: 0, z: 0 };
        const px = (Number(pos.x) || 0) / 1000;
        const py = (Number(pos.y) || 0) / 1000;
        const pz = (Number(pos.z) || 0) / 1000;
        const outerX = (Number(focusedRack.geometry?.outer?.x) || 600) / 1000;
        const outerY = (Number(focusedRack.geometry?.outer?.y) || 2200) / 1000;
        const outerZ = (Number(focusedRack.geometry?.outer?.z) || 1000) / 1000;
        const targetY = py + (outerY * 0.5);
        const span = Math.max(outerX, outerZ, 1.2);
        this.state.controls.target.set(px, targetY, pz);
        this.state.camera.position.set(px + span * 1.1, targetY + span * 0.9, pz + span * 1.2);
        this._requestRender();
        return;
      }

      let minX = Infinity;
      let maxX = -Infinity;
      let minZ = Infinity;
      let maxZ = -Infinity;
      let sumY = 0;
      let count = 0;

      for (const rack of this.state.liveRacks) {
        const pos = rack.position || { x: 0, y: 0, z: 0 };
        const px = (Number(pos.x) || 0) / 1000;
        const pz = (Number(pos.z) || 0) / 1000;
        const halfX = (Number(rack.geometry?.outer?.x) || 600) / 2000;
        const halfZ = (Number(rack.geometry?.outer?.z) || 1000) / 2000;

        minX = Math.min(minX, px - halfX);
        maxX = Math.max(maxX, px + halfX);
        minZ = Math.min(minZ, pz - halfZ);
        maxZ = Math.max(maxZ, pz + halfZ);
        sumY += ((Number(pos.y) || 0) / 1000) + ((Number(rack.geometry?.outer?.y) || 2200) / 2000);
        count += 1;
      }

      if (count > 0 && Number.isFinite(minX) && Number.isFinite(maxX) && Number.isFinite(minZ) && Number.isFinite(maxZ)) {
        const centerX = (minX + maxX) * 0.5;
        const centerZ = (minZ + maxZ) * 0.5;
        const centerY = sumY / count;
        const span = Math.max(maxX - minX, maxZ - minZ, 1.2);
        this.state.controls.target.set(centerX, centerY, centerZ);
        this.state.camera.position.set(centerX + span * 0.8, centerY + span * 0.9, centerZ + span * 1.0);
      }
    } else {
      const rack = this.state.liveRacks[0];
      const rackX = (Number(rack.position?.x) || 0) / 1000;
      const rackY = ((Number(rack.position?.y) || 0) / 1000) + (rack.geometry.outer.y * 0.5 / 1000);
      const rackZ = (Number(rack.position?.z) || 0) / 1000;
      this.state.controls.target.set(rackX, rackY, rackZ);
      this.state.camera.position.y = rackY + 1.2;
    }
    this._requestRender();
  }

  _buildRackGeometry(model, worldPos = { x: 0, y: 0, z: 0 }) {
    const THREE = this.state.THREE;
    const rack = model.rack;
    const devices = model.devices;

    const group = new THREE.Group();

    const outerX = rack.geometry.outer.x / 1000;
    const outerY = rack.geometry.outer.y / 1000;
    const outerZ = rack.geometry.outer.z / 1000;
    const innerX = rack.geometry.inner.x / 1000;
    const innerY = rack.geometry.inner.y / 1000;
    const innerZ = rack.geometry.inner.z / 1000;

    const halfX = outerX / 2;
    const halfY = outerY / 2;
    const halfZ = outerZ / 2;

    const frameGeo = new THREE.BoxGeometry(outerX, outerY, outerZ);
    const frameEdges = new THREE.EdgesGeometry(frameGeo);
    const frameLines = new THREE.LineSegments(
      frameEdges,
      new THREE.LineBasicMaterial({ color: 0xb4bcc8 })
    );
    frameLines.position.y = halfY;
    group.add(frameLines);

    const postMat = new THREE.MeshStandardMaterial({
      color: 0x9ca3af,
      metalness: 0.25,
      roughness: 0.5
    });
    const postThickness = Math.max(0.01, Math.min(outerX, outerZ) * 0.03);
    const postGeo = new THREE.BoxGeometry(postThickness, outerY, postThickness);
    const postPositions = [
      [-halfX + postThickness * 0.5, halfY, -halfZ + postThickness * 0.5],
      [halfX - postThickness * 0.5, halfY, -halfZ + postThickness * 0.5],
      [-halfX + postThickness * 0.5, halfY, halfZ - postThickness * 0.5],
      [halfX - postThickness * 0.5, halfY, halfZ - postThickness * 0.5]
    ];
    postPositions.forEach(([x, y, z]) => {
      const post = new THREE.Mesh(postGeo, postMat);
      post.position.set(x, y, z);
      group.add(post);
    });

    const railGeoX = new THREE.BoxGeometry(outerX - postThickness * 2, postThickness, postThickness);
    const railGeoZ = new THREE.BoxGeometry(postThickness, postThickness, outerZ - postThickness * 2);
    const yBottom = postThickness * 0.5;
    const yTop = outerY - postThickness * 0.5;

    [yBottom, yTop].forEach(y => {
      const frontRail = new THREE.Mesh(railGeoX, postMat);
      frontRail.position.set(0, y, halfZ - postThickness * 0.5);
      group.add(frontRail);

      const backRail = new THREE.Mesh(railGeoX, postMat);
      backRail.position.set(0, y, -halfZ + postThickness * 0.5);
      group.add(backRail);

      const leftRail = new THREE.Mesh(railGeoZ, postMat);
      leftRail.position.set(-halfX + postThickness * 0.5, y, 0);
      group.add(leftRail);

      const rightRail = new THREE.Mesh(railGeoZ, postMat);
      rightRail.position.set(halfX - postThickness * 0.5, y, 0);
      group.add(rightRail);
    });

    const xLeft = (rack.geometry.between.x_left || 0) / 1000;
    const xRight = (rack.geometry.between.x_right || 0) / 1000;
    const yBottomOffset = (rack.geometry.between.y_bottom || 0) / 1000;
    const yTopOffset = (rack.geometry.between.y_top || 0) / 1000;
    const zFrontOffset = (rack.geometry.between.z_front || 0) / 1000;
    const zBackOffset = (rack.geometry.between.z_back || 0) / 1000;

    const innerMinX = -halfX + xLeft;
    const innerMaxX = halfX - xRight;
    const innerMinY = yBottomOffset;
    const innerMaxY = outerY - yTopOffset;
    const innerMaxZ = halfZ - zFrontOffset;
    const innerMinZ = -halfZ + zBackOffset;

    const innerCenterX = (innerMinX + innerMaxX) * 0.5;
    const innerCenterY = (innerMinY + innerMaxY) * 0.5;
    const innerCenterZ = (innerMinZ + innerMaxZ) * 0.5;

    const innerGeo = new THREE.BoxGeometry(innerX, innerY, innerZ);
    const innerEdges = new THREE.EdgesGeometry(innerGeo);
    const innerLines = new THREE.LineSegments(
      innerEdges,
      new THREE.LineBasicMaterial({ color: 0x94a3b8 })
    );
    innerLines.position.set(innerCenterX, innerCenterY, innerCenterZ);
    group.add(innerLines);

    const panelMat = new THREE.MeshStandardMaterial({
      color: 0x9ca3af,
      metalness: 0.2,
      roughness: 0.65,
      transparent: true,
      opacity: this.state.rackTransparent ? 0.28 : 0.85,
      depthWrite: false,
      side: THREE.DoubleSide
    });

    const sideThickness = 0.01;
    const sideGeo = new THREE.PlaneGeometry(outerZ, outerY);
    if (!this.state.sidePanelsOpen) {
      const leftPanel = new THREE.Mesh(sideGeo, panelMat.clone());
      leftPanel.position.set(-halfX, halfY, 0);
      leftPanel.rotation.y = Math.PI / 2;
      group.add(leftPanel);

      const rightPanel = new THREE.Mesh(sideGeo, panelMat.clone());
      rightPanel.position.set(halfX, halfY, 0);
      rightPanel.rotation.y = -Math.PI / 2;
      group.add(rightPanel);
    }

    const topGeo = new THREE.PlaneGeometry(outerX, outerZ);
    const topPanel = new THREE.Mesh(topGeo, panelMat.clone());
    topPanel.position.set(0, outerY, 0);
    topPanel.rotation.x = -Math.PI / 2;
    group.add(topPanel);

    const backDoorGeo = new THREE.PlaneGeometry(outerX, outerY);
    const doorMat = new THREE.MeshStandardMaterial({
      color: 0xcbd5e1,
      metalness: 0.15,
      roughness: 0.45,
      transparent: true,
      opacity: 0.22,
      depthWrite: false,
      side: THREE.DoubleSide
    });

    const frontDoorPivot = new THREE.Group();
    frontDoorPivot.position.set(halfX, halfY, halfZ + sideThickness);
    const frontDoor = new THREE.Mesh(backDoorGeo, doorMat.clone());
    frontDoor.position.set(-outerX * 0.5, 0, 0);
    frontDoorPivot.add(frontDoor);

    const backDoorPivot = new THREE.Group();
    backDoorPivot.position.set(-halfX, halfY, -halfZ - sideThickness);
    const backDoor = new THREE.Mesh(backDoorGeo, doorMat.clone());
    backDoor.position.set(outerX * 0.5, 0, 0);
    backDoorPivot.add(backDoor);

    if (this.state.doorsOpen) {
      frontDoorPivot.rotation.y = -Math.PI * 0.5;
      backDoorPivot.rotation.y = Math.PI * 0.5;
    }

    group.add(frontDoorPivot);
    group.add(backDoorPivot);

    // Devices
    for (const device of devices) {
      const sizeX = Number(device?.size?.x) || 445;
      const sizeY = Number(device?.size?.y) || 44;
      const sizeZ = Number(device?.size?.z) || 300;
      const deviceGeo = new THREE.BoxGeometry(
        sizeX / 1000,
        sizeY / 1000,
        sizeZ / 1000
      );
      
      // Wähle Farbe basierend auf Overlay-Metrik
      let deviceColor = parseInt(device.color || '#0f766e'.slice(1), 16);
      if (this.state.showLoadOverlay && this.state.loadOverlayMetric) {
        deviceColor = this._getMetricColor(device, rack);
      }
      
      const deviceMat = new THREE.MeshStandardMaterial({
        color: deviceColor,
        metalness: 0.2,
        roughness: 0.8
      });
      const deviceMesh = new THREE.Mesh(deviceGeo, deviceMat);
      const deviceGroup = new THREE.Group();
      deviceGroup.add(deviceMesh);

      // Placement model (mockup-compatible): x=centered, y=bottom-origin, z=front-origin
      const deviceX = Number(device?.placement?.x);
      const deviceY = Number(device?.placement?.y);
      const deviceZ = Number(device?.placement?.z);

      const deviceCenterX = innerCenterX + (Number.isFinite(deviceX) ? (deviceX / 1000) : 0);
      const deviceCenterY = innerMinY + (Number.isFinite(deviceY) ? (deviceY / 1000) : 0) + (sizeY / 2000);
      const deviceCenterZ = innerMaxZ - (Number.isFinite(deviceZ) ? (deviceZ / 1000) : 0) - (sizeZ / 2000);

      deviceGroup.position.set(deviceCenterX, deviceCenterY, deviceCenterZ);

      const rotX = Number(device?.rotation?.x) || 0;
      const rotY = Number(device?.rotation?.y) || 0;
      const rotZ = Number(device?.rotation?.z) || 0;
      const baseYawDeg = 180;
      deviceGroup.rotation.set(
        THREE.MathUtils.degToRad(rotX),
        THREE.MathUtils.degToRad(baseYawDeg + rotY),
        THREE.MathUtils.degToRad(rotZ)
      );

      if (this.state.showRackEars) {
        const earThickness = Math.min(0.035, (sizeZ / 1000) * 0.25);
        const earDepth = 0.002;
        const earHeight = Math.max(0.006, sizeY / 1000);
        const earZ = (-sizeZ / 2000) + (earDepth / 2);
        const earMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.35, metalness: 0.65 });
        const earGeo = new THREE.BoxGeometry(earThickness, earHeight, earDepth);

        const leftEar = new THREE.Mesh(earGeo, earMat);
        leftEar.position.set(-(sizeX / 2000) - (earThickness / 2), 0, earZ);
        deviceGroup.add(leftEar);

        const rightEar = new THREE.Mesh(earGeo, earMat);
        rightEar.position.set((sizeX / 2000) + (earThickness / 2), 0, earZ);
        deviceGroup.add(rightEar);
      }

      if (this.state.showPorts && Array.isArray(device.ports) && device.ports.length > 0) {
        const portsGroup = new THREE.Group();
        for (const port of device.ports) {
          const pSize = port.size || { x: 12, y: 12, z: 10 };
          const pw = Math.max(0.008, (Number(pSize.x) || 12) / 1000);
          const ph = Math.max(0.008, (Number(pSize.y) || 12) / 1000);
          const pd = Math.max(0.006, (Number(pSize.z) || 10) / 1000);
          const pPlace = port.placement || { x: 20, y: 10, z: 2 };

          const pXmm = Number(pPlace.x) || 0;
          const pYmm = Number(pPlace.y) || 0;
          const pZmm = Number(pPlace.z) || 2;
          const portSide = String(port.side || '').toLowerCase() === 'rear' ? 'rear' : 'front';

          const px = (sizeX / 2000) - (pXmm / 1000) - (pw / 2);
          const py = -(sizeY / 2000) + (pYmm / 1000) + (ph / 2);
          const outsideOffset = 0.0015;
          const pz = portSide === 'rear'
            ? (sizeZ / 2000) + (pZmm / 1000) + (pd / 2) + outsideOffset
            : -(sizeZ / 2000) - (pZmm / 1000) - (pd / 2) - outsideOffset;

          const pGeo = new THREE.BoxGeometry(pw, ph, pd);
          const pMat = new THREE.MeshStandardMaterial({
            color: port.color || 0x93c5fd,
            emissive: 0x1f2937,
            emissiveIntensity: 0.35,
            roughness: 0.3,
            metalness: 0.55
          });
          const pMesh = new THREE.Mesh(pGeo, pMat);
          pMesh.position.set(
            Math.max(-(sizeX / 2000) + (pw / 2), Math.min((sizeX / 2000) - (pw / 2), px)),
            Math.max(-(sizeY / 2000) + (ph / 2), Math.min((sizeY / 2000) - (ph / 2), py)),
            pz
          );
          portsGroup.add(pMesh);

          if (this.state.showPortLabels) {
            const txt = String(port.name || port.uuid || '').trim();
            if (txt) {
              const lbl = this._createTextLabelSprite(THREE, txt, {
                worldWidth: 0.08,
                fontSize: 28,
                bgColor: 'rgba(15,23,42,0.78)'
              });
              lbl.position.set(pMesh.position.x, pMesh.position.y + Math.max(ph * 0.8, 0.01), pMesh.position.z + (portSide === 'rear' ? 0.006 : -0.006));
              portsGroup.add(lbl);
            }
          }
        }
        deviceGroup.add(portsGroup);
      }

      group.add(deviceGroup);

      if (this.state.showPortLabels || this.state.showCableLabels) {
        const labelText = device.name || device.uuid || 'Device';
        const label = this._createTextLabelSprite(THREE, labelText, {
          bgColor: 'rgba(15,23,42,0.85)',
          textColor: '#f8fafc',
          worldWidth: 0.24
        });
        label.position.set(
          deviceCenterX,
          deviceCenterY + (sizeY / 1000) * 0.75,
          deviceCenterZ
        );
        group.add(label);
      }
    }

    if (this.state.showComMarker && devices.length > 0) {
      const totalWeight = devices.reduce((sum, d) => sum + Math.max(0, Number(d.weightKg) || 0), 0);
      if (totalWeight > 0) {
        let cx = 0;
        let cy = 0;
        let cz = 0;
        for (const device of devices) {
          const w = Math.max(0, Number(device.weightKg) || 0);
          const dx = Number(device?.placement?.x);
          const dy = Number(device?.placement?.y);
          const dz = Number(device?.placement?.z);
          const sizeY = Number(device?.size?.y) || 44;
          const sizeZ = Number(device?.size?.z) || 300;

          const px = innerCenterX + (Number.isFinite(dx) ? (dx / 1000) : 0);
          const py = innerMinY + (Number.isFinite(dy) ? (dy / 1000) : 0) + (sizeY / 2000);
          const pz = innerMaxZ - (Number.isFinite(dz) ? (dz / 1000) : 0) - (sizeZ / 2000);
          cx += px * w;
          cy += py * w;
          cz += pz * w;
        }

        const marker = new THREE.Mesh(
          new THREE.SphereGeometry(0.025, 18, 18),
          new THREE.MeshStandardMaterial({ color: 0xf59e0b, emissive: 0x78350f, emissiveIntensity: 0.5 })
        );
        marker.position.set(cx / totalWeight, cy / totalWeight, cz / totalWeight);
        group.add(marker);
      }
    }

    group.position.set(
      (Number(worldPos?.x) || 0) / 1000,
      (Number(worldPos?.y) || 0) / 1000,
      (Number(worldPos?.z) || 0) / 1000
    );
    this.state.scene.add(group);
  }

  _collectPortAnchors() {
    const THREE = this.state.THREE;
    const anchors = new Map();

    for (const rack of this.state.liveRacks || []) {
      const rackUuid = String(rack?.uuid || '').trim();
      if (!rackUuid) continue;

      const rackWorld = new THREE.Vector3(
        (Number(rack?.position?.x) || 0) / 1000,
        (Number(rack?.position?.y) || 0) / 1000,
        (Number(rack?.position?.z) || 0) / 1000
      );

      const outerX = (Number(rack?.geometry?.outer?.x) || 600) / 1000;
      const outerY = (Number(rack?.geometry?.outer?.y) || 2200) / 1000;
      const outerZ = (Number(rack?.geometry?.outer?.z) || 1000) / 1000;
      const halfX = outerX / 2;
      const halfZ = outerZ / 2;
      const between = rack?.geometry?.between || {};
      const xLeft = (Number(between.x_left) || 0) / 1000;
      const xRight = (Number(between.x_right) || 0) / 1000;
      const yBottomOffset = (Number(between.y_bottom) || 0) / 1000;
      const yTopOffset = (Number(between.y_top) || 0) / 1000;
      const zFrontOffset = (Number(between.z_front) || 0) / 1000;
      const zBackOffset = (Number(between.z_back) || 0) / 1000;

      const innerMinY = yBottomOffset;
      const innerMaxY = outerY - yTopOffset;
      const innerMaxZ = halfZ - zFrontOffset;
      const innerMinZ = -halfZ + zBackOffset;
      const innerCenterX = ((-halfX + xLeft) + (halfX - xRight)) * 0.5;

      const rackDevices = (this.state.liveDevices || []).filter(device => String(device?.location || '').trim() === rackUuid);
      for (const device of rackDevices) {
        const sizeX = Number(device?.size?.x) || 445;
        const sizeY = Number(device?.size?.y) || 44;
        const sizeZ = Number(device?.size?.z) || 300;
        const deviceX = Number(device?.placement?.x);
        const deviceY = Number(device?.placement?.y);
        const deviceZ = Number(device?.placement?.z);

        const deviceCenter = new THREE.Vector3(
          innerCenterX + (Number.isFinite(deviceX) ? (deviceX / 1000) : 0),
          innerMinY + (Number.isFinite(deviceY) ? (deviceY / 1000) : 0) + (sizeY / 2000),
          innerMaxZ - (Number.isFinite(deviceZ) ? (deviceZ / 1000) : 0) - (sizeZ / 2000)
        );

        const rotX = Number(device?.rotation?.x) || 0;
        const rotY = Number(device?.rotation?.y) || 0;
        const rotZ = Number(device?.rotation?.z) || 0;
        const deviceRotation = new THREE.Euler(
          THREE.MathUtils.degToRad(rotX),
          THREE.MathUtils.degToRad(180 + rotY),
          THREE.MathUtils.degToRad(rotZ)
        );

        for (const port of device?.ports || []) {
          const portUuid = String(port?.uuid || '').trim();
          if (!portUuid) continue;

          const portSize = port.size || { x: 12, y: 12, z: 10 };
          const pw = Math.max(0.008, (Number(portSize.x) || 12) / 1000);
          const ph = Math.max(0.008, (Number(portSize.y) || 12) / 1000);
          const pd = Math.max(0.006, (Number(portSize.z) || 10) / 1000);
          const placement = port.placement || { x: 20, y: 10, z: 2 };
          const pXmm = Number(placement.x) || 0;
          const pYmm = Number(placement.y) || 0;
          const pZmm = Number(placement.z) || 2;
          const portSide = String(port.side || '').toLowerCase() === 'rear' ? 'rear' : 'front';
          const outsideOffset = 0.0015;

          const localPos = new THREE.Vector3(
            (sizeX / 2000) - (pXmm / 1000) - (pw / 2),
            -(sizeY / 2000) + (pYmm / 1000) + (ph / 2),
            portSide === 'rear'
              ? (sizeZ / 2000) + (pZmm / 1000) + (pd / 2) + outsideOffset
              : -(sizeZ / 2000) - (pZmm / 1000) - (pd / 2) - outsideOffset
          );

          localPos.x = Math.max(-(sizeX / 2000) + (pw / 2), Math.min((sizeX / 2000) - (pw / 2), localPos.x));
          localPos.y = Math.max(-(sizeY / 2000) + (ph / 2), Math.min((sizeY / 2000) - (ph / 2), localPos.y));

          const worldPoint = localPos.clone().applyEuler(deviceRotation).add(deviceCenter).add(rackWorld);
          const localNormal = new THREE.Vector3(0, 0, portSide === 'rear' ? 1 : -1);
          const worldNormal = localNormal.applyEuler(deviceRotation).normalize();

          anchors.set(portUuid, {
            portUuid,
            rackUuid,
            deviceUuid: String(device?.uuid || '').trim(),
            point: worldPoint,
            normal: worldNormal,
            rackTopY: rackWorld.y + innerMaxY,
            rackBottomY: rackWorld.y + innerMinY,
            rackFrontZ: rackWorld.z + innerMaxZ,
            rackRearZ: rackWorld.z + innerMinZ
          });
        }
      }
    }

    return anchors;
  }

  _buildCableGeometry(connections, portAnchorMap) {
    const THREE = this.state.THREE;
    if (!THREE || !portAnchorMap || !(portAnchorMap instanceof Map)) {
      return;
    }

    const cablesGroup = new THREE.Group();
    let renderedCount = 0;

    for (const connection of connections || []) {
      if (!connection || !this._shouldRenderCable(connection)) continue;

      const sourceAnchor = portAnchorMap.get(String(connection.sourcePortUuid || '').trim());
      const destinationAnchor = portAnchorMap.get(String(connection.destinationPortUuid || '').trim());
      if (!sourceAnchor || !destinationAnchor) {
        continue;
      }

      const routePoints = this._buildCableRoutePoints(sourceAnchor, destinationAnchor, connection);
      if (routePoints.length < 2) continue;

      const curve = new THREE.CatmullRomCurve3(routePoints);
      const cableRadius = connection.cableKind === 'power'
        ? 0.007
        : connection.cableKind === 'fiber'
          ? 0.0032
          : 0.0045;
      const segments = Math.max(20, routePoints.length * 10);
      const geometry = new THREE.TubeGeometry(curve, segments, cableRadius, 10, false);
      const material = new THREE.MeshStandardMaterial({
        color: connection.color,
        emissive: connection.color,
        emissiveIntensity: 0.16,
        roughness: 0.55,
        metalness: 0.18
      });
      const mesh = new THREE.Mesh(geometry, material);
      mesh.renderOrder = 3;
      cablesGroup.add(mesh);
      renderedCount += 1;

      if (this.state.showCableLabels) {
        const labelText = this._connectionLabel(connection);
        if (labelText) {
          const label = this._createTextLabelSprite(THREE, labelText, {
            worldWidth: 0.16,
            fontSize: 24,
            bgColor: 'rgba(17,24,39,0.82)'
          });
          const midPoint = curve.getPoint(0.5);
          label.position.copy(midPoint);
          label.position.y += 0.04;
          cablesGroup.add(label);
        }
      }
    }

    if (renderedCount > 0) {
      this.state.scene.add(cablesGroup);
    }
  }

  _buildCableRoutePoints(sourceAnchor, destinationAnchor, connection) {
    const outward = 0.06;
    const sourceOut = sourceAnchor.point.clone().add(sourceAnchor.normal.clone().multiplyScalar(outward));
    const destinationOut = destinationAnchor.point.clone().add(destinationAnchor.normal.clone().multiplyScalar(outward));
    const points = [sourceAnchor.point.clone(), sourceOut];

    const isInterRack = sourceAnchor.rackUuid !== destinationAnchor.rackUuid;
    if (isInterRack) {
      const trayY = Math.max(sourceAnchor.rackTopY, destinationAnchor.rackTopY) + 0.16;
      points.push(new this.state.THREE.Vector3(sourceOut.x, trayY, sourceOut.z));
      points.push(new this.state.THREE.Vector3(destinationOut.x, trayY, destinationOut.z));
    } else if (this.state.cableRearAware) {
      const bridgeZ = (sourceAnchor.normal.z + destinationAnchor.normal.z) >= 0
        ? Math.max(sourceAnchor.rackRearZ, destinationAnchor.rackRearZ) - 0.08
        : Math.max(sourceAnchor.rackFrontZ, destinationAnchor.rackFrontZ) + 0.08;
      points.push(new this.state.THREE.Vector3(sourceOut.x, sourceOut.y, bridgeZ));
      points.push(new this.state.THREE.Vector3(destinationOut.x, destinationOut.y, bridgeZ));
    } else {
      const midY = Math.max(sourceOut.y, destinationOut.y) + 0.04;
      const midX = (sourceOut.x + destinationOut.x) * 0.5;
      const midZ = (sourceOut.z + destinationOut.z) * 0.5;
      points.push(new this.state.THREE.Vector3(midX, midY, midZ));
    }

    points.push(destinationOut, destinationAnchor.point.clone());
    return points;
  }

  _shouldRenderCable(connection) {
    if (!this.state.showCables || !connection) {
      return false;
    }

    if (connection.isTrunk && !this.state.cableFilterTrunk) {
      return false;
    }

    if (connection.cableKind === 'power') {
      return this.state.cableFilterPower;
    }

    if (connection.cableKind === 'fiber') {
      return this.state.cableFilterFiber;
    }

    return this.state.cableFilterCopper;
  }

  _connectionLabel(connection) {
    const parts = [];
    if (connection.name) {
      parts.push(connection.name);
    }
    if (this.state.showCableMetaLabels) {
      if (connection.typeLabel) parts.push(connection.typeLabel);
      if (connection.speed) parts.push(`${connection.speed}`);
      if (connection.length) parts.push(`${connection.length}m`);
    }
    return parts.join(' | ');
  }

  _getMetricColor(device, rack) {
    let value = 0;
    let limit = 1;
    
    switch (this.state.loadOverlayMetric) {
      case 'weight':
        value = device.weightKg || 0;
        limit = rack.loadLimitKg || 120;
        break;
      case 'power':
        value = device.powerW || 0;
        limit = rack.powerLimitW || 1200;
        break;
      case 'thermal':
        value = device.thermalW || 0;
        limit = rack.thermalLimitW || 1100;
        break;
    }
    
    const ratio = Math.min(value / limit, 1);
    
    // Farbverlauf: Grün (0%) -> Rot (100%)
    const hue = (1 - ratio) * 120 / 360; // Green to Red
    const saturation = 0.8;
    const lightness = 0.5;
    
    return this._hslToHex(hue, saturation, lightness);
  }

  _hslToHex(h, s, l) {
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs((h * 6) % 2 - 1));
    const m = l - c / 2;
    
    let r = 0, g = 0, b = 0;
    if (h < 1/6) { r = c; g = x; }
    else if (h < 2/6) { r = x; g = c; }
    else if (h < 3/6) { g = c; b = x; }
    else if (h < 4/6) { g = x; b = c; }
    else if (h < 5/6) { r = x; b = c; }
    else { r = c; b = x; }
    
    r = Math.round((r + m) * 255);
    g = Math.round((g + m) * 255);
    b = Math.round((b + m) * 255);
    
    return (r << 16) | (g << 8) | b;
  }

  _createTextLabelSprite(THREE, text, opts = {}) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      return new THREE.Object3D();
    }

    const fontSize = Number(opts.fontSize) || 34;
    const fontFamily = opts.fontFamily || 'Segoe UI, sans-serif';
    const padX = Number(opts.padX) || 16;
    const padY = Number(opts.padY) || 10;
    const label = String(text || '').slice(0, 80);

    ctx.font = `700 ${fontSize}px ${fontFamily}`;
    const width = Math.max(96, Math.ceil(ctx.measureText(label).width + padX * 2));
    const height = Math.max(44, Math.ceil(fontSize + padY * 2));
    canvas.width = width;
    canvas.height = height;

    ctx.font = `700 ${fontSize}px ${fontFamily}`;
    ctx.fillStyle = opts.bgColor || 'rgba(15,23,42,0.82)';
    ctx.fillRect(0, 0, width, height);
    ctx.fillStyle = opts.textColor || '#f8fafc';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, padX, height / 2);

    const texture = new THREE.CanvasTexture(canvas);
    texture.needsUpdate = true;

    const material = new THREE.SpriteMaterial({
      map: texture,
      transparent: true,
      depthTest: false,
      depthWrite: false
    });
    const sprite = new THREE.Sprite(material);
    const worldWidth = Number(opts.worldWidth) || 0.22;
    const worldHeight = worldWidth * (height / width);
    sprite.scale.set(worldWidth, worldHeight, 1);
    return sprite;
  }

  _startRenderLoop() {
    this._requestRender();
  }

  _requestRender() {
    if (!this.state || !this.state.loaded || !this.state.renderer || !this.state.scene || !this.state.camera) {
      return;
    }
    this.state.renderer.render(this.state.scene, this.state.camera);
  }

  _attachResizeObserver() {
    if (!this.container || !this.state.renderer || !this.state.camera) return;

    if (this.state.resizeObserver) {
      this.state.resizeObserver.disconnect();
    }

    this.state.resizeObserver = new ResizeObserver((entries) => {
      const entry = entries && entries[0];
      if (!entry) return;
      const width = Math.max(1, Math.floor(entry.contentRect.width));
      const height = Math.max(1, Math.floor(entry.contentRect.height));
      this.state.renderer.setSize(width, height, false);
      this.state.camera.aspect = width / height;
      this.state.camera.updateProjectionMatrix();
      this._requestRender();
    });

    this.state.resizeObserver.observe(this.container);
  }

  _invalidateCache() {
    this.state.lastModelSignature = null;
    if (this.state.loaded) this._renderSingleRack();
  }

  _toRowsArray(payload) {
    if (Array.isArray(payload)) return payload.filter(Boolean);
    if (payload && Array.isArray(payload.items)) return payload.items.filter(Boolean);
    if (payload && typeof payload === 'object' && (payload.uuid || payload.location_uuid)) {
      return [payload];
    }
    return [];
  }

  _rowUuid(row) {
    if (!row || typeof row !== 'object') return '';
    return String(row.location_uuid || row.uuid || '').trim();
  }

  _selectRackRowByUuid(rows, requestedUuid) {
    const expected = String(requestedUuid || '').trim();
    if (!Array.isArray(rows) || rows.length === 0) return null;
    if (!expected) return rows[0];
    const exact = rows.find(row => this._rowUuid(row) === expected);
    return exact || rows[0];
  }

  _getRoomFocusedRack() {
    const focusUuid = String(this.state.roomFocusRackUuid || '').trim();
    if (!focusUuid) return null;
    return this.state.liveRacks.find(r => String(r?.uuid || '').trim() === focusUuid) || null;
  }

  _mergeRowsByUuid(rows) {
    const map = new Map();
    for (const row of rows || []) {
      if (!row || typeof row !== 'object') continue;
      const key = String(row.uuid || '').trim();
      if (!key) continue;
      if (!map.has(key)) map.set(key, row);
    }
    return Array.from(map.values());
  }

  async _fetchAllPages(table, params = {}, options = {}) {
    const maxPages = Number(options.maxPages || 500);
    const requestedLimit = Number(params.limit || 500);
    const rows = [];

    let page = 1;
    let totalPages = null;

    while (page <= maxPages) {
      const payload = await this._fetchApi(table, {
        ...params,
        limit: requestedLimit,
        page
      });
      const pageRows = this._toRowsArray(payload);
      rows.push(...pageRows);

      const pageInfo = payload && typeof payload === 'object' ? payload.pageInfo : null;
      if (pageInfo && totalPages === null) {
        const totalResults = Number(pageInfo.totalResults || 0);
        const perPage = Number(pageInfo.resultsPerPage || requestedLimit || 1);
        totalPages = perPage > 0 ? Math.max(1, Math.ceil(totalResults / perPage)) : 1;
      }

      if (!pageInfo && pageRows.length < requestedLimit) {
        break;
      }

      if (totalPages !== null && page >= totalPages) {
        break;
      }

      if (pageRows.length === 0) {
        break;
      }

      page += 1;
    }

    if (this.options.debug) {
      console.log('[3D-DEBUG] _fetchAllPages summary:', {
        table,
        params,
        rows: rows.length,
        pagesFetched: page
      });
    }

    return rows;
  }

  async _fetchByInFilter(table, column, values, params = {}, options = {}) {
    const sanitizedValues = Array.from(new Set((values || [])
      .map(v => String(v || '').trim())
      .filter(Boolean)));

    if (!sanitizedValues.length) {
      return [];
    }

    const plan = this._computeAdaptiveFetchPlan(sanitizedValues.length, options);

    const chunks = [];
    for (let i = 0; i < sanitizedValues.length; i += plan.chunkSize) {
      chunks.push(sanitizedValues.slice(i, i + plan.chunkSize));
    }

    const chunkRows = await this._runChunkedFetch(chunks, plan.concurrency, async (chunk) => {
      return this._fetchAllPages(table, {
        ...params,
        [`${column}In`]: chunk.join(',')
      });
    });

    const allRows = [];
    for (const rows of chunkRows) {
      allRows.push(...(rows || []));
    }

    if (this.options.debug) {
      console.log('[3D-DEBUG] _fetchByInFilter plan:', {
        table,
        column,
        values: sanitizedValues.length,
        chunks: chunks.length,
        chunkSize: plan.chunkSize,
        concurrency: plan.concurrency,
        rows: allRows.length
      });
    }

    return this._mergeRowsByUuid(allRows);
  }

  _computeAdaptiveFetchPlan(totalValues, options = {}) {
    const tuning = {
      ...(this.options.fetchTuning || {}),
      ...(options || {})
    };

    const minChunkSize = Math.max(1, Number(tuning.minChunkSize || 40));
    const maxChunkSize = Math.max(minChunkSize, Number(tuning.maxChunkSize || 300));
    const targetChunks = Math.max(1, Number(tuning.targetChunks || 8));
    const minConcurrency = Math.max(1, Number(tuning.minConcurrency || 1));
    const maxConcurrency = Math.max(minConcurrency, Number(tuning.maxConcurrency || 4));

    const estimatedChunk = Math.ceil(Math.max(1, totalValues) / targetChunks);
    const chunkSize = Math.max(minChunkSize, Math.min(maxChunkSize, estimatedChunk));

    const estimatedChunks = Math.max(1, Math.ceil(totalValues / chunkSize));
    let concurrency = Math.min(maxConcurrency, Math.max(minConcurrency, estimatedChunks));

    if (totalValues < 300) concurrency = Math.min(concurrency, 2);
    if (totalValues < 80) concurrency = 1;

    return { chunkSize, concurrency };
  }

  async _runChunkedFetch(chunks, concurrency, workerFn) {
    const queue = Array.isArray(chunks) ? chunks.slice() : [];
    const workers = Math.max(1, Number(concurrency || 1));
    const results = [];

    const runWorker = async () => {
      while (queue.length > 0) {
        const chunk = queue.shift();
        if (!chunk) continue;
        const rows = await workerFn(chunk);
        results.push(rows || []);
      }
    };

    await Promise.all(Array.from({ length: workers }, () => runWorker()));
    return results;
  }

  async _fetchApi(table, params = {}) {
    // Konstruiere API-URL: ./api/?table=location&...
    const url = new URL(this.options.apiUrl, window.location.href);
    
    // Stelle sicher dass pathname mit / endet
    if (!url.pathname.endsWith('/')) {
      url.pathname += '/';
    }
    
    url.searchParams.set('table', table);
    Object.entries(params).forEach(([k, v]) => {
      if (v) url.searchParams.set(k, v);
    });

    if (this.options.debug) {
      console.log(`[3D-DEBUG] API Request: ${url.toString()}`);
    }
    
    const response = await fetch(url.toString());
    if (!response.ok) {
      throw new Error(`API error: ${response.statusText} (${url.pathname})`);
    }
    const data = await response.json();
    if (this.options.debug) {
      console.log(`[3D-DEBUG] API Response (${table}):`, data);
    }
    return data;
  }

  _normalizeRack(row) {
    if (!row || typeof row !== 'object') {
      throw new Error('Rack-Datensatz ist ungueltig oder leer');
    }
    try {
      const sizeRaw = row.size ?? row.location_size ?? null;
      const geom = this._parseJsonishObject(sizeRaw, {});
      const outer = geom.outer || ((geom.x || geom.y || geom.z) ? {
        x: Number(geom.x) || 600,
        y: Number(geom.y) || 2200,
        z: Number(geom.z) || 1000
      } : { x: 600, y: 2200, z: 1000 });
      const inner = geom.inner || {
        x: Math.max(100, outer.x - 50),
        y: Math.max(200, outer.y - 120),
        z: Math.max(100, outer.z - 80)
      };
      const rawBetween = geom.between || {};
      const between = {
        x_left: Number(rawBetween.x_left ?? 25),
        x_right: Number(rawBetween.x_right ?? 25),
        y_bottom: Number(rawBetween.y_bottom ?? rawBetween.z_bottom ?? 60),
        y_top: Number(rawBetween.y_top ?? rawBetween.z_top ?? 60),
        z_front: Number(rawBetween.z_front ?? rawBetween.y_front ?? 40),
        z_back: Number(rawBetween.z_back ?? rawBetween.y_back ?? 40)
      };
      const limits = geom.limits || {};
      return {
        uuid: row.uuid || row.location_uuid || 'unknown-rack',
        name: row.name || row.location_metadata_caption || row.caption || 'Rack',
        position: this._parseJsonishObject(row.position ?? row.location_position ?? null, { x: 0, y: 0, z: 0 }),
        geometry: {
          outer,
          inner,
          between
        },
        loadLimitKg: row.weight_limit || limits.weightKg || 120,
        powerLimitW: row.power_limit || limits.powerW || 1200,
        thermalLimitW: row.thermal_limit || limits.thermalW || 1100
      };
    } catch (e) {
      console.warn('Failed to normalize rack', row, e);
      return {
        uuid: row?.uuid || 'unknown-rack',
        name: row?.name || 'Rack',
        position: { x: 0, y: 0, z: 0 },
        geometry: { outer: { x: 600, y: 2200, z: 1000 }, inner: { x: 550, y: 2080, z: 920 }, between: { x_left: 25, x_right: 25, y_bottom: 60, y_top: 60, z_front: 40, z_back: 40 } }
      };
    }
  }

  _normalizeDevice(row, ports = []) {
    if (!row || typeof row !== 'object') {
      return null;
    }
    try {
      const pos = this._parseJsonishObject(row.position, {});
      const size = this._parseJsonishObject(row.size, {});
      const rotation = this._parseJsonishObject(row.rotation, {});
      return {
        uuid: row.uuid,
        name: row.name,
        location: row.location || null,
        placement: { x: pos.x || 0, y: pos.y || 0, z: pos.z || 0 },
        rotation: { x: rotation.x || 0, y: rotation.y || 0, z: rotation.z || 0 },
        size: { x: size.x || 445, y: size.y || 44, z: size.z || 300 },
        ports,
        weightKg: row.weight || size.weightKg || size.weight || 5,
        powerW: row.power || 100,
        thermalW: row.thermal || 80,
        color: '#' + (Math.random() * 0xFFFFFF << 0).toString(16).padStart(6, '0')
      };
    } catch (e) {
      console.warn('Failed to normalize device', row, e);
      return {
        uuid: row?.uuid || 'unknown-device',
        name: row?.name || 'Device',
        location: row?.location || null,
        placement: { x: 0, y: 0, z: 0 },
        rotation: { x: 0, y: 0, z: 0 },
        size: { x: 445, y: 44, z: 300 },
        ports,
        weightKg: 5,
        powerW: 100,
        thermalW: 80
      };
    }
  }

  _normalizePort(row) {
    if (!row || typeof row !== 'object') {
      return null;
    }
    try {
      const placement = this._parseJsonishObject(row.position || row.device_port_position, {});
      const size = this._parseJsonishObject(row.size || row.device_port_size, {});
      const typeCode = this._parsePortTypeCode(row.type ?? row.device_port_type);
      const typeMeta = this._portTypeMeta(typeCode);
      return {
        uuid: row.uuid || row.device_port_uuid || `port-${Math.random().toString(36).slice(2, 8)}`,
        name: row.name || row.caption || 'Port',
        type: typeMeta.label,
        typeCode,
        side: row.side || placement.side || 'front',
        color: row.color || typeMeta.color,
        placement: {
          x: Number(placement.x || 20),
          y: Number(placement.y || 10),
          z: Number(placement.z || 2)
        },
        size: {
          x: Number(size.x || 12),
          y: Number(size.y || 12),
          z: Number(size.z || 10)
        }
      };
    } catch (_e) {
      return null;
    }
  }

  _parsePortTypeCode(rawType) {
    const numeric = Number(rawType);
    if (Number.isFinite(numeric)) {
      return Math.round(numeric);
    }

    const text = String(rawType || '').trim().toLowerCase();
    const textMap = {
      'rj45': 10,
      'sfp': 11,
      'sfp+': 11,
      'qsfp': 12,
      'qsfp28': 12,
      'power': 0,
      'mgmt': 20,
      'management': 20,
      'console': 21
    };

    return textMap[text] ?? 99;
  }

  _portTypeMeta(typeCode) {
    const map = {
      0: { label: 'C14 (Power)', color: 0xf59e0b },
      1: { label: 'C20 (Power)', color: 0xd97706 },
      10: { label: 'RJ45', color: 0x93c5fd },
      11: { label: 'SFP / SFP+', color: 0x22d3ee },
      12: { label: 'QSFP', color: 0x14b8a6 },
      13: { label: 'MPO', color: 0x6366f1 },
      20: { label: 'Management (RJ45)', color: 0x84cc16 },
      21: { label: 'Console (RJ45)', color: 0xf97316 },
      30: { label: 'Fiber LC', color: 0x06b6d4 },
      31: { label: 'Fiber SC', color: 0x0891b2 },
      40: { label: 'Coax BNC', color: 0xa855f7 },
      99: { label: 'Other', color: 0x94a3b8 }
    };

    return map[typeCode] || map[99];
  }

  _normalizeConnections(rows) {
    return (rows || [])
      .filter(row => row && typeof row === 'object')
      .map(row => {
        const sourcePortUuid = String(row.device_port_source || row.expected_device_port_source || '').trim();
        const destinationPortUuid = String(row.device_port_destination || row.expected_device_port_destination || '').trim();
        const descriptor = [
          row.type,
          row.caption,
          row.cable_name,
          row.tags,
          row.specification,
          row.description
        ].filter(Boolean).join(' ').toLowerCase();
        const isPower = /power|strom|c13|c14|c19|c20|pdu/.test(descriptor);
        const isFiber = /fiber|glasfaser|lc|sc|sfp|qsfp|mpo|om\d|smf|mmf/.test(descriptor);
        const isTrunk = /trunk|uplink|bundle|backbone/.test(descriptor) || !!row.item_group;
        const cableKind = isPower ? 'power' : (isFiber ? 'fiber' : 'copper');
        const color = isPower ? 0xf59e0b : (isFiber ? 0x22d3ee : 0x60a5fa);

        return {
          uuid: row.uuid || 'unknown-connection',
          sourcePortUuid: sourcePortUuid || null,
          destinationPortUuid: destinationPortUuid || null,
          type: row.type || row.cable_name || 'Copper',
          typeLabel: row.type || row.cable_name || (isPower ? 'Power' : (isFiber ? 'Fiber' : 'Copper')),
          name: row.caption || row.cable_name || '',
          length: Number(row.length) || 1.5,
          speed: Number(row.speed || row.expected_speed) || 0,
          cableKind,
          isTrunk,
          color
        };
      })
      .filter(connection => connection.sourcePortUuid && connection.destinationPortUuid);
  }

  _parseJsonishObject(input, fallback = {}) {
    if (input && typeof input === 'object' && !Array.isArray(input)) {
      return { ...fallback, ...input };
    }

    if (!input || typeof input !== 'string') {
      return { ...fallback };
    }

    const raw = String(input).trim();
    const attempts = [raw];

    const htmlDecoded = raw
      .replace(/&quot;/g, '"')
      .replace(/&#34;/g, '"')
      .replace(/&#39;/g, "'")
      .replace(/&apos;/g, "'")
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>');
    if (htmlDecoded !== raw) {
      attempts.push(htmlDecoded);
    }

    const normalized = htmlDecoded
      .replace(/=>/g, ':')
      .replace(/'/g, '"')
      .replace(/([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)(\s*:)/g, '$1"$2"$3')
      .replace(/,\s*([}\]])/g, '$1');
    if (normalized !== htmlDecoded) {
      attempts.push(normalized);
    }

    for (const candidate of attempts) {
      try {
        const parsed = JSON.parse(candidate);
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
          return { ...fallback, ...parsed };
        }
      } catch (_) {
        // try next
      }
    }

    return { ...fallback };
  }
}
