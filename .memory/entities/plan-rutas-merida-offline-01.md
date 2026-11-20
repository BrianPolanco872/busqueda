---
entity: plan-rutas-merida-offline-01
type: plan
---

- Tipo: feature.
- Objetivo: Visualizar BFS y DFS en una sola Home de Laravel Blade sobre un mismo grafo dirigido de calles reales del municipio de Mérida, con funcionamiento íntegro en localhost sin internet después de la instalación e importación inicial.
- Estado: PARCIAL — código y pruebas previas al tag aplicados; pendiente tag válido, build autorizado, extracto municipal real y aceptación en navegador sin internet. No cerrar como IMPLEMENTADO hasta completar esas verificaciones.
- Fecha: 2026-11-19.
- Importancia: Alta; funcionamiento offline es un criterio obligatorio, no una mejora opcional.

## Verificación y contexto

- Skills disponibles: opencode, ponytail, ponytail-audit, ponytail-debt, ponytail-gain, ponytail-help, ponytail-review y report. Aplica ponytail, cargada en esta sesión.
- No hay herramientas MCP de memoria ni codesearch en el catálogo disponible. Se usaron read, glob y grep; se guarda el contrato en .memory/ como respaldo persistente.
- Antes del guardado no existían entidades .memory/; no se encontraron planes previos ni bugs activos documentados allí. Esto no demuestra ausencia de defectos en el proyecto.
- PHP 8.3.35 y Composer 2.10.3 están disponibles. No se instalaron paquetes ni se ejecutaron pruebas.
- composer.json: Laravel ^13.17, PHPUnit ^12.5.12; no dependencias cartográficas ni módulos de algoritmos.
- routes/web.php:5-7: GET / muestra welcome. resources/views/welcome.blade.php es la bienvenida estándar; contiene @fonts y enlaces externos, no una Home funcional del dominio.
- resources/js/app.js está vacío salvo un comentario; resources/css/app.css contiene Tailwind y referencia a Instrument Sans. vite.config.js configura el plugin de fuentes Bunny.
- Vite ya compila resources/css/app.css y resources/js/app.js. Se conservarán esas entradas; no hace falta SPA, Livewire, Inertia ni otro framework.
- tests/Feature/ExampleTest.php solamente comprueba respuesta 200 de /. tests/Unit/ExampleTest.php contiene una aserción trivial. No hay callers de búsquedas existentes que migrar.
- config/filesystems.php ofrece almacenamiento local privado. SQLite y sesiones locales forman parte del esqueleto existente; no se requiere un servicio remoto ni migraciones del dominio para la primera versión basada en archivos.
- git status --short reportó package-lock.json sin seguimiento antes de este trabajo. Preservar ese archivo y cualquier cambio ajeno; no restaurar ni reemplazar indiscriminadamente.
- AGENTS.md exige instalar Laravel Boost antes de cambios de aplicación y releer sus instrucciones. Queda como prerrequisito de la implementación con internet, no se ejecutó durante el plan.

## Alcance

- Una pantalla en /: encabezado bajo con título y subtítulo solicitados, controles a la izquierda y mapa de aproximadamente dos tercios del ancho; resultados en dos columnas debajo del mapa. En móvil: controles, mapa y resultados, sin desbordamiento horizontal.
- Buscar por nombre todos los parques del conjunto municipal importado; admitir acentos, nombres repetidos identificados por ubicación y ausencia de resultados.
- Elegir origen haciendo clic o tocando el mapa y mostrar el punto original, nodo conectado y distancia del ajuste. El destino también tendrá una conexión identificable a la red.
- Grafo académico dirigido compartido, sin selección de transporte. No convertirlo en instrucciones de navegación real.
- BFS, DFS y Comparar ambos; animación con pausa/reanudación, siguiente paso, contador y reinicio; selector Ver BFS/Ver DFS sin perder los dos resultados.
- Calles grises, parques verdes, nombres legibles; nodos y flechas de dirección a zoom adecuado; A/B, orden de visita, exploración tenue y ruta final más gruesa; azul BFS y naranja oscuro DFS; leyenda que no dependa solo del color.
- Ruta, Ver calles desplegable, tramos, nodos visitados, distancia y tiempo exclusivo de búsqueda. Destacar únicamente la menor distancia entre las dos rutas encontradas o el empate.

## Fuera de alcance

- Dashboard, bienvenida, navegación lateral, autenticación, historial, tráfico, GPS, cálculo de tiempo de viaje, perfiles de movilidad y algoritmos ponderados adicionales.
- Teselas raster remotas, geocodificación externa, consultas a Overpass/Nominatim durante el uso habitual y fuentes o librerías por CDN.
- Descarga masiva de teselas públicas. El mapa se dibujará con geometrías vectoriales propias importadas, no con una caché de mapas previamente visitados.
- PWA/service worker, servidor de teselas, PostGIS, colas y extensiones GIS como prerrequisitos de esta primera solución.
- Garantía de que todos los parques existentes estén presentes en OpenStreetMap, que ambos algoritmos den rutas distintas o que alguno minimice distancia global.

## Decisión técnica

- La última petición del usuario resuelve la decisión pendiente: datos locales y dibujo vectorial, sin dependencia de fondo cartográfico externo.
- Leaflet se instalará durante la preparación y se importarán su JS/CSS desde el paquete local a través de Vite. Los artefactos compilados se servirán desde public/build. Marcadores e iconos serán locales, preferentemente SVG/CSS.
- Usar tipografía del sistema para evitar descargas y configuración innecesarias de fuentes. Retirar la integración Bunny de la nueva pantalla y del build si ya no tiene consumidores.
- Un conjunto local versionado en storage/app/private/merida/ contendrá el límite, catálogo de parques, geometrías de calles, grafo compacto y metadatos. Separar geometría de adyacencias evita cargar en cada búsqueda toda la información visual.
- El mapa solicita geometrías locales por área visible; el backend ejecuta las búsquedas sobre la red municipal completa. Nunca recortar el grafo al viewport ni al rectángulo origen-destino.
- Evitar migraciones y dependencias de routing externas. La viabilidad de memoria/tamaño se comprobará con el extracto real antes de dar por terminado el importador.

## Criterios de aceptación verificables

1. Con dependencias instaladas, datos importados y build generado, Home abre desde cero y se recarga en localhost con Wi-Fi apagado y caché del navegador vacía.
2. Ninguna función principal solicita recursos a un host externo; JS, CSS, iconos, mapa, etiquetas, parques y búsquedas son locales.
3. La búsqueda encuentra por nombre los parques del catálogo municipal local; no se limita a los cuatro ejemplos iniciales.
4. La cobertura se valida con el polígono del municipio de Mérida en Yucatán, no con un rectángulo ni con un límite de ciudad ambiguo.
5. Seleccionar origen muestra su nodo de conexión y ajuste; selección fuera del municipio o sin conexión razonable produce un mensaje, no una conexión ficticia.
6. BFS y DFS usan la misma versión del grafo, los mismos nodos de origen/destino y el mismo orden de vecinos; toda arista de sus resultados existe en el sentido indicado.
7. Pausar y avanzar un paso producen estados reproducibles; cada visita se numera y no se confunde con una calle inspeccionada ni con la ruta final.
8. Las métricas coinciden con la ruta y la traza: nodos procesados únicos, aristas de la ruta, suma de longitudes de sus geometrías y milisegundos de recorrido del algoritmo.
9. El cronómetro excluye lectura de datos, ajuste de coordenadas, renderizado, HTTP, animación y cálculo posterior de distancias; se documenta si el registro de eventos se incluye, de forma igual para ambos métodos.
10. La comparación conserva ambos resultados al alternar la visualización; el rótulo es «Más corta de las dos encontradas», nunca «ruta óptima» por distancia.
11. Empate, destino inalcanzable, origen igual a destino, ciclos, datos ausentes/corruptos y errores de validación tienen estados correctos y comprensibles.
12. La pantalla es utilizable con teclado y en viewport móvil; las etiquetas y controles principales no dependen solamente del color ni de pasar el cursor.

## Pasos de implementación

1. Preparación: preservar cambios existentes; instalar Laravel Boost según AGENTS.md y releer las instrucciones generadas antes de tocar aplicación. Instalar Leaflet durante esta etapa con internet y conservar los locks resultantes. No incorporar otros frameworks.
2. Datos iniciales: documentar una extracción reproducible de OpenStreetMap con nodos, ways, relaciones multipolígono de parques y límite municipal completo. Verificar identidad del municipio, cobertura, licencia, fecha y nombres de parques. La obtención inicial puede requerir internet; el importador recibirá un archivo local, sin llamadas de red al usar Home.
3. Importador local: validar esquema, referencias, coordenadas, polígono con huecos y metadatos; rechazar archivos incompletos. Generar archivos normalizados y un informe de conteos/cobertura/memoria. Activar el conjunto solo después de validarlo y conservar el anterior para rollback.
4. Topología: usar identificadores de nodos OSM para conectividad; no conectar calles por un cruce visual sin nodo compartido, especialmente puentes. Consolidar puntos de forma en tramos entre intersecciones conservando geometrías y puntos de conexión necesarios; no contar cada punto de una curva como un tramo. Manejar componentes, bucles y aristas paralelas mediante IDs de arista.
5. Dirección y longitudes: documentar un conjunto explícito de etiquetas de calles para el ejercicio, sin perfiles de movilidad. Aplicar oneway=yes/1/true, oneway=-1, doble sentido cuando corresponda y reglas implícitas como glorietas con sus excepciones. Mantener dos aristas dirigidas para doble sentido. Sumar distancias geográficas de todos los puntos de cada tramo, no la recta entre extremos. Conservar calles/segmentos dentro del límite; los puntos de frontera no deben crear cruces falsos. Documentar que no se modelan todos los perfiles ni restricciones de giro de un navegador real.
6. Conexión de extremos: validar pertenencia municipal y elegir un nodo local de conexión con distancia de ajuste visible y umbral documentado. Para parques preferir una entrada mapeada cuando exista; si el ajuste es aproximado, rotularlo. Una línea de ajuste no cuenta como calle ni como distancia de la ruta; no crear aristas ficticias para forzar conectividad.
7. Algoritmos: BFS con SplQueue y DFS iterativa con pila que preserve el comportamiento en profundidad, sin recursión sobre la ciudad. Adyacencias ordenadas al importar; estado independiente por ejecución; procesados y padres por IDs de nodo/arista. Comprobar destino al procesarlo. Generar eventos compactos de visita/inspección, reconstruir el camino dirigido y manejar inicio igual a destino e inalcanzable. Si un límite técnico interrumpe una ejecución, informar ejecución incompleta, nunca inalcanzable.
8. Medición y contrato: usar hrtime(true) solo alrededor del recorrido del algoritmo sobre el grafo ya preparado; reconstrucción, distancia, serialización y animación quedan fuera. La instrumentación didáctica será equivalente y su inclusión se documentará. Retornar versión del conjunto, extremos resueltos, traza, ruta, métricas y estado. En comparación, cargar una sola versión y ejecutar ambos algoritmos de forma independiente sobre ella. Definir empate con la misma precisión de distancia que se muestra.
9. Endpoints locales en routes/web.php: GET / para Home; GET /parques para búsqueda; GET /mapa para geometrías del área visible; POST /origen para resolver la conexión; POST /busquedas para BFS/DFS/comparación. Aplicar validación de coordenadas, polígono, IDs, método y versión; conservar CSRF. Consultar solo el conjunto local, sin URLs aportadas por usuario. Tratar nombres del dataset como texto al mostrarlos.
10. Vista y mapa: reemplazar contenido de welcome por Home en español; composición fiel a la referencia pero sin saturación decorativa. Leaflet sin tileLayer externo, con parques, calles y etiquetas locales. Dibujar la base con renderer eficiente; limitar marcadores/flechas/etiquetas al área visible y zoom adecuado, conservando completa la búsqueda backend. Añadir controles accesibles, resultados, detalle de calles y estados de carga/error/datos no importados.
11. Animación: consumir la traza calculada, no simular BFS/DFS en el navegador. Llevar un cursor por algoritmo; pausa/reanudación y siguiente paso no recalculan las métricas. Al cambiar origen, parque o método, cancelar animación e invalidar resultados anteriores y respuestas tardías. Reiniciar limpia estado de búsqueda, no elimina datos importados.
12. Build y ejecución offline: compilar assets, comprobar manifiesto y recursos de Leaflet; no depender de npm run dev ni de public/hot para la entrega. Evitar conexiones externas de fuentes, iconos o scripts. Documentar preparación con internet y arranque posterior con PHP/Laravel y SQLite locales. No usar el comando general composer setup como requisito habitual offline porque reinstala dependencias.
13. Pruebas y cierre: ejecutar pruebas unitarias/feature, build y comprobación visual; validar específicamente con el conjunto municipal real y en pantalla móvil. Hacer la prueba offline con perfil limpio, caché vacía, Vite detenido y Wi-Fi apagado, manteniendo localhost accesible; verificar en la pestaña de red ausencia de solicitudes externas. Un bloqueo global de red del navegador no es equivalente porque también bloquearía localhost.

## Archivos afectados y razón

- routes/web.php: reemplazar la bienvenida y declarar endpoints del módulo con middleware web existente.
- resources/views/welcome.blade.php: convertir la vista actual en la Home principal; no crear otra pantalla ni mantener contenido de bienvenida innecesario.
- resources/css/app.css: layout responsive, colores, controles, leyenda y estilos de Leaflet; tipografía de sistema.
- resources/js/app.js: inicialización del mapa local, catálogo, selección, solicitudes y animación; dividir en módulos de propósito concreto solo si el tamaño final lo justifica.
- vite.config.js: retirar uso no necesario de Bunny y mantener compilación local con las dos entradas actuales.
- package.json y package-lock.json: añadir Leaflet y fijar la instalación reproducible; preservar el lock previo sin seguimiento.
- app/Http/Controllers/MapController.php (nuevo): Home, catálogo, geometrías, resolución del origen y coordinación de búsquedas con validación Laravel.
- app/Services/MeridaMapData.php (nuevo): lectura del snapshot local, metadatos/versión, búsqueda de parques, selección geográfica, conexión a nodos y geometrías de viewport; reutilizar la misma carga durante la comparación.
- app/Services/GraphSearch.php (nuevo): BFS, DFS, traza, padres, reconstrucción y métricas; sin acceso HTTP ni lógica de Blade.
- app/Console/Commands/ImportMeridaMap.php (nuevo): importar y validar extracto local; activar versión y conservar la anterior. Extraer un colaborador solo si el parser realmente lo requiere.
- storage/app/private/merida/ (datos generados): grafo, geometrías, parques, límite, metadatos y versiones locales. No publicar datos arbitrarios ni exigir enlaces simbólicos en Windows.
- tests/Unit/GraphSearchTest.php (nuevo): corrección de recorridos, sentidos, métricas, ciclos y extremos.
- tests/Feature/MapTest.php (nuevo): Home, catálogo, mapa, ajuste de origen, búsqueda/comparación, validación y errores de datos, usando fixtures locales.
- tests/Feature/ImportMeridaMapTest.php y tests/Fixtures/merida/ (nuevos): importación reproducible, etiquetas de dirección, multipolígonos, referencias, límite y activación segura; la prueba pequeña no sustituye la verificación del conjunto real.
- tests/Feature/ExampleTest.php: conservar o integrar su comprobación de / sin duplicación innecesaria.
- README.md: instrucciones reales de preparación, fuente/licencia, importación, definición de métricas, arranque offline, verificación, actualización y rollback.
- composer.json, composer.lock y AGENTS.md: solo lo exigido por instalación de Boost durante implementación; releer cualquier pauta generada y no asumir cambios adicionales.
- No se prevén cambios de base de datos, autenticación, bootstrap/app.php ni phpunit.xml: la estructura existente ya permite el módulo y pruebas.

## Pruebas mínimas concretas

- Fixture dirigida donde BFS use menos tramos pero su ruta mida más que la de DFS; validar que no se promete optimización por distancia.
- Camino de sentido único y sentido inverso, glorieta, doble sentido, ciclo, aristas paralelas, nodo aislado y origen igual a destino.
- Ambos algoritmos coinciden en alcanzabilidad sobre el mismo grafo completo; si difieren, hay un defecto o ejecución incompleta.
- Longitud calculada a lo largo de una geometría curva, distinta de la recta entre extremos; tramos no afectados por puntos geométricos de forma.
- Importación rechaza referencias ausentes y municipio incorrecto; no activa ni destruye el snapshot anterior ante fallo.
- Origen fuera de polígono municipal aunque esté dentro de su bounding box; parque con y sin conexión; búsqueda insensible a acentos.
- Mapa, búsqueda y recorridos usan únicamente fixtures/archivos locales y prohíben solicitudes HTTP externas en pruebas del backend.
- Prueba manual de caché limpia + Wi-Fi apagado + assets compilados, ampliando y moviendo el mapa a un sector no visitado previamente, buscando otro parque y ejecutando ambos algoritmos.
- Comprobar móvil, teclado, pausa, paso único, cambio de algoritmo visible, reinicio y respuestas tardías.

## Plan de rollback

- Durante implementación, conservar copias de los archivos modificados y el conjunto de datos activo antes de reemplazarlos; no sobrescribir cambios ajenos.
- Ante fallo de importación, no activar la nueva versión. Ante fallo posterior de datos, volver a señalar la versión local anterior y verificar metadatos y grafo coherentes.
- Ante fallo funcional, restaurar solo los archivos de esta feature desde su copia previa, la ruta de bienvenida y los manifests/locks previos; reconstruir assets con las dependencias correspondientes si es necesario.
- Restaurar también los cambios de Boost solo si pertenecen a esta implementación y se desea revertirlos; no borrar el SQLite existente ni datos personales.
- No usar comandos destructivos ni operaciones que modifiquen historial Git. La memoria del plan permanece como registro, aunque se revierta implementación.

## Riesgos y dependencias externas

- Internet solo se permite para preparar dependencias y obtener la fuente inicial o una actualización explícita; el uso ordinario no depende de red externa. Composer/npm y sus licencias se conservan localmente.
- OpenStreetMap no garantiza todos los parques ni sentidos actualizados. Mostrar fecha, origen, atribución y cobertura; reportar ausencias, no inventar parques o conexiones.
- Verificar el límite administrativo real de Mérida y la integridad de un extracto municipal completo; consultas públicas de gran tamaño pueden limitarse, por lo que la extracción inicial puede necesitar segmentación o un archivo regional adecuado.
- El tamaño del municipio y una DFS extensa pueden consumir memoria y producir trazas grandes. Medir antes de cerrar, separar geometrías, servir solo el área visible y evitar copiar el grafo por paso. No resolverlo truncando silenciosamente la red o las visitas.
- Las restricciones de giro y reglas por transporte de un navegador real no son parte del modelo académico de direcciones entre nodos; explicitar esta limitación en la interfaz/documentación.
- Sin internet, la cartografía local no tendrá imágenes satelitales ni el estilo completo de un proveedor, pero sí calles, parques, etiquetas y topología reales.
- Wi-Fi apagado no significa Laravel apagado: PHP/servidor local, archivos y almacenamiento deben estar disponibles. En móvil se exige diseño responsive; localhost de un teléfono no es localhost de una computadora.
- No se ejecutaron pruebas ni importación en esta sesión de planificación; cobertura, rendimiento y funcionamiento offline quedan por demostrar durante implementación.

## Alternativas consideradas

- Leaflet local + geometrías locales: elegida por requisito del usuario; mínimo suficiente para dibujar y explorar los datos sin servicios adicionales.
- Leaflet local + teselas remotas: descartada; falla con Wi-Fi apagado aunque el JS sea local.
- Consultar Overpass/Nominatim en cada búsqueda: descartada; dependencia de internet, límites y versiones inconsistentes para comparación.
- Servidor local de teselas/vector tiles: viable pero añade generación y servicios innecesarios para esta primera pantalla; reconsiderar únicamente si las mediciones del conjunto real lo exigen.
- Guardar solo un rectángulo del centro o mostrar el mapa desde caché: descartado; incumple alcance municipal y apertura offline en frío.

## Verificación de aplicación — 2026-11-19

- Se recuperaron plan, feature y relaciones desde el respaldo .memory/. Tipo feature y plan de rollback confirmados.
- PHP, Composer, Node y npm están disponibles. Shell PowerShell disponible; se detectó bash.exe pero no se verificó su funcionamiento.
- Rama main; HEAD e0608ef. No existen tags Git, no hay build.sh y package.json no declara versión del proyecto.
- El plan genera artefactos Vite. Las reglas obligatorias exigen ./build.sh y versión/tag coherentes antes de construir; no se puede sustituir el build por npm run build directamente.
- Bloqueo: falta acordar versión del proyecto, preparar su tag por el usuario o @git y autorizar incorporar build.sh al alcance. No se ejecutaron operaciones de historial Git.
- Cambios aplicados a la aplicación: ninguno. Solo se actualiza este registro y el estado de la feature; no corresponde crear un fix ni marcar IMPLEMENTADO.
- Dependencias, importación, build y pruebas: no ejecutados. Testing sigue requiriendo solicitud explícita, incluso aunque figure como paso de verificación en el plan.

## Ampliación aprobada — 2026-11-20

- El usuario autorizó incorporar build.sh al proyecto y al alcance, versión inicial 0.1.0 en package.json, tag v0.1.0 y README con ejecución desde Windows/Git Bash.
- El usuario solicitó explícitamente pruebas BFS, DFS y flujo completo en localhost con recursos compilados y sin internet; se permite ejecutar Testing en esta aplicación.
- Se permite completar preparación e implementación antes del tag. La compilación sigue bloqueada hasta que el usuario prepare un tag que identifique el código correcto. No crear tags ni modificar historial Git.
- build.sh será el único punto de entrada de construcción y validará versión, tag/HEAD y correspondencia de fuentes; no crear artefactos por comandos alternativos.
- Se justifica app/Services/MapGeometry.php como colaborador compartido de polígono, recorte y distancias necesario para el importador y la validación de origen, tal como permite el paso de importación.
- Copia previa guardada fuera del workspace en C:/Users/Administrador/AppData/Local/Temp/opencode/parques-merida-before-offline-01; conserva archivos originales y el package-lock.json que ya existía sin seguimiento.
- Relación municipal verificada por API oficial: 5961522, admin_level=6, INEGI:MUNID=31050. La descarga inicial del extracto municipal encontró HTTP 406 y timeout en proveedores públicos; no sustituir esos errores por datos ficticios.

## Aplicación previa al tag — 2026-11-20

- Cambios aplicados: preparación Laravel Boost 2.10.4, Leaflet 1.9.4 local, package.json y lock versión 0.1.0, build.sh para Git Bash con validación de tag/HEAD/fuentes y sello del manifiesto. Se retiró la integración Bunny para no descargar fuentes.
- Cambios aplicados: Home en español responsive, mapa vectorial Leaflet sin servicios remotos, catálogo local de parques, selección y conexión de origen/destino, endpoints locales con CSRF/validación/versionado, BFS/DFS con traza y métricas, animación con pausa/paso/final/alternancia, comparación y mensajes de error. README documenta instalación, fuente, importación, Git Bash, uso offline y rollback.
- Cambios aplicados: importador Overpass JSON con relaciones y huecos, calles recortadas al polígono, topología OSM, sentidos y longitudes de geometría, snapshots locales con activación atómica y versión previa. No se descargaron ni publicaron teselas.
- Aclaración aplicada: parques que cruzan el límite conservan la geometría original como referencia visual y solo entran al catálogo con un punto dentro del municipio. El grafo de búsqueda permanece recortado al municipio. Evita abortar un extracto válido; no inventa conexiones ni rutas fuera del territorio.
- Integridad revisada: rechazar resultados parciales Overpass, anillos autointersectados, referencias/adyacencias dañadas e IDs de extremos que no sean string; evitar elegir puntos de parque sobre bordes excluidos de huecos. La animación DFS muestra el nodo padre al volver de una rama sin contarlo como otra visita.
- Pruebas autorizadas ejecutadas: php artisan test --compact: 79 pruebas totales, 78 correctas, 589 aserciones, 1 omitida (CompiledAssetsTest por ausencia de release.json generado con tag/build válido). npm run test:ui: 4 correctas, sin dependencias de pruebas añadidas.
- Verificación básica: Pint --dirty --format agent correcto; node --check correcto; bash -n build.sh correcto; git diff --check sin errores. Los tests incluyen importación a almacenamiento fake seguida de consultas y búsquedas HTTP, sin solicitudes externas.
- Límite de la evidencia: pruebas PHP y dobles DOM/Leaflet no sustituyen un navegador con recursos compilados. La prueba real de localhost con Wi-Fi apagado, caché vacía, móvil y dataset municipal completo NO se ha realizado.
- Build: NO ejecutado. Se encontró un manifiesto antiguo de bienvenida en public/build; no se usa para certificar la nueva interfaz. build.sh generará release.json con versión, commit y hash del manifiesto después de un build válido.
- Fuente real: descarga no obtenida; Overpass devolvió HTTP 406 o timeout. README incluye consulta reproducible para relación municipal 5961522. Los fixtures sintéticos permanecen exclusivamente en tests/ y almacenamiento fake, nunca como datos reales de la aplicación.
- Git: HEAD e0608efae90c664137128fdfc9fb2dfcfa71100f sigue siendo el commit inicial; no tiene versión 0.1.0 ni esta implementación. No hay tag en HEAD y no existe aún un hash de commit que identifique los cambios de esta entrega. No se ejecutaron operaciones que modifiquen historial.
- Desviaciones del plan original: ampliación de build.sh/versión/pruebas explícitamente aprobada por el usuario; helper MapGeometry necesario y anunciado; pruebas JS nativas y prueba de assets añadidas al alcance de Testing autorizado, sin librerías adicionales. Boost generó sus archivos de configuración/skills en la preparación exigida por AGENTS.md. Pendientes de aceptación registrados, no ocultados como éxito.
