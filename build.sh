#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"

for tool in node npm git; do
    command -v "$tool" >/dev/null || { echo "Falta $tool en PATH." >&2; exit 1; }
done

version="$(node -p "JSON.parse(require('fs').readFileSync('package.json','utf8')).version")"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'package.json debe declarar una versión X.Y.Z.' >&2; exit 1; }
tag="v$version"
head="$(git rev-parse HEAD)"
tag_commit="$(git rev-parse --verify "refs/tags/$tag^{commit}" 2>/dev/null)" || {
    echo "Falta el tag $tag. HEAD actual: $head. No se generaron artefactos." >&2
    exit 1
}
[[ "$tag_commit" == "$head" ]] || { echo "$tag no apunta a HEAD ($head)." >&2; exit 1; }

release_paths=(app bootstrap config routes resources public/index.php tests vite.config.js package.json package-lock.json composer.json composer.lock build.sh)
git diff --quiet HEAD -- "${release_paths[@]}" || { echo 'Hay cambios de aplicación que no están identificados por el tag.' >&2; exit 1; }
[[ -z "$(git ls-files --others --exclude-standard -- "${release_paths[@]}")" ]] || { echo 'Hay archivos de aplicación sin seguimiento que no están identificados por el tag.' >&2; exit 1; }
[[ -f node_modules/vite/bin/vite.js ]] || { echo 'Instala las dependencias durante la preparación: npm ci.' >&2; exit 1; }
[[ ! -f public/hot ]] || { echo 'Detén Vite y elimina únicamente su archivo public/hot obsoleto antes de construir.' >&2; exit 1; }

echo "Compilando recursos locales $tag ($head)…"
npm run build
node --input-type=commonjs - "$version" "$head" <<'NODE'
const fs = require('node:fs');
const crypto = require('node:crypto');
fs.writeFileSync('public/build/release.json', JSON.stringify({
    version: process.argv[2],
    commit: process.argv[3],
    built_at: new Date().toISOString(),
    manifest_sha256: crypto.createHash('sha256').update(fs.readFileSync('public/build/manifest.json')).digest('hex'),
}, null, 2));
NODE
