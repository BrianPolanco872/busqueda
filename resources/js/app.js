import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const configElement = document.getElementById('map-config');

if (configElement) {
    initializeMap(JSON.parse(configElement.textContent));
}

function initializeMap(config) {
    const element = (id) => document.getElementById(id);
    const colors = { bfs: '#246bb2', dfs: '#b85a20' };
    const requests = {};
    const state = {
        available: Boolean(config.metadata && !config.error),
        origin: null,
        destination: null,
        response: null,
        playback: {},
        active: 'bfs',
        picking: false,
        busy: false,
        timer: null,
        parkTimer: null,
        mapTimer: null,
    };
    const map = L.map('map', {
        preferCanvas: true,
        attributionControl: false,
        zoomControl: true,
        maxZoom: 20,
        zoomAnimation: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    }).setView([20.9674, -89.5926], 13);
    const baseLayer = L.layerGroup().addTo(map);
    const decorationLayer = L.layerGroup().addTo(map);
    const explorationLayer = L.layerGroup().addTo(map);
    const finalRouteLayer = L.layerGroup().addTo(map);
    const visitLayer = L.layerGroup().addTo(map);
    const endpointLayer = L.layerGroup().addTo(map);
    let visibleData = null;

    function message(text, error = false) {
        element('app-status').textContent = text;
        element('app-status').classList.toggle('is-error', error);
    }

    function beginRequest(name) {
        requests[name]?.controller.abort();
        const request = {
            generation: (requests[name]?.generation ?? 0) + 1,
            controller: new AbortController(),
        };
        requests[name] = request;
        return request;
    }

    function cancelRequest(name) {
        const request = beginRequest(name);
        request.controller.abort();
    }

    function isCurrent(name, request) {
        return requests[name]?.generation === request.generation && !request.controller.signal.aborted;
    }

    async function fetchLocal(name, request, parameters = {}, post = false) {
        const url = new URL(config.urls[name], window.location.href);
        const payload = { ...parameters, version: config.metadata.version };
        const options = {
            signal: request.controller.signal,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        };
        if (post) {
            options.method = 'POST';
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').content;
            options.body = JSON.stringify(payload);
        } else {
            for (const [key, value] of Object.entries(payload)) {
                url.searchParams.set(key, value);
            }
        }
        const response = await fetch(url, options);
        const data = await response.json().catch(() => null);
        if (!response.ok) {
            const messages = {
                409: 'Los datos locales cambiaron. Recarga la página para usar la nueva versión.',
                419: 'La sesión caducó. Recarga la página antes de continuar.',
                422: 'Revisa la selección: el punto o el parque no tiene una conexión válida.',
                503: 'Los datos locales no están disponibles o no son válidos. Importa un conjunto validado y recarga.',
            };
            const validation = data?.errors ? Object.values(data.errors).flat().join(' ') : null;
            const error = new Error(
                response.status === 422
                    ? validation || data?.message || messages[422]
                    : messages[response.status] || data?.message || 'No se pudo completar la solicitud local.',
            );
            error.status = response.status;
            throw error;
        }
        if (!data || data.version !== config.metadata.version) {
            const error = new Error('La versión recibida no coincide. Recarga la página para continuar.');
            error.status = 409;
            throw error;
        }
        return data;
    }

    function handleError(error) {
        if (error.name === 'AbortError') {
            return;
        }
        const text = error instanceof TypeError
            ? 'No se pudo contactar con el servidor local. Comprueba que Laravel siga en ejecución.'
            : error.message;
        if ([409, 503].includes(error.status)) {
            state.available = false;
            clearTimeout(state.parkTimer);
            clearTimeout(state.mapTimer);
            invalidateResults();
            for (const name of Object.keys(requests)) {
                cancelRequest(name);
            }
            element('route-fields').disabled = true;
            state.origin = null;
            state.destination = null;
            baseLayer.clearLayers();
            decorationLayer.clearLayers();
            endpointLayer.clearLayers();
            visibleData = null;
            element('map').setAttribute('aria-busy', 'false');
            setPicking(false);
            element('map-empty-message').textContent = text;
            element('map-empty').hidden = false;
        }
        message(text, true);
        updateControls();
    }

    function stopPlayback() {
        clearTimeout(state.timer);
        state.timer = null;
    }

    function invalidateResults() {
        stopPlayback();
        cancelRequest('search');
        state.response = null;
        state.playback = {};
        state.busy = false;
        explorationLayer.clearLayers();
        finalRouteLayer.clearLayers();
        visitLayer.clearLayers();
        drawEndpoints();
        if (state.destination) {
            element('destination-summary').textContent = `${state.destination.name}. La conexión se resolverá al buscar.`;
        }
        renderResults();
        updatePlayback();
        updateControls();
    }

    function updateControls() {
        element('visualize').disabled = !state.available || !state.origin || !state.destination || state.busy;
        element('visualize').textContent = state.busy ? 'Buscando recorrido…' : '▶  Visualizar búsqueda';
        element('route-controls').setAttribute('aria-busy', String(state.busy));
    }

    function setPicking(picking) {
        state.picking = picking && state.available;
        element('pick-origin').setAttribute('aria-pressed', String(state.picking));
        element('map').classList.toggle('is-picking', state.picking);
        element('map-crosshair').hidden = !state.picking;
        element('select-center').hidden = !state.picking;
        element('map-instructions').textContent = state.picking
            ? 'Haz clic para elegir el origen. Con teclado: mueve el mapa con las flechas y pulsa Enter o «Elegir centro».'
            : 'Arrastra para moverte. Acércate para ver nodos y sentidos. Selecciona un parque verde como destino.';
    }

    function pointMarker(coordinates, letter, destination = false) {
        const content = document.createElement('span');
        content.textContent = letter;
        return L.marker(toLatLng(coordinates), {
            icon: L.divIcon({
                className: `map-point${destination ? ' destination' : ''}`,
                html: content,
                iconSize: [26, 26],
                iconAnchor: [13, 26],
            }),
            keyboard: true,
            title: destination ? 'B · Parque de destino' : 'A · Punto de origen',
            alt: destination ? 'Parque de destino' : 'Punto de origen',
            zIndexOffset: 1000,
        });
    }

    function drawConnection(connection, letter, destination = false) {
        const selected = connection.selected ?? connection.coordinates;
        pointMarker(selected, letter, destination).addTo(endpointLayer);
        L.circleMarker(toLatLng(connection.coordinates), {
            radius: 5, color: '#405845', weight: 2, fillColor: '#fff', fillOpacity: 1, interactive: false,
        }).addTo(endpointLayer);
        L.polyline([toLatLng(selected), toLatLng(connection.coordinates)], {
            color: '#6b7b65', weight: 2, dashArray: '3 5', interactive: false,
        }).addTo(endpointLayer);
    }

    function drawEndpoints() {
        endpointLayer.clearLayers();
        if (state.origin) {
            drawConnection(state.origin, 'A');
        }
        if (state.response?.destination) {
            drawConnection(state.response.destination, 'B', true);
        } else if (state.destination?.coordinates) {
            pointMarker(state.destination.coordinates, 'B', true).addTo(endpointLayer);
        }
    }

    function connectionSummary(connection) {
        return `Nodo ${connection.node_id} · ajuste ${formatDistance(connection.offset_m)}${connection.approximate ? ' (aproximado)' : ''}. El ajuste no cuenta como calle.`;
    }

    async function chooseOrigin(latlng) {
        if (!state.available) {
            return;
        }
        invalidateResults();
        state.origin = null;
        drawEndpoints();
        const request = beginRequest('origin');
        element('origin-summary').textContent = 'Validando el punto y su conexión a la red…';
        message('Resolviendo el origen con los datos locales…');
        updateControls();
        try {
            const data = await fetchLocal('origin', request, { latitude: latlng.lat, longitude: latlng.lng }, true);
            if (!isCurrent('origin', request)) {
                return;
            }
            state.origin = data.origin;
            element('origin-summary').textContent = `${latlng.lat.toFixed(5)}, ${latlng.lng.toFixed(5)} · ${connectionSummary(data.origin)}`;
            drawEndpoints();
            setPicking(false);
            message(state.destination ? 'Todo listo. Visualiza el recorrido.' : 'Origen conectado. Ahora selecciona un parque.');
        } catch (error) {
            if (isCurrent('origin', request)) {
                element('origin-summary').textContent = 'El punto no se pudo conectar. Elige otro origen.';
                handleError(error);
            }
        } finally {
            if (isCurrent('origin', request)) {
                updateControls();
            }
        }
    }

    function choosePark(park) {
        if (!state.available) {
            return;
        }
        clearTimeout(state.parkTimer);
        cancelRequest('parks');
        invalidateResults();
        state.destination = park;
        element('park-query').value = park.name;
        element('park-results').replaceChildren();
        element('park-count').textContent = 'Parque seleccionado. Puedes buscar otro o elegirlo en el mapa.';
        element('destination-summary').textContent = `${park.name} · ${park.coordinates[1].toFixed(5)}, ${park.coordinates[0].toFixed(5)}${park.approximate ? ' · ubicación aproximada' : ''}. La conexión se resolverá al buscar.`;
        setPicking(false);
        drawEndpoints();
        updateControls();
        message(state.origin ? 'Todo listo. Visualiza el recorrido.' : 'Parque seleccionado. Falta elegir el punto de origen.');
    }

    async function loadParks(query) {
        if (!state.available) {
            return;
        }
        const request = beginRequest('parks');
        element('park-count').textContent = 'Buscando en el catálogo local…';
        try {
            const data = await fetchLocal('parks', request, { q: query });
            if (!isCurrent('parks', request) || element('park-query').value.trim() !== query) {
                return;
            }
            const items = data.parks.map((park) => {
                const item = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                const name = document.createElement('span');
                name.textContent = park.name;
                const location = document.createElement('small');
                location.textContent = `${park.coordinates[1].toFixed(5)}, ${park.coordinates[0].toFixed(5)}${park.approximate ? ' · aproximado' : ''}`;
                button.append(name, location);
                button.addEventListener('click', () => choosePark(park));
                item.append(button);
                return item;
            });
            element('park-results').replaceChildren(...items);
            element('park-count').textContent = data.total > data.parks.length
                ? `Se muestran ${data.parks.length} de ${data.total} parques. Afina el nombre o selecciona uno visible en el mapa.`
                : data.total === 0 ? 'No se encontraron parques con ese nombre.' : `${data.total} parque${data.total === 1 ? '' : 's'} encontrado${data.total === 1 ? '' : 's'}.`;
        } catch (error) {
            if (isCurrent('parks', request)) {
                element('park-count').textContent = 'No se pudo consultar el catálogo local.';
                handleError(error);
            }
        }
    }

    async function loadMap() {
        if (!state.available) {
            return;
        }
        const request = beginRequest('map');
        const bounds = map.getBounds();
        element('map').setAttribute('aria-busy', 'true');
        element('map-load-status').textContent = 'Cargando cartografía local…';
        try {
            const data = await fetchLocal('map', request, {
                west: bounds.getWest(), south: bounds.getSouth(), east: bounds.getEast(), north: bounds.getNorth(), zoom: map.getZoom(),
            });
            if (!isCurrent('map', request)) {
                return;
            }
            visibleData = data;
            drawBaseMap();
            element('map-load-status').textContent = '';
        } catch (error) {
            if (isCurrent('map', request)) {
                element('map-load-status').textContent = 'No se pudo cargar el área visible.';
                handleError(error);
            }
        } finally {
            if (isCurrent('map', request)) {
                element('map').setAttribute('aria-busy', 'false');
            }
        }
    }

    function drawBaseMap() {
        baseLayer.clearLayers();
        decorationLayer.clearLayers();
        if (!visibleData) {
            return;
        }
        L.geoJSON(visibleData.boundary, {
            style: { color: '#9cac8d', weight: 1.5, dashArray: '5 5', fill: false, interactive: false },
        }).addTo(baseLayer);
        L.geoJSON(visibleData.streets, {
            style: { color: '#bec5b8', weight: map.getZoom() >= 16 ? 3 : 1.5, opacity: .85, interactive: false },
        }).addTo(baseLayer);
        let parkLabels = 0;
        L.geoJSON(visibleData.parks, {
            style: { color: '#8fb473', weight: 1.5, fillColor: '#d6e7c1', fillOpacity: .85, bubblingMouseEvents: false },
            pointToLayer: (feature, latlng) => L.circleMarker(latlng, {
                radius: 6, color: '#759f5b', weight: 1.5, fillColor: '#c8dfa8', fillOpacity: 1, bubblingMouseEvents: false,
            }),
            onEachFeature: (feature, layer) => {
                const name = document.createElement('span');
                name.textContent = feature.properties.name;
                layer.bindTooltip(name, { direction: 'top', permanent: map.getZoom() >= 16 && parkLabels++ < 30 });
                layer.on('click', (event) => {
                    if (event.originalEvent) {
                        L.DomEvent.stopPropagation(event.originalEvent);
                    }
                    if (state.picking) {
                        chooseOrigin(event.latlng);
                        return;
                    }
                    choosePark({
                        id: feature.properties.id ?? feature.id,
                        name: feature.properties.name,
                        coordinates: feature.properties.connection_point,
                        approximate: feature.properties.approximate,
                    });
                });
            },
        }).addTo(baseLayer);
        drawStreetDetails();
    }

    function drawStreetDetails() {
        const zoom = map.getZoom();
        if (zoom < 16 || !visibleData) {
            return;
        }
        const bounds = map.getBounds();
        const nodes = new Set();
        const names = new Set();
        let arrows = 0;
        let labels = 0;
        for (const feature of visibleData.streets.features) {
            const coordinates = feature.geometry.coordinates;
            const middle = Math.floor((coordinates.length - 1) / 2);
            const start = map.latLngToContainerPoint(toLatLng(coordinates[middle]));
            const end = map.latLngToContainerPoint(toLatLng(coordinates[middle + 1]));
            const center = map.containerPointToLatLng(start.add(end).divideBy(2));
            if (bounds.contains(center) && arrows < 100) {
                const arrow = document.createElement('span');
                arrow.textContent = feature.properties.oneway === 0 ? '↔' : '→';
                const angle = Math.atan2(end.y - start.y, end.x - start.x) * 180 / Math.PI;
                arrow.style.transform = `rotate(${angle + (feature.properties.oneway === -1 ? 180 : 0)}deg)`;
                L.marker(center, {
                    icon: L.divIcon({ className: 'direction-icon', html: arrow, iconSize: [16, 16] }), interactive: false, keyboard: false,
                }).addTo(decorationLayer);
                arrows++;
            }
            const name = feature.properties.name;
            if (name && !names.has(name) && bounds.contains(center) && labels < 35) {
                const label = document.createElement('span');
                label.textContent = name;
                L.tooltip({ permanent: true, direction: 'center', className: 'street-label' })
                    .setLatLng(center).setContent(label).addTo(decorationLayer);
                names.add(name);
                labels++;
            }
            if (zoom < 17) {
                continue;
            }
            for (const [id, coordinate] of [[feature.properties.from, coordinates[0]], [feature.properties.to, coordinates.at(-1)]]) {
                if (nodes.size >= 220 || nodes.has(id) || !bounds.contains(toLatLng(coordinate))) {
                    continue;
                }
                nodes.add(id);
                L.circleMarker(toLatLng(coordinate), {
                    radius: 2.5, color: '#9ca795', weight: 1, fillColor: '#fff', fillOpacity: 1, interactive: false,
                }).addTo(decorationLayer);
            }
        }
    }

    async function search(event) {
        event.preventDefault();
        if (!state.available || !state.origin || !state.destination || state.busy) {
            return;
        }
        invalidateResults();
        const request = beginRequest('search');
        state.busy = true;
        updateControls();
        message('Buscando sobre la red municipal completa…');
        try {
            const data = await fetchLocal('search', request, {
                latitude: state.origin.selected[1],
                longitude: state.origin.selected[0],
                park_id: state.destination.id,
                method: document.querySelector('input[name="method"]:checked').value,
            }, true);
            if (!isCurrent('search', request)) {
                return;
            }
            state.response = data;
            state.origin = data.origin;
            state.active = data.results.bfs ? 'bfs' : 'dfs';
            for (const method of ['bfs', 'dfs']) {
                if (data.results[method]) {
                    state.playback[method] = { cursor: 0, visits: [], current: null };
                }
            }
            element('origin-summary').textContent = connectionSummary(data.origin);
            element('destination-summary').textContent = `${data.destination.park.name} · ${connectionSummary(data.destination)}`;
            renderResults();
            drawEndpoints();
            redrawPlayback();
            const endpoints = L.latLngBounds(toLatLng(data.origin.coordinates), toLatLng(data.destination.coordinates));
            map.fitBounds(endpoints.pad(.25), { maxZoom: 16, animate: false });
            message('Búsqueda terminada. La reproducción muestra la traza real; las métricas ya son definitivas.');
            updatePlayback();
        } catch (error) {
            if (isCurrent('search', request)) {
                handleError(error);
            }
        } finally {
            if (isCurrent('search', request)) {
                state.busy = false;
                updateControls();
            }
        }
    }

    function renderResults() {
        const results = state.response?.results;
        const winner = state.response?.comparison?.winner;
        for (const method of ['bfs', 'dfs']) {
            const result = results?.[method];
            const found = result?.status === 'found';
            element(`${method}-status`).textContent = !result
                ? results ? 'Método no ejecutado en esta búsqueda.' : 'Ejecuta una búsqueda para ver el resultado.'
                : found ? 'Ruta encontrada · métricas definitivas' : 'Destino inalcanzable desde este origen en la red dirigida.';
            element(`${method}-distance`).textContent = found ? formatDistance(result.metrics.distance_m) : '—';
            element(`${method}-segments`).textContent = found ? formatNumber(result.metrics.segments) : '—';
            element(`${method}-visited`).textContent = result ? formatNumber(result.metrics.visited_nodes) : '—';
            element(`${method}-time`).textContent = result ? `${formatNumber(result.metrics.search_ms, 3)} ms` : '—';
            const badge = element(`${method}-badge`);
            badge.hidden = !found || (winner !== method && winner !== 'tie');
            badge.textContent = winner === 'tie' ? 'Empate en distancia' : 'Más corta de las dos encontradas';
            const details = element(`${method}-details`);
            details.hidden = !found;
            details.open = false;
            element(`${method}-streets`).replaceChildren();
            if (found) {
                element(`${method}-route-summary`).textContent = `${result.route_nodes.length} nodos en la ruta · ${result.route_edges.length} tramos dirigidos. ${result.route_edges.length === 0 ? 'El origen y el destino coinciden en el mismo nodo.' : 'En el orden del recorrido:'}`;
                details.ontoggle = () => {
                    if (details.open && !element(`${method}-streets`).childElementCount) {
                        renderStreets(method);
                    }
                };
            } else {
                details.ontoggle = null;
            }
        }
        let comparison = 'Las métricas aparecerán al ejecutar la búsqueda.';
        if (results) {
            comparison = winner === 'tie'
                ? 'Empate: ambas rutas tienen la misma distancia redondeada a 1 m.'
                : ['bfs', 'dfs'].includes(winner)
                    ? `${winner.toUpperCase()}: más corta de las dos encontradas. No implica la menor distancia posible.`
                    : results.bfs && results.dfs
                        ? 'No hay dos rutas encontradas para comparar sus distancias.'
                        : 'Ejecuta «Comparar ambos» para contrastar las distancias.';
        }
        element('comparison').textContent = comparison;
    }

    function renderStreets(method) {
        const result = state.response.results[method];
        const fragment = document.createDocumentFragment();
        for (const id of result.route_edges) {
            const edge = state.response.network.edges[id];
            const item = document.createElement('li');
            item.textContent = `${edge.name || 'Calle sin nombre'} · ${formatDistance(edge.distance_m)} · ${edge.from} → ${edge.to}`;
            fragment.append(item);
        }
        element(`${method}-streets`).append(fragment);
    }

    function drawEdge(id, layer, final = false) {
        const edge = state.response.network.edges[id];
        if (!edge) {
            return;
        }
        L.polyline(edge.geometry.map(toLatLng), {
            color: colors[state.active],
            weight: final ? 5 : 2,
            opacity: final ? .95 : .23,
            dashArray: state.active === 'dfs' ? final ? '10 5' : '3 5' : final ? null : '2 4',
            interactive: false,
        }).addTo(layer);
    }

    function applyTraceEvent(event, playback) {
        const [kind, id] = event;
        if (kind === 'visit') {
            playback.visits.push(id);
            playback.current = id;
            const coordinate = state.response.network.nodes[id];
            if (coordinate) {
                L.circleMarker(toLatLng(coordinate), {
                    radius: 3, color: colors[state.active], weight: 1, fillOpacity: .35, interactive: false,
                }).addTo(explorationLayer);
            }
        } else if (kind === 'edge') {
            playback.current = state.response.network.edges[id]?.from ?? playback.current;
            drawEdge(id, explorationLayer);
        }
    }

    function drawFinalRoute() {
        finalRouteLayer.clearLayers();
        const result = state.response?.results[state.active];
        const playback = state.playback[state.active];
        if (result?.status === 'found' && playback.cursor === result.trace.length) {
            for (const id of result.route_edges) {
                drawEdge(id, finalRouteLayer, true);
            }
        }
    }

    function drawVisitNumbers() {
        visitLayer.clearLayers();
        const playback = state.playback[state.active];
        if (!playback || !state.response) {
            return;
        }
        const bounds = map.getBounds();
        const cells = new Set();
        let count = 0;
        // shortcut: labels are limited to 100 visible, non-overlapping visits; zoom in to inspect dense areas.
        for (let index = playback.visits.length - 1; index >= 0 && count < 100; index--) {
            const id = playback.visits[index];
            const coordinate = state.response.network.nodes[id];
            if (!coordinate || !bounds.contains(toLatLng(coordinate))) {
                continue;
            }
            const point = map.latLngToContainerPoint(toLatLng(coordinate));
            const cell = `${Math.floor(point.x / 35)},${Math.floor(point.y / 28)}`;
            if (cells.has(cell)) {
                continue;
            }
            cells.add(cell);
            const content = document.createElement('span');
            content.textContent = index + 1;
            L.marker(toLatLng(coordinate), {
                icon: L.divIcon({ className: `visit-number ${state.active}`, html: content, iconSize: [25, 21] }),
                interactive: false, keyboard: false,
            }).addTo(visitLayer);
            count++;
        }
    }

    function redrawPlayback() {
        explorationLayer.clearLayers();
        finalRouteLayer.clearLayers();
        visitLayer.clearLayers();
        const result = state.response?.results[state.active];
        const playback = state.playback[state.active];
        if (!result || !playback) {
            return;
        }
        const cursor = playback.cursor;
        playback.visits = [];
        playback.current = null;
        for (let index = 0; index < cursor; index++) {
            applyTraceEvent(result.trace[index], playback);
        }
        drawFinalRoute();
        drawVisitNumbers();
    }

    function updatePlayback() {
        element('view-switch').hidden = !(state.response?.results.bfs && state.response?.results.dfs);
        const result = state.response?.results[state.active];
        const playback = state.playback[state.active];
        const done = !result || playback.cursor >= result.trace.length;
        for (const method of ['bfs', 'dfs']) {
            element(`view-${method}`).disabled = !state.response?.results[method];
            element(`view-${method}`).setAttribute('aria-pressed', String(state.active === method));
        }
        element('play-pause').disabled = done;
        element('next-step').disabled = done;
        element('finish').disabled = done;
        element('play-pause').textContent = state.timer ? 'Ⅱ Pausar' : playback?.cursor ? '▶ Reanudar' : '▶ Reproducir';
        element('playback-counter').textContent = result
            ? `${state.active.toUpperCase()} · Paso ${formatNumber(playback.cursor)} de ${formatNumber(result.trace.length)}${done ? ' · Finalizado' : ''}`
            : 'Sin recorrido · Paso 0 de 0';
        element('current-node').textContent = `Nodo actual: ${playback?.current ?? '—'}${playback ? ` · ${formatNumber(playback.visits.length)} visitas reproducidas` : ''}`;
    }

    function advance() {
        const result = state.response?.results[state.active];
        const playback = state.playback[state.active];
        if (!result || playback.cursor >= result.trace.length) {
            stopPlayback();
            updatePlayback();
            return;
        }
        applyTraceEvent(result.trace[playback.cursor], playback);
        playback.cursor++;
        if (playback.cursor === result.trace.length) {
            stopPlayback();
            drawFinalRoute();
        }
        drawVisitNumbers();
        updatePlayback();
    }

    function play() {
        const result = state.response?.results[state.active];
        const playback = state.playback[state.active];
        if (!result || playback.cursor >= result.trace.length) {
            return;
        }
        state.timer = window.setTimeout(() => {
            state.timer = null;
            advance();
            play();
        }, 180);
        updatePlayback();
    }

    element('pick-origin').addEventListener('click', () => {
        setPicking(!state.picking);
        if (state.picking) {
            stopPlayback();
            updatePlayback();
            message('Elige el origen con un clic o mueve el mapa con las flechas y pulsa Enter.');
            element('map').focus();
        }
    });
    element('select-center').addEventListener('click', () => chooseOrigin(map.getCenter()));
    element('map').addEventListener('keydown', (event) => {
        if (event.target === element('map') && event.key === 'Enter' && state.picking) {
            event.preventDefault();
            chooseOrigin(map.getCenter());
        }
        if (event.key === 'Escape' && state.picking) {
            setPicking(false);
            element('pick-origin').focus();
        }
    });
    map.on('click', (event) => {
        if (state.picking || !state.origin) {
            chooseOrigin(event.latlng);
        }
    });
    map.on('movestart', () => {
        cancelRequest('map');
        clearTimeout(state.mapTimer);
    });
    map.on('moveend', () => {
        drawVisitNumbers();
        clearTimeout(state.mapTimer);
        state.mapTimer = window.setTimeout(loadMap, 140);
    });
    element('park-query').addEventListener('input', () => {
        clearTimeout(state.parkTimer);
        cancelRequest('parks');
        invalidateResults();
        state.destination = null;
        element('destination-summary').textContent = 'Ningún parque seleccionado.';
        element('park-results').replaceChildren();
        element('park-count').textContent = 'Buscando en el catálogo local…';
        drawEndpoints();
        updateControls();
        const query = element('park-query').value.trim();
        state.parkTimer = window.setTimeout(() => loadParks(query), 220);
    });
    element('park-query').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            element('park-results').querySelector('button')?.focus();
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            element('park-results').querySelector('button')?.focus();
        }
    });
    document.querySelectorAll('input[name="method"]').forEach((input) => {
        input.addEventListener('change', () => {
            invalidateResults();
            drawEndpoints();
            if (state.destination) {
                element('destination-summary').textContent = `${state.destination.name}. La conexión se resolverá al buscar.`;
            }
            message('Método actualizado. Vuelve a visualizar el recorrido.');
        });
    });
    element('route-controls').addEventListener('submit', search);
    element('reset').addEventListener('click', () => {
        clearTimeout(state.parkTimer);
        cancelRequest('parks');
        cancelRequest('origin');
        invalidateResults();
        state.origin = null;
        state.destination = null;
        state.active = 'bfs';
        element('route-controls').reset();
        element('park-results').replaceChildren();
        element('park-count').textContent = 'Busca por nombre o elige un parque en el mapa.';
        element('origin-summary').textContent = 'Ningún punto seleccionado.';
        element('destination-summary').textContent = 'Ningún parque seleccionado.';
        setPicking(false);
        drawEndpoints();
        updateControls();
        updatePlayback();
        message('Recorrido reiniciado. Los datos locales se conservan.');
    });
    for (const method of ['bfs', 'dfs']) {
        element(`view-${method}`).addEventListener('click', () => {
            if (!state.response?.results[method]) {
                return;
            }
            stopPlayback();
            state.active = method;
            redrawPlayback();
            updatePlayback();
        });
    }
    element('play-pause').addEventListener('click', () => {
        if (state.timer) {
            stopPlayback();
            updatePlayback();
        } else {
            play();
        }
    });
    element('next-step').addEventListener('click', () => {
        stopPlayback();
        advance();
    });
    element('finish').addEventListener('click', () => {
        stopPlayback();
        state.playback[state.active].cursor = state.response.results[state.active].trace.length;
        redrawPlayback();
        updatePlayback();
    });
    window.addEventListener('pagehide', () => {
        stopPlayback();
        clearTimeout(state.parkTimer);
        clearTimeout(state.mapTimer);
        for (const name of Object.keys(requests)) {
            cancelRequest(name);
        }
    });

    updateControls();
    updatePlayback();
    if (state.available) {
        const [west, south, east, north] = config.metadata.bounds;
        const bounds = L.latLngBounds([south, west], [north, east]);
        map.fitBounds(bounds, { padding: [12, 12], animate: false });
        map.setMaxBounds(bounds.pad(.03));
        map.setMinZoom(Math.min(map.getZoom(), 13));
        clearTimeout(state.mapTimer);
        loadMap();
    }
}

function toLatLng(coordinates) {
    return [coordinates[1], coordinates[0]];
}

function formatNumber(value, decimals = 0) {
    return new Intl.NumberFormat('es-MX', { maximumFractionDigits: decimals }).format(value);
}

function formatDistance(meters) {
    const rounded = Math.round(meters);
    return rounded >= 1000 ? `${formatNumber(rounded / 1000, 3)} km` : `${formatNumber(rounded)} m`;
}
