import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8')
    .replace(/^import .*;\r?\n/gm, '');

// Exercise the actual UI event handlers without a browser or a network service.
function application({ ready = true, audio = true, blockedAudio = false } = {}) {
    class Element {
        constructor(tag = 'div') {
            this.tag = tag;
            this.children = [];
            this.listeners = {};
            this.value = '';
            this.textContent = '';
            this.style = {};
            this.classList = { toggle() {} };
        }
        addEventListener(name, callback) { this.listeners[name] = callback; }
        setAttribute(name, value) { this[name] = value; }
        append(...children) {
            this.children.push(...children.flatMap(child => child.tag === 'fragment' ? child.children : [child]));
        }
        replaceChildren(...children) { this.children = []; this.append(...children); }
        get childElementCount() { return this.children.length; }
        querySelector(tag) {
            for (const child of this.children) {
                if (child.tag === tag) return child;
                const nested = child.querySelector?.(tag);
                if (nested) return nested;
            }
            return null;
        }
        focus() {}
        emit(name, extra = {}) { return this.listeners[name]?.({ preventDefault() {}, target: this, ...extra }); }
    }
    const elements = new Map();
    const element = id => {
        if (!elements.has(id)) elements.set(id, new Element());
        return elements.get(id);
    };
    const metadata = { version: 'test', bounds: [-89.64, 20.96, -89.60, 21.0] };
    const urls = Object.fromEntries(['parks', 'map', 'origin', 'search'].map(name => [name, `http://localhost/${name}`]));
    element('map-config').textContent = JSON.stringify({ metadata: ready ? metadata : null, error: ready ? null : 'No hay datos locales.', urls });
    element('playback-speed').value = '1';
    const radios = ['bfs', 'dfs', 'both'].map(value => Object.assign(new Element(), { value }));
    let method = 'both';
    for (const radio of radios) {
        const emit = radio.emit.bind(radio);
        radio.emit = name => { method = radio.value; return emit(name); };
    }
    element('route-controls').reset = () => { method = 'both'; element('park-query').value = ''; };
    const document = {
        getElementById: element,
        createElement: tag => new Element(tag),
        createDocumentFragment: () => new Element('fragment'),
        querySelector: selector => selector.includes('csrf') ? { content: 'local-csrf' } : { value: method },
        querySelectorAll: () => radios,
    };
    const bounds = {
        getWest: () => -89.64, getSouth: () => 20.96, getEast: () => -89.60, getNorth: () => 21.0,
        contains: () => true, pad() { return this; },
    };
    const map = {
        shapes: [], events: {},
        setView() { return this; }, fitBounds() { return this; }, setMaxBounds() {}, setMinZoom() {},
        getZoom: () => 15, getBounds: () => bounds, getCenter: () => ({ lat: 20.97, lng: -89.62 }),
        on(name, callback) { this.events[name] = callback; },
        latLngToContainerPoint: () => ({ x: 20, y: 20, add() { return this; }, divideBy() { return this; } }),
        containerPointToLatLng: () => ({ lat: 20.97, lng: -89.62 }),
    };
    const layers = [];
    const shape = options => ({
        options, shapes: [],
        addTo(parent) { parent.shapes.push(this); return this; },
        clearLayers() { this.shapes = []; },
    });
    const L = {
        map: () => map,
        layerGroup: () => { const layer = shape(); layers.push(layer); return layer; },
        latLngBounds: () => bounds,
        geoJSON: (data, options = {}) => {
            const layer = shape({ data, ...options });
            layer.setStyle = style => {
                layer.styles = (data.features ?? []).map(feature => style(feature));
                return layer;
            };
            if (typeof options.style === 'function') layer.setStyle(options.style);
            return layer;
        },
        circleMarker: (_point, options) => shape(options),
        marker: (_point, options) => shape(options), divIcon: options => options,
        polyline: (_points, options) => shape(options),
    };
    let timerId = 0;
    const timers = new Map();
    const setTimer = (callback, delay) => { timers.set(++timerId, { callback, delay }); return timerId; };
    const clearTimer = id => timers.delete(id);
    const tones = [];
    const audioContexts = [];
    class AudioContext {
        constructor() {
            if (blockedAudio === 'constructor') throw new Error('Audio unavailable');
            this.currentTime = 0;
            this.state = 'running';
            this.destination = {};
            audioContexts.push(this);
        }
        resume() {
            if (blockedAudio) {
                this.state = 'suspended';
                return Promise.reject(new Error('Audio blocked'));
            }
            return Promise.resolve();
        }
        close() { this.state = 'closed'; return Promise.resolve(); }
        createOscillator() {
            const tone = {
                frequency: { value: 0 }, stops: [],
                connect() {}, disconnect() { this.disconnected = true; },
                start() { tones.push(this); }, stop(time) { this.stops.push(time); },
            };
            return tone;
        }
        createGain() {
            return { gain: { setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {}, disconnect() {} };
        }
    }
    const windowEvents = {};
    const connection = { node_id: 'A', coordinates: [-89.62, 20.97], selected: [-89.62, 20.97], offset_m: 0, approximate: false };
    const result = { status: 'found', route_nodes: ['A', 'B'], route_edges: ['ab'], visited_nodes: ['A', 'B'], trace: [['visit', 'A'], ['edge', 'ab'], ['visit', 'B']], metrics: { visited_nodes: 2, segments: 1, distance_m: 100, search_ms: 0.25 } };
    const searchResult = {
        version: 'test', origin: connection,
        destination: { ...connection, node_id: 'B', coordinates: [-89.619, 20.97], park: { id: 'way/1', name: 'Parque de prueba' } },
        results: { bfs: result, dfs: result }, comparison: { winner: 'tie' },
        network: { nodes: { A: [-89.62, 20.97], B: [-89.619, 20.97] }, edges: { ab: { from: 'A', to: 'B', name: 'Calle de prueba', distance_m: 100, geometry: [[-89.62, 20.97], [-89.619, 20.97]] } } },
    };
    const calls = [];
    const handlers = {
        map: () => ({ version: 'test', boundary: {}, streets: { features: [] }, parks: { features: [] } }),
        parks: () => ({ version: 'test', total: 1, parks: [{ id: 'way/1', name: 'Parque de prueba', coordinates: [-89.619, 20.97], approximate: true }] }),
        origin: () => ({ version: 'test', origin: connection }),
        search: () => searchResult,
    };
    const context = vm.createContext({
        L, document, URL, AbortController, Intl,
        setTimeout: setTimer, clearTimeout: clearTimer,
        window: {
            location: { href: 'http://localhost/' }, matchMedia: () => ({ matches: false }),
            setTimeout: setTimer, addEventListener(name, callback) { windowEvents[name] = callback; },
            AudioContext: audio ? AudioContext : undefined,
        },
        fetch: async (url, options) => {
            assert.equal(url.origin, 'http://localhost');
            const name = url.pathname.slice(1);
            calls.push({ name, options });
            const body = await handlers[name](options);
            return { ok: true, status: 200, json: async () => body };
        },
    });
    vm.runInContext(source, context);
    return {
        element, radios, map, layers, calls, handlers, searchResult, context, timers, tones, audioContexts, windowEvents,
        tick() { const next = timers.entries().next().value; if (next) { timers.delete(next[0]); next[1].callback(); } },
    };
}

const settle = () => new Promise(resolve => setImmediate(resolve));

async function selectExtremes(app) {
    app.map.events.click({ latlng: { lat: 20.97, lng: -89.62 } });
    await settle();
    app.element('park-query').value = 'prueba';
    app.element('park-query').emit('input');
    app.tick();
    await settle();
    app.element('park-results').querySelector('button').emit('click');
}

test('formatting compares and displays the same one-metre precision', () => {
    const app = application({ ready: false });
    assert.equal(vm.runInContext('formatDistance(999.5)', app.context), '1 km');
    assert.equal(vm.runInContext('formatDistance(1001.4)', app.context), '1.001 km');
    assert.equal(app.calls.length, 0);
    assert.equal(app.element('visualize').disabled, true);
});

test('reversible streets keep their map geometry without fixed arrows or graph node markers', async () => {
    const app = application();
    await settle();
    app.map.getZoom = () => 17;
    const streets = {
        features: ['reversible', 0, 1, -1].map((oneway, index) => ({
            properties: { oneway, name: '', from: `${index}:a`, to: `${index}:b` },
            geometry: { type: 'LineString', coordinates: [[-89.62, 20.97], [-89.619, 20.97]] },
        })),
    };
    app.handlers.map = () => ({ version: 'test', boundary: {}, streets, parks: { features: [] } });

    app.map.events.moveend();
    app.tick();
    await settle();

    assert.equal(app.layers[0].shapes[1].options.data, streets);
    const decorations = app.layers[1].shapes;
    const arrows = decorations.filter(shape => shape.options.icon);
    assert.deepEqual(arrows.map(shape => shape.options.icon.html.textContent), ['↔', '→', '→']);
    assert.equal(arrows[2].options.icon.html.style.transform, 'rotate(180deg)');
    assert.equal(decorations.filter(shape => shape.options.radius === 2.5).length, 6);
});

test('local selection, CSRF, steps, method switching and reset use the actual UI handlers', async () => {
    const app = application();
    await settle();
    await selectExtremes(app);
    assert.equal(app.element('visualize').disabled, false);
    await app.element('route-controls').emit('submit');
    assert.equal(app.element('view-switch').hidden, false);
    assert.equal(app.element('bfs-distance').textContent, '100 m');
    assert.equal(app.element('bfs-time').textContent, '0.25 ms');
    assert.equal(app.calls.find(call => call.name === 'search').options.headers['X-CSRF-TOKEN'], 'local-csrf');
    app.element('play-pause').emit('click');
    app.tick();
    assert.match(app.element('playback-counter').textContent, /BFS · Paso 1 de 3/);
    app.element('play-pause').emit('click');
    app.tick();
    assert.match(app.element('playback-counter').textContent, /BFS · Paso 1 de 3/);
    app.element('next-step').emit('click');
    assert.match(app.element('playback-counter').textContent, /BFS · Paso 2 de 3/);
    app.element('view-dfs').emit('click');
    assert.match(app.element('playback-counter').textContent, /DFS · Paso 0 de 3/);
    app.element('next-step').emit('click');
    app.element('view-bfs').emit('click');
    assert.match(app.element('playback-counter').textContent, /BFS · Paso 2 de 3/);
    app.element('finish').emit('click');
    assert.match(app.element('playback-counter').textContent, /Finalizado/);
    assert.equal(app.element('bfs-time').textContent, '0.25 ms');
    app.element('reset').emit('click');
    assert.equal(app.element('visualize').disabled, true);
    assert.equal(app.element('view-switch').hidden, true);
    assert.equal(app.element('bfs-distance').textContent, '—');
});

test('a search response arriving after reset cannot restore obsolete routes', async () => {
    const app = application();
    await settle();
    await selectExtremes(app);
    let complete;
    app.handlers.search = () => new Promise(resolve => { complete = resolve; });
    const pending = app.element('route-controls').emit('submit');
    app.element('reset').emit('click');
    complete(app.searchResult);
    await pending;
    assert.equal(app.element('bfs-distance').textContent, '—');
    assert.equal(app.element('view-switch').hidden, true);
    assert.equal(app.element('visualize').disabled, true);
});

test('DFS shows the parent intersection when exploration resumes after backtracking', async () => {
    const app = application();
    await settle();
    await selectExtremes(app);
    const direct = {
        status: 'found', route_nodes: ['A', 'C'], route_edges: ['ac'], visited_nodes: ['A', 'B', 'C'],
        trace: [['visit', 'A'], ['edge', 'ab'], ['visit', 'B'], ['edge', 'ac'], ['visit', 'C']],
        metrics: { visited_nodes: 3, segments: 1, distance_m: 80, search_ms: 0.25 },
    };
    app.searchResult.results = { bfs: { ...direct, trace: [['visit', 'A'], ['edge', 'ab'], ['edge', 'ac'], ['visit', 'B'], ['visit', 'C']] }, dfs: direct };
    app.searchResult.destination = { ...app.searchResult.destination, node_id: 'C', coordinates: [-89.62, 20.971], selected: [-89.62, 20.971] };
    app.searchResult.network.nodes.C = [-89.62, 20.971];
    app.searchResult.network.edges.ac = { from: 'A', to: 'C', distance_m: 80, name: 'Otra calle', geometry: [[-89.62, 20.97], [-89.62, 20.971]] };
    await app.element('route-controls').emit('submit');
    app.element('view-dfs').emit('click');
    for (let i = 0; i < 3; i++) app.element('next-step').emit('click');
    assert.match(app.element('current-node').textContent, /Nodo actual: B/);
    app.element('next-step').emit('click');
    assert.match(app.element('current-node').textContent, /Nodo actual: A/);
    assert.match(app.element('current-node').textContent, /2 visitas reproducidas/);
});

test('the selected park changes colour, arrival follows the active trace, and reset clears the highlight', async () => {
    const app = application();
    await settle();
    const parks = { features: [
        { id: 'way/1', properties: { name: 'Parque de prueba' }, geometry: { type: 'Polygon' } },
        { id: 'node/2', properties: { name: 'Otro parque' }, geometry: { type: 'Point' } },
    ] };
    app.handlers.map = () => ({ version: 'test', boundary: {}, streets: { features: [] }, parks });
    app.map.events.moveend();
    app.tick();
    await settle();
    const parkLayer = app.layers[0].shapes[2];
    assert.equal(parkLayer.styles[0].color, '#8fb473');

    await selectExtremes(app);
    assert.equal(parkLayer.styles[0].color, '#7c3aed');
    assert.equal(parkLayer.styles[1].color, '#8fb473');
    const point = parkLayer.options.pointToLayer({ ...parks.features[1], id: 'way/1' }, [20.97, -89.62]);
    assert.equal(point.options.color, '#7c3aed');
    assert.equal(point.options.radius, 9);
    await app.element('route-controls').emit('submit');
    app.element('finish').emit('click');
    assert.equal(parkLayer.styles[0].color, '#b45309');
    app.element('view-dfs').emit('click');
    assert.equal(parkLayer.styles[0].color, '#7c3aed');
    for (let index = 0; index < 3; index++) app.element('next-step').emit('click');
    assert.equal(parkLayer.styles[0].color, '#b45309');
    app.element('park-query').emit('input');
    assert.equal(parkLayer.styles[0].color, '#8fb473');
    app.element('reset').emit('click');
    assert.equal(parkLayer.styles[0].color, '#8fb473');
});

test('an unreachable park never gets the arrival colour or arrival sound', async () => {
    const app = application();
    await settle();
    await selectExtremes(app);
    app.searchResult.results = { bfs: {
        status: 'unreachable', route_nodes: [], route_edges: [], visited_nodes: ['A'], trace: [['visit', 'A']],
        metrics: { visited_nodes: 1, segments: null, distance_m: null, search_ms: 0.25 },
    } };
    app.handlers.map = () => ({ version: 'test', boundary: {}, streets: { features: [] }, parks: { features: [{ id: 'way/1', properties: {} }] } });
    app.map.events.moveend();
    app.tick();
    await settle();

    await app.element('route-controls').emit('submit');
    app.element('next-step').emit('click');

    assert.equal(app.layers[0].shapes[2].styles[0].color, '#7c3aed');
    assert.equal(app.tones.length, 1);
    assert.equal(app.tones[0].frequency.value, 520);
    assert.match(app.element('bfs-status').textContent, /inalcanzable/);
});

test('changing speed reschedules only active playback and leaves search metrics unchanged', async () => {
    const app = application();
    await settle();
    await selectExtremes(app);
    await app.element('route-controls').emit('submit');
    const speed = app.element('playback-speed');
    speed.value = '0.5';
    speed.emit('change');
    assert.equal(app.timers.size, 0);

    app.element('play-pause').emit('click');
    assert.equal([...app.timers.values()][0].delay, 360);
    speed.value = '4';
    speed.emit('change');
    assert.equal(app.timers.size, 1);
    assert.equal([...app.timers.values()][0].delay, 45);
    app.tick();
    assert.match(app.element('playback-counter').textContent, /Paso 1 de 3/);
    app.element('play-pause').emit('click');
    speed.value = '2';
    speed.emit('change');
    assert.equal(app.timers.size, 0);
    app.element('play-pause').emit('click');
    assert.equal([...app.timers.values()][0].delay, 90);
    assert.equal(app.element('bfs-time').textContent, '0.25 ms');
    assert.equal(app.element('bfs-distance').textContent, '100 m');
    app.element('reset').emit('click');
    assert.equal(app.timers.size, 0);
});

test('sound starts only on interaction, follows real steps, can be muted, and stops on pause or reset', async () => {
    const app = application();
    await settle();
    await selectExtremes(app);
    await app.element('route-controls').emit('submit');
    assert.equal(app.audioContexts.length, 0);

    app.element('play-pause').emit('click');
    app.tick();
    assert.equal(app.tones.length, 1);
    assert.equal(app.tones[0].frequency.value, 520);
    app.element('play-pause').emit('click');
    assert.equal(app.tones[0].stops.at(-1), undefined);
    app.tones[0].onended();
    assert.equal(app.tones[0].disconnected, true);
    app.element('sound-toggle').emit('click');
    assert.equal(app.element('sound-toggle')['aria-pressed'], 'false');
    app.element('next-step').emit('click');
    assert.equal(app.tones.length, 1);
    app.element('sound-toggle').emit('click');
    app.element('next-step').emit('click');
    assert.equal(app.tones[1].frequency.value, 880);
    app.element('view-dfs').emit('click');
    assert.equal(app.tones.length, 2);
    app.element('next-step').emit('click');
    assert.equal(app.tones[2].frequency.value, 390);
    app.element('finish').emit('click');
    assert.equal(app.tones.length, 3);
    app.element('reset').emit('click');
    assert.equal(app.tones[2].stops.at(-1), undefined);
    app.windowEvents.pagehide();
    assert.equal(app.audioContexts[0].state, 'closed');
});

test('animation still works when audio is unsupported or the browser rejects it', async () => {
    for (const options of [{ audio: false }, { blockedAudio: true }, { blockedAudio: 'constructor' }]) {
        const app = application(options);
        await settle();
        await selectExtremes(app);
        await app.element('route-controls').emit('submit');

        app.element('play-pause').emit('click');
        await settle();
        app.tick();

        assert.match(app.element('playback-counter').textContent, /Paso 1 de 3/);
        assert.equal(app.tones.length, 0);
        assert.equal(app.element('sound-toggle')['aria-pressed'], 'false');
    }
});
