<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="description" content="Explora BFS y DFS sobre las calles y parques del municipio de Mérida con datos locales.">
        <title>Rutas entre parques de Mérida</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <a class="skip-link" href="#route-controls">Saltar a los controles</a>
        <div class="app-shell">
            <header class="page-header">
                <div class="title-icon" aria-hidden="true">↗</div>
                <div>
                    <h1>Rutas entre parques de Mérida</h1>
                    <p>Explora las calles con búsqueda en anchura y profundidad.</p>
                </div>
            </header>

            <main class="workspace">
                <aside class="controls-card card" aria-labelledby="controls-heading">
                    <div class="card-heading">
                        <h2 id="controls-heading">Planea tu recorrido</h2>
                        <p>Un origen, un parque y dos formas de explorar.</p>
                    </div>
                    <form id="route-controls" action="{{ route('search') }}" method="POST">
                        @csrf
                        <fieldset id="route-fields" @disabled(! $mapMetadata || $mapError)>
                            <legend class="sr-only">Configurar búsqueda</legend>
                            <section class="control-section" aria-labelledby="origin-heading">
                                <h3 id="origin-heading"><span class="step-number">1</span> Punto de partida <span class="point-letter">A</span></h3>
                                <p class="help">Elige un punto dentro del municipio.</p>
                                <button class="button button-secondary full-width" type="button" id="pick-origin" aria-pressed="false">⌖ &nbsp; Elegir en el mapa</button>
                                <p id="origin-summary" class="selection-summary">Ningún punto seleccionado.</p>
                            </section>

                            <section class="control-section" aria-labelledby="destination-heading">
                                <h3 id="destination-heading"><span class="step-number">2</span> Parque de destino <span class="point-letter destination-letter">B</span></h3>
                                <label for="park-query" class="sr-only">Buscar parque por nombre</label>
                                <input id="park-query" type="search" placeholder="Escribe el nombre de un parque" autocomplete="off" aria-controls="park-results" aria-describedby="park-count destination-summary">
                                <p id="park-count" class="help" role="status">Busca por nombre o elige un parque en el mapa.</p>
                                <ul id="park-results" class="park-results" aria-label="Parques encontrados"></ul>
                                <p id="destination-summary" class="selection-summary">Ningún parque seleccionado.</p>
                            </section>

                            <fieldset class="control-section method-options">
                                <legend><span class="step-number">3</span> Método de búsqueda</legend>
                                <label class="method-option bfs-option">
                                    <input type="radio" name="method" value="bfs">
                                    <span><strong>BFS <span>· Anchura</span></strong><small>Explora nivel por nivel.</small></span>
                                </label>
                                <label class="method-option dfs-option">
                                    <input type="radio" name="method" value="dfs">
                                    <span><strong>DFS <span>· Profundidad</span></strong><small>Avanza por una rama antes de volver.</small></span>
                                </label>
                                <label class="method-option">
                                    <input type="radio" name="method" value="both" checked>
                                    <span><strong>Comparar ambos</strong><small>Mismos puntos y misma red de calles.</small></span>
                                </label>
                            </fieldset>
                            <div class="form-actions">
                                <button id="visualize" class="button button-primary full-width" type="submit" disabled>▶ &nbsp; Visualizar búsqueda</button>
                                <button id="reset" class="button button-reset full-width" type="button">↺ &nbsp; Reiniciar</button>
                            </div>
                        </fieldset>
                    </form>
                    <p id="app-status" class="app-status" role="status" aria-live="polite" aria-atomic="true">{{ $mapError ?: ($mapMetadata ? 'Selecciona el origen y el parque para comenzar.' : 'No hay datos locales importados.') }}</p>
                    <div class="learning-note">
                        <strong>¿Qué estamos comparando?</strong>
                        <p>BFS encuentra una ruta con menos tramos. DFS sigue el orden de las ramas. Ninguno garantiza la menor distancia.</p>
                    </div>
                </aside>

                <section class="map-card card" aria-labelledby="map-heading">
                    <div class="map-heading">
                        <h2 id="map-heading">Mapa de Mérida <span>Yucatán, México</span></h2>
                        <div id="view-switch" class="view-switch" role="group" aria-label="Recorrido visible en el mapa" hidden>
                            <button id="view-bfs" type="button" aria-pressed="true" disabled>Ver BFS</button>
                            <button id="view-dfs" type="button" aria-pressed="false" disabled>Ver DFS</button>
                        </div>
                        <span class="local-badge">● Datos locales</span>
                    </div>
                    <div class="map-frame">
                        <div id="map" tabindex="0" role="region" aria-label="Mapa de calles y parques de Mérida" aria-describedby="map-instructions"></div>
                        <p id="map-load-status" class="map-load-status" role="status" aria-live="polite">{{ $mapMetadata && ! $mapError ? 'Cargando cartografía local…' : '' }}</p>
                        <span id="map-crosshair" class="map-crosshair" aria-hidden="true" hidden>+</span>
                        <div id="map-empty" class="map-empty" @if ($mapMetadata && ! $mapError) hidden @endif>
                            <span class="empty-symbol" aria-hidden="true">⌑</span>
                            <h3>El mapa comienza con datos reales</h3>
                            <p id="map-empty-message">{{ $mapError ?: 'Aún no hay un conjunto local de calles y parques disponible.' }}</p>
                            <p>Importa el extracto local validado de OpenStreetMap con el importador del proyecto y recarga esta página.</p>
                            <small>No se muestran calles, parques ni rutas de ejemplo.</small>
                        </div>
                        <div class="map-legend" aria-label="Leyenda del mapa">
                            <span><i class="legend-park" aria-hidden="true"></i> Parque</span>
                            <span><i class="legend-selected-park" aria-hidden="true"></i> Parque elegido</span>
                            <span><i class="legend-arrived-park" aria-hidden="true"></i> Destino alcanzado</span>
                            <span><i class="legend-street" aria-hidden="true"></i> Calle → sentido</span>
                            <span><i class="legend-bfs" aria-hidden="true"></i> BFS</span>
                            <span><i class="legend-dfs" aria-hidden="true"></i> DFS</span>
                            <span><i class="legend-explored" aria-hidden="true"></i> Exploración</span>
                            <span><i class="legend-route" aria-hidden="true"></i> Ruta final</span>
                            <span><i class="legend-node" aria-hidden="true">1</i> Orden de visita</span>
                        </div>
                    </div>
                    <div class="playback" aria-label="Reproducción de la exploración">
                        <div class="playback-buttons">
                            <button id="play-pause" class="button button-small" type="button" disabled>▶ Reproducir</button>
                            <button id="next-step" class="button button-small" type="button" disabled>Siguiente paso →</button>
                            <button id="finish" class="button button-small" type="button" disabled>Ver final</button>
                        </div>
                        <label class="playback-speed" for="playback-speed">Velocidad
                            <select id="playback-speed" aria-describedby="playback-help">
                                <option value="0.5">0.5× · Lenta</option>
                                <option value="1" selected>1× · Normal</option>
                                <option value="2">2× · Rápida</option>
                                <option value="4">4× · Muy rápida</option>
                            </select>
                        </label>
                        <button id="sound-toggle" class="button button-small" type="button" aria-pressed="true">Sonido: activado</button>
                        <p id="playback-help" class="help">La velocidad solo cambia la animación, no las métricas. El sonido comienza al reproducir o avanzar un paso.</p>
                        <p id="playback-counter" role="status" aria-live="polite">Sin recorrido · Paso 0 de 0</p>
                        <p id="current-node" class="help">Nodo actual: —</p>
                    </div>
                    <div class="map-instructions-row">
                        <p id="map-instructions">Arrastra para moverte. Acércate para ver nodos y sentidos. Selecciona un parque verde como destino.</p>
                        <button id="select-center" class="button button-small" type="button" hidden>Elegir centro como origen</button>
                    </div>
                </section>

                <section class="results-section" aria-labelledby="results-heading">
                    <div class="results-heading">
                        <h2 id="results-heading">Resultados del recorrido</h2>
                    </div>
                    <p id="comparison" class="comparison" role="status">Las métricas aparecerán al ejecutar la búsqueda.</p>
                    <div class="result-grid">
                        @foreach (['bfs' => ['BFS', 'Búsqueda en anchura'], 'dfs' => ['DFS', 'Búsqueda en profundidad']] as $method => [$label, $description])
                            <article class="result-card card {{ $method }}-result" aria-labelledby="{{ $method }}-heading">
                                <div class="result-title">
                                    <h3 id="{{ $method }}-heading"><span class="algorithm-dot" aria-hidden="true"></span>{{ $label }} <small>{{ $description }}</small></h3>
                                    <span id="{{ $method }}-badge" class="result-badge" hidden></span>
                                </div>
                                <p id="{{ $method }}-status" class="help">Ejecuta una búsqueda para ver el resultado.</p>
                                <dl class="metrics">
                                    <div><dt>Distancia</dt><dd id="{{ $method }}-distance">—</dd></div>
                                    <div><dt>Tramos de ruta</dt><dd id="{{ $method }}-segments">—</dd></div>
                                    <div><dt>Nodos visitados</dt><dd id="{{ $method }}-visited">—</dd></div>
                                    <div><dt>Tiempo de búsqueda</dt><dd id="{{ $method }}-time">—</dd></div>
                                </dl>
                                <details id="{{ $method }}-details" class="route-details" hidden>
                                    <summary>Ver calles</summary>
                                    <p id="{{ $method }}-route-summary" class="help"></p>
                                    <ol id="{{ $method }}-streets"></ol>
                                </details>
                            </article>
                        @endforeach
                    </div>
                    <p class="academic-note">Ejercicio académico sobre una red dirigida. No es un navegador: no modela todos los perfiles de movilidad ni las restricciones de giro. El tiempo mide solo la búsqueda, no la animación ni el traslado.</p>
                </section>
            </main>

            <footer class="page-footer">
                <p>Cartografía: <a href="https://www.openstreetmap.org/copyright" rel="noreferrer">© OpenStreetMap contributors</a> · Datos locales <span id="source-date">{{ $mapMetadata ? ($mapMetadata['source_date'] ?? 'sin fecha de fuente') : 'sin importar' }}</span>@if ($mapMetadata) · Importado: {{ $mapMetadata['imported_at'] ?? 'fecha no disponible' }}@endif</p>
                <p>Municipio de Mérida · <span id="data-license">{{ $mapMetadata['license'] ?? 'Licencia de la fuente: ODbL' }}</span></p>
            </footer>
        </div>
        <noscript><p class="noscript-message">Activa JavaScript para usar el mapa local y visualizar los recorridos.</p></noscript>
        <script type="application/json" id="map-config">{!! \Illuminate\Support\Js::encode(['metadata' => $mapMetadata, 'error' => $mapError, 'urls' => ['parks' => route('parks'), 'map' => route('map'), 'origin' => route('origin'), 'search' => route('search')]]) !!}</script>
    </body>
</html>
