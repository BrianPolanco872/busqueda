# Rutas entre parques de Mérida

Aplicación universitaria Laravel 13 / Blade para observar BFS y DFS en un mismo grafo dirigido de calles reales del **municipio de Mérida, Yucatán**. Una sola pantalla, sin cuentas ni dashboard.

## Funcionamiento sin internet

Después de instalar dependencias, importar datos y compilar, **todo se sirve desde localhost**: Leaflet, JavaScript, CSS, iconos, calles, parques, etiquetas, búsquedas y animación. No se usan teselas, fuentes por CDN, Nominatim ni consultas a Overpass durante el uso habitual. No depende de la caché del navegador ni de haber visitado una zona antes.

Es necesario mantener Laravel ejecutándose. No es una PWA ni un navegador GPS. El diseño móvil no implica que `localhost` de un teléfono acceda al servidor de una computadora.

## Preparación inicial (con internet)

Requisitos: PHP 8.3+, Composer, Node compatible con Vite 8, npm y Git. En Windows, instalar **Git for Windows**, que incluye Git Bash. No hace falta PostGIS ni un servidor de mapas.

Desde Git Bash, en la raíz del proyecto:

```bash
composer install
npm ci
```

Solo en una instalación nueva, sin `.env` configurado:

```bash
test -f .env || cp .env.example .env
php artisan key:generate
php artisan migrate
```

El proyecto usa SQLite por defecto. No ejecutar de nuevo `key:generate` sobre una instalación que ya tenga una clave y datos. Laravel Boost es una dependencia de desarrollo; sus guías ya están generadas.

## Obtener e importar datos reales

La fuente es OpenStreetMap, licencia ODbL 1.0. El municipio es la **relación 5961522**, `boundary=administrative`, `admin_level=6`, `INEGI:MUNID=31050`, nombre oficial «Municipio de Mérida». Verificar la relación antes de futuras actualizaciones; no confundirla con el punto de la ciudad ni con un rectángulo del centro.

Durante la preparación, ejecutar esta consulta en un proveedor Overpass que permita la descarga. Guardar la respuesta **JSON completa**, no HTML de error ni solo GeoJSON:

```overpass
[out:json][timeout:90][maxsize:268435456];
relation(5961522)
  ["boundary"="administrative"]
  ["admin_level"="6"]->.boundary;
.boundary map_to_area->.municipality;
(
  .boundary;
  nwr(area.municipality)["leisure"="park"];
  way(area.municipality)
    ["highway"~"^(motorway|trunk|primary|secondary|tertiary|residential|living_street|unclassified|service|road)(_link)?$"];
);
(._; >>;);
out body;
```

Por ejemplo, guardar el archivo como `storage/app/private/merida-source/merida.osm.json` y ejecutar:

```bash
php -d memory_limit=512M artisan mapa:importar storage/app/private/merida-source/merida.osm.json --boundary=5961522
```

El archivo debe incluir los nodos y ways referenciados por calles, parques y límite municipal. El importador valida referencias, sentidos y geometrías; activa el conjunto únicamente al terminar correctamente y conserva la versión anterior. La fecha de la fuente y la importación son distintas. Los archivos generados quedan en `storage/app/private/merida/versions/`, con un puntero `merida/active.json`.

Las calles se recortan al polígono municipal. Un parque que cruza el límite conserva su geometría real como referencia visual, pero solo entra al catálogo si tiene una parte dentro del municipio, y su punto de conexión queda dentro de él. Esto no habilita rutas fuera de Mérida.

**No se distribuye un extracto municipal real con el código.** Las respuestas de los proveedores públicos pueden fallar por límites o disponibilidad; los fixtures de `tests/` son sintéticos y nunca sustituyen la importación de datos reales. No importar un fixture como si fuera el municipio. Los parques disponibles serán los que incluya la fuente, no una lista fija de cuatro ejemplos.

## Compilar en Windows con Git Bash

La versión de la aplicación es `0.1.0`; su tag correspondiente es `v0.1.0`.

Abrir **Git Bash** en la carpeta del proyecto y ejecutar:

```bash
bash ./build.sh
```

También puede ejecutarse `./build.sh` si el sistema tiene permisos de ejecución adecuados. No hace falta WSL. En PowerShell puede invocarse Git Bash explícitamente:

```powershell
& 'C:\Program Files\Git\bin\bash.exe' ./build.sh
```

El script comprueba que la versión sea válida, que el tag exista y apunte a HEAD, y que los archivos de aplicación coincidan con el estado identificado por ese tag. Luego ejecuta la compilación Vite y guarda los recursos en `public/build/`, con un sello `release.json` que identifica versión, commit y manifiesto. Un build antiguo de la bienvenida no valida la nueva interfaz.

Si falta el tag o hay código sin identificar por él, **se detiene sin compilar**. No crea tags ni modifica el historial Git. Un tag sobre un commit antiguo no incluye los cambios del directorio de trabajo.

Si existe `public/hot`, detener Vite; si quedó un archivo `hot` obsoleto, retirarlo antes del build. Para la entrega offline no debe quedar activo ese modo. Usar `build.sh` como punto de entrada de construcción, no scripts alternativos.

## Arrancar después, sin internet

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Abrir la dirección que muestra Artisan. No se necesita Node, Composer, Vite dev server ni internet en esta etapa, pero sí los archivos ya instalados, el build, `.env`, SQLite y el conjunto cartográfico importado. No usar `composer setup` para el arranque offline porque reinstala dependencias.

## Uso y métricas

1. Buscar un parque por nombre; la búsqueda local ignora acentos. Los resultados se limitan a 50 coincidencias por consulta, indicando el total para refinar el texto; no se limita el catálogo importado.
2. Elegir el origen en el mapa. Se muestra el nodo conectado y el ajuste; se rechaza un punto fuera del polígono municipal o a más de **250 m de un nodo**. El ajuste no es una calle ni se añade a la distancia.
3. Seleccionar BFS, DFS o Comparar ambos y visualizar. La conexión del destino también se identifica; si no hay entrada mapeada, se informa que es aproximada.
4. Pausar, avanzar un evento y alternar BFS/DFS sin perder los resultados. La numeración corresponde a visitas; un evento de inspección de una arista no es otra visita.

- **Tramos:** aristas de la ruta entre nodos de la red, no cada punto geométrico de una curva.
- **Nodos visitados:** nodos únicos procesados, incluyendo origen y destino cuando se alcanza.
- **Distancia:** suma de longitudes geográficas de las geometrías de los tramos, sin los ajustes de extremos. Se compara con precisión de un metro, igual que lo mostrado.
- **Tiempo de búsqueda:** `hrtime(true)` alrededor del algoritmo sobre el grafo preparado. Incluye el registro didáctico de eventos en ambos métodos; excluye lectura de archivos, selección de extremos, reconstrucción, cálculo de distancia, HTTP, mapa y animación. Es una medición ilustrativa, no un benchmark estadístico.
- **Comparación:** «Más corta de las dos encontradas» o empate. BFS minimiza tramos del grafo no ponderado; ni BFS ni DFS garantizan la distancia mínima global.

Ambos algoritmos comparten versión, origen, destino y orden de vecinos, pero sus estados son independientes. Un cambio de dataset obliga a recargar la pantalla. Un destino inalcanzable no se confunde con un error de datos o una ejecución interrumpida.

El modelo es académico: usa calles `motorway`, `trunk`, `primary`, `secondary`, `tertiary`, sus variantes `_link`, y `residential`, `living_street`, `unclassified`, `service`, `road`; excluye accesos privados/no permitidos. Respeta las direcciones importadas y el sentido implícito de glorietas y autopistas (salvo una excepción explícita), pero no modela perfiles de transporte ni todas las restricciones de giro de un navegador real. Una conexión depende de IDs OSM compartidos, no de cruces dibujados; puentes no crean intersecciones falsas.

## Pruebas

Las pruebas de algoritmos y HTTP funcionan con fixtures locales y no necesitan internet ni el extracto real:

```bash
php artisan test --compact tests/Unit/GraphSearchTest.php
php artisan test --compact tests/Feature/ImportMeridaMapTest.php
php artisan test --compact tests/Feature/MapTest.php
php artisan test --compact
npm run test:ui
```

Cubren sentidos, DFS en profundidad, ciclos, caminos inalcanzables, extremos iguales, menor cantidad de tramos frente a menor distancia, geometrías, importación segura, consultas y validación. Las pruebas nativas de Node ejercitan los handlers reales de selección, CSRF, pasos, alternancia y respuestas tardías mediante dobles de DOM/Leaflet, sin dependencias adicionales. Ninguna de estas pruebas **sustituye un navegador con el JavaScript compilado**. La prueba de recursos compilados se omite explícitamente hasta que exista un build sellado por `build.sh`.

Para aceptar el flujo completo offline, después del tag, build e importación real:

1. Detener Vite y arrancar únicamente Laravel.
2. Desactivar el Wi-Fi y abrir la aplicación en un perfil nuevo del navegador, sin caché. No usar el botón global «Offline» de DevTools, porque también puede bloquear localhost.
3. Comprobar en Red que las solicitudes de la aplicación se resuelven desde el servidor local; ninguna a CDN, fuentes, teselas ni APIs externas.
4. Mover y ampliar el mapa a una zona no visitada; buscar otro parque, marcar origen y ejecutar BFS, DFS y Comparar ambos.
5. Verificar pausa, paso siguiente, numeración, rutas, detalle de calles, comparación, reinicio, error de selección fuera del municipio y cambios mientras hay solicitudes pendientes.
6. Repetir con viewport móvil y teclado. Una prueba HTTP aislada no demuestra esta aceptación visual.

No afirmar que esta prueba completa pasó si faltan el build, la fuente real o un navegador para ejecutarla. Medir memoria y tiempos sobre el conjunto municipal real antes de darlo por validado; no reducir silenciosamente la red al centro o al área visible para mejorar cifras.

## Actualización y rollback

- Volver a descargar e importar de forma explícita cuando se quiera actualizar la fuente; la aplicación no necesita hacerlo al arrancar.
- Conservar el snapshot anterior. Ante fallo de importación el activo no debe cambiar. Para volver a una versión anterior, detener el uso, conservar copia de `active.json` y restaurar su `version` a la versión previa existente y validada; no borrar datos.
- Para revertir código, restaurar exclusivamente los archivos de esta feature y sus manifests/locks desde la copia previa; reconstruir con la versión y tag que correspondan. No borrar SQLite, `.env` ni cambios ajenos.
- No hacer descargas masivas de teselas de OpenStreetMap: este proyecto utiliza datos vectoriales importados, no imágenes de un proveedor.

© OpenStreetMap contributors — https://www.openstreetmap.org/copyright . Datos bajo ODbL 1.0. Mantener atribución y fecha visibles en la aplicación. Leaflet mantiene su licencia BSD-2-Clause dentro del paquete local.
