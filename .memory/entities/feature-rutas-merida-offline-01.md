---
entity: feature-rutas-merida-offline-01
type: feature
---

- Objetivo: Visualizar BFS y DFS en una sola Home de Laravel Blade sobre un mismo grafo dirigido de calles reales del municipio de Mérida, con funcionamiento íntegro en localhost sin internet después de la instalación e importación inicial.
- Alcance: Mapa local con calles, parques y límite municipal; buscar parques reales por nombre; seleccionar destino y origen sobre el mapa; mostrar conexión del origen a la red; ejecutar BFS, DFS y comparación; animación pausable y por pasos; comparar nodos visitados, tramos, distancia y tiempo exclusivo de búsqueda; interfaz en español adaptable a móvil.
- Alcance offline: Leaflet, CSS, JavaScript, iconos y cualquier fuente utilizada deben estar en el proyecto; calles y parques se dibujan desde datos locales, sin proveedores de teselas ni APIs durante el uso habitual. Laravel debe seguir ejecutándose localmente.
- Fuera de alcance: Cuentas, historial, tráfico, navegación GPS, perfiles peatonales o vehiculares, tiempos de viaje, algoritmos distintos de BFS/DFS, servicios cartográficos remotos en ejecución y una PWA que sustituya al servidor localhost.
- Criterios de aceptación: Apertura en frío y recarga con Wi-Fi apagado y caché del navegador vacía; ninguna dependencia HTTP externa para las funciones; búsqueda local de parques municipales; origen conectado visiblemente a la red; ambos métodos sobre igual versión completa del grafo y mismos extremos; sentidos respetados; pasos reproducibles y exploración distinta de ruta; métricas reales; ejecución medida en milisegundos excluyendo carga e interfaz; comparación rotulada como menor distancia de las dos encontradas; empate, inalcanzable y origen igual a destino; uso responsive y accesible.
- Archivos/módulos confirmados por el usuario: Home Blade, CSS/JavaScript y backend necesario para datos y búsquedas; rutas concretas determinadas por inspección.
- Solicitada por: usuario.
- Referencia visual: Imagen del usuario; panel compacto izquierdo, mapa de aproximadamente dos tercios del ancho y comparación debajo del mapa, sin dashboard ni navegación lateral.
- Prioridad de requisitos: Los mensajes posteriores reemplazan la maqueta simulada, el tiempo estimado de recorrido y cualquier dependencia de mapas externos. No inventar resultados ni garantizar que BFS y DFS produzcan rutas diferentes.
- Fuente propuesta: Extracto local de OpenStreetMap, con atribución, fecha y límite municipal verificado; cobertura sujeta a los datos importados.
- Estado: EN IMPLEMENTACIÓN — código y preparación anteriores al tag aplicados en fix-rutas-merida-offline-01; pendiente tag/build, importación municipal real y aceptación completa offline. No validado aún como entrega terminada.
- Implementación registrada en: fix-rutas-merida-offline-01.
- Pruebas autorizadas: 78 PHP correctas (589 aserciones), 4 JavaScript correctas; 1 prueba de assets omitida por build/tag pendiente.
- Última actualización: 2026-11-20.
