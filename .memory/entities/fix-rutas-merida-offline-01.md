---
entity: fix-rutas-merida-offline-01
type: fix
---

- Objetivo: Implementar la preparación y el código de Home con mapa local de Mérida, importación OSM y visualización BFS/DFS conforme al plan aprobado, hasta el límite previo al tag.
- Estado: PARCIAL — código aplicado, assets nuevos construidos desde v0.1.0 y pruebas aprobadas; falta certificar la entrega offline con datos reales.
- Archivos tocados: app/Http/Controllers/MapController.php; app/Services/GraphSearch.php; app/Services/MeridaMapData.php; app/Services/MapGeometry.php; app/Console/Commands/ImportMeridaMap.php; routes/web.php; resources/views/welcome.blade.php; resources/css/app.css; resources/js/app.js; vite.config.js; package.json; package-lock.json; build.sh; composer.json; composer.lock; README.md; tests/Unit/GraphSearchTest.php; tests/Feature/ImportMeridaMapTest.php; tests/Feature/MapTest.php; tests/Feature/CompiledAssetsTest.php; tests/Fixtures/merida/extract.json; tests/Frontend/app.test.js.
- Archivos generados por preparación Boost: AGENTS.md; CLAUDE.md; boost.json; opencode.json; .mcp.json; .agents/skills/; .claude/; .junie/. No contienen implementación cartográfica remota.
- Pruebas actuales: 79 PHP correctas (619 aserciones, cero omisiones), 4 JavaScript correctas. CompiledAssetsTest pasó con 30 aserciones. Smoke de assets reales en Edge con perfil nuevo, caché limpia y solicitudes externas bloqueadas correcto; datos ausentes explícitos.
- Artefactos: public/build/manifest.json, public/build/release.json y public/build/assets/* generados por build.sh desde v0.1.0, commit 82524affb64b6ec853461b8a1102c665274c42d0.
- Pendientes: obtener/importar extracto municipal real; comprobar búsqueda de parque real, origen y BFS/DFS en localhost con Wi-Fi físicamente apagado y caché vacía; medir rendimiento municipal real.
- Limitaciones actuales: dos timeouts y HTTP 502 de saturación en proveedores. No se proporcionan fixtures como datos reales. El smoke de assets/estado vacío no demuestra el flujo de rutas con datos reales ni la desconexión física Wi-Fi.
- Git actual: el usuario preparó el tag; se verificó v0.1.0=HEAD=82524affb64b6ec853461b8a1102c665274c42d0 antes del build, sin cambios de fuentes. El agente no alteró historial.
- Documentación actualizada: README detalla Overpass JSON completo, almacenamiento en storage/app/private/merida-source/merida.osm.json y comando PowerShell con --boundary=5961522.
- Fecha: 2026-11-20.
