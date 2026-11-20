---
entity: fix-rutas-merida-offline-01
type: fix
---

- Objetivo: Implementar la preparación y el código de Home con mapa local de Mérida, importación OSM y visualización BFS/DFS conforme al plan aprobado, hasta el límite previo al tag.
- Estado: PARCIAL — código aplicado y pruebas previas al tag aprobadas; no se certifica aún la entrega offline con datos reales y assets nuevos.
- Archivos tocados: app/Http/Controllers/MapController.php; app/Services/GraphSearch.php; app/Services/MeridaMapData.php; app/Services/MapGeometry.php; app/Console/Commands/ImportMeridaMap.php; routes/web.php; resources/views/welcome.blade.php; resources/css/app.css; resources/js/app.js; vite.config.js; package.json; package-lock.json; build.sh; composer.json; composer.lock; README.md; tests/Unit/GraphSearchTest.php; tests/Feature/ImportMeridaMapTest.php; tests/Feature/MapTest.php; tests/Feature/CompiledAssetsTest.php; tests/Fixtures/merida/extract.json; tests/Frontend/app.test.js.
- Archivos generados por preparación Boost: AGENTS.md; CLAUDE.md; boost.json; opencode.json; .mcp.json; .agents/skills/; .claude/; .junie/. No contienen implementación cartográfica remota.
- Pruebas: 78 PHP correctas de 79 (589 aserciones; 1 omitida por build/tag), 4 JavaScript correctas. Sintaxis JS/Bash, Pint y diff-check correctos.
- Pendientes: tag v0.1.0 sobre commit que contenga realmente la implementación; ejecución de build.sh; obtener/importar extracto municipal real; comprobar navegador en localhost con Wi-Fi apagado y caché vacía; medir rendimiento municipal real.
- Limitaciones: fuentes públicas de extracción devolvieron HTTP 406/timeout. No se proporcionan fixtures como datos reales. Las pruebas HTTP/DOM simulado no demuestran por sí solas funcionamiento offline visual.
- Git: no se alteró historial; HEAD inicial e0608efae90c664137128fdfc9fb2dfcfa71100f no identifica estos cambios.
- Fecha: 2026-11-20.
