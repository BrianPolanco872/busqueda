<?php

namespace App\Console\Commands;

use App\Services\MapGeometry;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportMeridaMap extends Command
{
    protected $signature = 'mapa:importar {archivo : Archivo JSON local de Overpass} {--boundary= : ID relación municipio}';

    protected $description = 'Valida e importa calles y parques de Mérida sin acceder a la red';

    /** Public street types for this academic graph, not a transport/routing profile. */
    private const Highways = [
        'motorway', 'motorway_link', 'trunk', 'trunk_link', 'primary', 'primary_link',
        'secondary', 'secondary_link', 'tertiary', 'tertiary_link', 'unclassified',
        'residential', 'living_street', 'service', 'road',
    ];

    public function handle(): int
    {
        $lock = null;
        $temporary = null;
        $disk = Storage::disk('local');

        try {
            $boundaryId = $this->identifier($this->option('boundary'), 'La opción --boundary es obligatoria y debe ser un ID positivo.');
            $path = $this->argument('archivo');
            if (! is_string($path) || str_contains($path, '://') || str_starts_with(str_replace('\\', '/', $path), '//')
                || ! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('El archivo debe existir y ser un JSON local legible; no se admiten URLs.');
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('No se pudo leer el archivo local.');
            }

            $extract = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            [$nodes, $ways, $relations] = $this->elements($extract);
            $boundary = $relations[$boundaryId] ?? throw new RuntimeException('No existe la relación municipal solicitada.');
            $this->validateMunicipality($boundary);
            $geometry = $this->relationGeometry($boundary, $ways, $nodes);
            $bounds = MapGeometry::bounds($geometry);
            if ($bounds[0] < -90.1 || $bounds[2] > -88.8 || $bounds[1] < 20.4 || $bounds[3] > 21.5) {
                throw new RuntimeException('El límite municipal no está en la zona de Yucatán.');
            }

            [$graph, $streets] = $this->streets($ways, $nodes, $geometry);
            $parks = $this->parks($nodes, $ways, $relations, $geometry);
            $sourceDate = $extract['osm3s']['timestamp_osm_base'] ?? null;
            if ($sourceDate !== null && (! is_string($sourceDate)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $sourceDate))) {
                throw new RuntimeException('La fecha OSM de la fuente no es válida.');
            }
            if ($sourceDate !== null) {
                new DateTimeImmutable($sourceDate);
                $errors = DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                    throw new RuntimeException('La fecha OSM de la fuente no es válida.');
                }
            }

            $version = hash('sha256', $contents."\0".bin2hex(random_bytes(16)));
            $metadata = [
                'schema' => 1, 'version' => $version, 'imported_at' => now()->toIso8601String(),
                'source_date' => $sourceDate, 'source' => 'OpenStreetMap', 'license' => 'ODbL-1.0',
                'municipality' => 'Mérida', 'state' => 'Yucatán', 'boundary_id' => $boundaryId,
                'bounds' => $bounds,
                'counts' => ['nodes' => count($graph['nodes']), 'edges' => count($graph['edges']), 'streets' => count($streets), 'parks' => count($parks)],
            ];
            $files = [
                'graph.json' => $graph,
                'streets.geojson' => ['type' => 'FeatureCollection', 'features' => $streets],
                'parks.geojson' => ['type' => 'FeatureCollection', 'features' => $parks],
                'boundary.geojson' => [
                    'type' => 'Feature', 'geometry' => $geometry,
                    'properties' => ['name' => 'Mérida', 'state' => 'Yucatán', 'boundary_id' => $boundaryId, 'admin_level' => '6', 'source' => 'OpenStreetMap', 'license' => 'ODbL-1.0'],
                    'bbox' => $bounds,
                ],
                'metadata.json' => $metadata,
            ];

            if (! $disk->makeDirectory('merida')) {
                throw new RuntimeException('No se pudo crear el directorio de datos.');
            }
            $lock = @fopen($disk->path('merida/import.lock'), 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Hay otra importación activa o no se pudo bloquear el almacenamiento.');
            }

            $previous = null;
            if ($disk->exists('merida/active.json')) {
                $active = json_decode($disk->get('merida/active.json'), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($active) || ! is_string($active['version'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $active['version'])) {
                    throw new RuntimeException('El indicador de versión activa está dañado; no se reemplazó.');
                }
                $previous = $active['version'];
            }

            foreach ($files as $name => $data) {
                if (! $disk->put("merida/versions/{$version}/{$name}", json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))) {
                    throw new RuntimeException("No se pudo escribir {$name}; se conserva la versión activa.");
                }
            }

            $temporary = "merida/active.{$version}.tmp";
            if (! $disk->put($temporary, json_encode(['version' => $version, 'previous_version' => $previous], JSON_THROW_ON_ERROR))
                || ! @rename($disk->path($temporary), $disk->path('merida/active.json'))) {
                throw new RuntimeException('No se pudo activar el snapshot de forma atómica; se conserva la versión anterior.');
            }
            $temporary = null;

            $this->info("Importación activada: {$version}. OpenStreetMap © colaboradores, ODbL-1.0.");
            $this->line('Conteos: '.json_encode($metadata['counts'], JSON_UNESCAPED_UNICODE));
            $this->line('Cobertura municipal: '.json_encode($bounds).'; memoria máxima: '.round(memory_get_peak_usage(true) / 1048576, 1).' MiB.');
            if ($sourceDate === null) {
                $this->warn('Fuente sin fecha OSM: source_date=null. imported_at indica solamente la fecha de importación.');
            } else {
                $this->line("Fecha de la fuente OSM: {$sourceDate}.");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('No se importó el mapa; se conserva la versión activa. '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($temporary !== null) {
                $disk->delete($temporary);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return array{0:array, 1:array, 2:array} */
    private function elements(mixed $extract): array
    {
        if (! is_array($extract) || ! is_array($extract['elements'] ?? null) || ! array_is_list($extract['elements']) || $extract['elements'] === []) {
            throw new RuntimeException('Se requiere un extracto Overpass con elements no vacío.');
        }
        if (! empty($extract['remark'])) {
            throw new RuntimeException('La fuente Overpass informa un error o resultado parcial. Descarga un extracto completo antes de importar.');
        }

        $indexed = ['node' => [], 'way' => [], 'relation' => []];
        foreach ($extract['elements'] as $element) {
            if (! is_array($element) || ! is_string($element['type'] ?? null) || ! array_key_exists($element['type'], $indexed)) {
                throw new RuntimeException('Tipo de elemento OSM inválido.');
            }
            $id = $this->identifier($element['id'] ?? null, 'ID OSM inválido.');
            $type = $element['type'];
            if (isset($indexed[$type][$id])) {
                throw new RuntimeException("Elemento OSM duplicado: {$type}/{$id}.");
            }
            $tags = $element['tags'] ?? [];
            if (! is_array($tags)) {
                throw new RuntimeException("Etiquetas inválidas: {$type}/{$id}.");
            }
            foreach ($tags as $tag => $value) {
                if (! is_string($tag) || ! is_string($value)) {
                    throw new RuntimeException("Etiquetas inválidas: {$type}/{$id}.");
                }
            }
            $element['id'] = $id;
            $element['tags'] = $tags;
            if ($type === 'node') {
                foreach (['lon' => 180, 'lat' => 90] as $coordinate => $limit) {
                    $value = $element[$coordinate] ?? null;
                    if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || abs($value) > $limit) {
                        throw new RuntimeException("Coordenadas inválidas en node/{$id}.");
                    }
                }
                $element['point'] = [(float) $element['lon'], (float) $element['lat']];
            } elseif ($type === 'way') {
                if (! is_array($element['nodes'] ?? null) || ! array_is_list($element['nodes']) || count($element['nodes']) < 2) {
                    throw new RuntimeException("Nodos incompletos en way/{$id}.");
                }
                $element['nodes'] = array_map(fn (mixed $node): string => $this->identifier($node, "Referencia inválida en way/{$id}."), $element['nodes']);
            } elseif (! is_array($element['members'] ?? null) || ! array_is_list($element['members'])) {
                throw new RuntimeException("Miembros incompletos en relation/{$id}.");
            }
            $indexed[$type][$id] = $element;
        }

        foreach ($indexed['way'] as $way) {
            foreach ($way['nodes'] as $reference) {
                if (! isset($indexed['node'][$reference])) {
                    throw new RuntimeException("Extracto incompleto: falta node/{$reference} en way/{$way['id']}.");
                }
            }
        }
        foreach ($indexed['relation'] as $relation) {
            foreach ($relation['members'] as $member) {
                if (! is_array($member) || ! is_string($member['type'] ?? null) || ! isset($indexed[$member['type']]) || ! is_string($member['role'] ?? null)) {
                    throw new RuntimeException("Miembro inválido en relation/{$relation['id']}.");
                }
                $reference = $this->identifier($member['ref'] ?? null, 'Referencia de relación inválida.');
                if (! isset($indexed[$member['type']][$reference])) {
                    throw new RuntimeException("Extracto incompleto: falta {$member['type']}/{$reference} en relation/{$relation['id']}.");
                }
            }
        }

        return [$indexed['node'], $indexed['way'], $indexed['relation']];
    }

    private function identifier(mixed $id, string $message): string
    {
        if ((! is_int($id) && ! is_string($id)) || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)) {
            throw new RuntimeException($message);
        }

        return (string) $id;
    }

    private function validateMunicipality(array $boundary): void
    {
        $tags = $boundary['tags'];
        if ((! in_array($tags['name'] ?? null, ['Mérida', 'Municipio de Mérida'], true)
            && ($tags['official_name'] ?? null) !== 'Municipio de Mérida') || ($tags['boundary'] ?? null) !== 'administrative') {
            throw new RuntimeException('La relación debe identificar exactamente el municipio administrativo Mérida.');
        }
        if (isset($tags['INEGI:MUNID']) && $tags['INEGI:MUNID'] !== '31050') {
            throw new RuntimeException('INEGI:MUNID no corresponde al municipio de Mérida (31050).');
        }
        if (($tags['admin_level'] ?? null) !== '6') {
            throw new RuntimeException('Se esperaba admin_level=6 para el municipio de México; nivel observado: '.($tags['admin_level'] ?? 'ausente').'. Verifique la fuente, no se asumió otro nivel.');
        }

        foreach (['is_in:state', 'addr:state', 'state'] as $key) {
            if (isset($tags[$key]) && ! in_array($tags[$key], ['Yucatán', 'Yucatan', 'MX-YUC'], true)) {
                throw new RuntimeException('La relación municipal declara un estado distinto de Yucatán.');
            }
        }
        if (isset($tags['is_in']) && ! preg_match('/(?:^|[,;])\s*Yucat[aá]n\s*(?:[,;]|$)/iu', $tags['is_in'])) {
            throw new RuntimeException('La etiqueta is_in no confirma Yucatán.');
        }
        foreach (['is_in:country', 'addr:country'] as $key) {
            if (isset($tags[$key]) && ! in_array($tags[$key], ['México', 'Mexico', 'MX'], true)) {
                throw new RuntimeException('La relación municipal declara un país distinto de México.');
            }
        }
    }

    private function relationGeometry(array $relation, array $ways, array $nodes): array
    {
        if (! in_array($relation['tags']['type'] ?? null, ['boundary', 'multipolygon'], true)) {
            throw new RuntimeException("relation/{$relation['id']} debe ser boundary o multipolygon.");
        }

        $members = ['outer' => [], 'inner' => []];
        foreach ($relation['members'] as $member) {
            if (in_array($member['role'], ['label', 'admin_centre', 'subarea'], true)) {
                continue;
            }
            $role = $member['role'] === '' ? 'outer' : $member['role'];
            if ($member['type'] !== 'way' || ! isset($members[$role])) {
                throw new RuntimeException("Miembro geométrico no admitido en relation/{$relation['id']}; se requieren ways outer/inner completos.");
            }
            if (isset($members[$role][(string) $member['ref']])) {
                throw new RuntimeException('Way de anillo duplicado.');
            }
            $members[$role][(string) $member['ref']] = $ways[(string) $member['ref']]['nodes'];
        }
        $outers = $this->assembleRings($members['outer'], $nodes);
        if ($outers === []) {
            throw new RuntimeException("No hay anillo exterior en relation/{$relation['id']}.");
        }
        $polygons = array_map(static fn (array $ring): array => [$ring], $outers);
        foreach ($this->assembleRings($members['inner'], $nodes) as $hole) {
            $owner = null;
            foreach ($outers as $index => $outer) {
                $outerGeometry = ['type' => 'Polygon', 'coordinates' => [$outer]];
                if (MapGeometry::contains($outerGeometry, $hole[0])) {
                    if ($owner !== null) {
                        throw new RuntimeException('Hueco con más de un anillo exterior posible.');
                    }
                    $owner = $index;
                }
            }
            if ($owner === null) {
                throw new RuntimeException('Hueco sin anillo exterior.');
            }
            foreach ($hole as $point) {
                if (! MapGeometry::contains(['type' => 'Polygon', 'coordinates' => [$outers[$owner]]], $point)) {
                    throw new RuntimeException('Hueco fuera de su anillo exterior.');
                }
            }
            $polygons[$owner][] = $hole;
        }

        return count($polygons) === 1
            ? ['type' => 'Polygon', 'coordinates' => $polygons[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $polygons];
    }

    /** @return list<list<array{0:float, 1:float}>> */
    private function assembleRings(array $parts, array $nodes): array
    {
        $rings = [];
        while ($parts !== []) {
            $key = array_key_first($parts);
            $ring = $parts[$key];
            unset($parts[$key]);
            while ($ring[0] !== $ring[array_key_last($ring)]) {
                $end = $ring[array_key_last($ring)];
                $matches = [];
                foreach ($parts as $id => $part) {
                    if ($part[0] === $end || $part[array_key_last($part)] === $end) {
                        $matches[$id] = $part[0] === $end ? $part : array_reverse($part);
                    }
                }
                if (count($matches) !== 1) {
                    throw new RuntimeException('Anillo abierto o ambiguo: faltan ways o los extremos no comparten ID OSM.');
                }
                $id = array_key_first($matches);
                array_push($ring, ...array_slice($matches[$id], 1));
                unset($parts[$id]);
            }
            if (count($ring) < 4 || count(array_unique(array_slice($ring, 0, -1))) !== count($ring) - 1) {
                throw new RuntimeException('Anillo degenerado o con nodos repetidos.');
            }
            $coordinates = array_map(static fn (string $id): array => $nodes[$id]['point'], $ring);
            MapGeometry::assertSimpleRing($coordinates);
            $area = 0.0;
            for ($i = 1, $count = count($coordinates); $i < $count; $i++) {
                $area += ($coordinates[$i - 1][0] - $coordinates[0][0]) * ($coordinates[$i][1] - $coordinates[0][1])
                    - ($coordinates[$i][0] - $coordinates[0][0]) * ($coordinates[$i - 1][1] - $coordinates[0][1]);
            }
            if (abs($area) < 1.0e-16) {
                throw new RuntimeException('Anillo sin superficie.');
            }
            $rings[] = $coordinates;
        }

        return $rings;
    }

    /** @return array{0:array, 1:list<array>} */
    private function streets(array $ways, array $nodes, array $boundary): array
    {
        $eligible = [];
        $uses = [];
        foreach ($ways as $id => $way) {
            $tags = $way['tags'];
            if (! in_array($tags['highway'] ?? null, self::Highways, true)
                || in_array($tags['access'] ?? null, ['no', 'private', 'customers', 'delivery'], true)
                || isset($tags['construction']) || isset($tags['proposed']) || ($tags['area'] ?? null) === 'yes') {
                continue;
            }
            $eligible[$id] = $way;
            foreach ($way['nodes'] as $node) {
                $uses[$node] = ($uses[$node] ?? 0) + 1;
            }
        }

        $graph = ['nodes' => [], 'edges' => [], 'adjacency' => []];
        $features = [];
        foreach ($eligible as $way) {
            $oneway = $this->oneway($way['tags']);
            $pieces = [];
            $current = [];
            for ($i = 1, $count = count($way['nodes']); $i < $count; $i++) {
                $from = $way['nodes'][$i - 1];
                $to = $way['nodes'][$i];
                $a = $nodes[$from]['point'];
                $b = $nodes[$to]['point'];
                if ($a === $b) {
                    throw new RuntimeException("Coordenadas consecutivas duplicadas en way/{$way['id']}.");
                }
                foreach (MapGeometry::clipSegment($boundary, $a, $b) as $partIndex => $part) {
                    $start = $part['start'] <= 1.0e-10 ? $from : "clip:{$way['id']}:{$i}:{$partIndex}:a";
                    $end = $part['end'] >= 1 - 1.0e-10 ? $to : "clip:{$way['id']}:{$i}:{$partIndex}:b";
                    if ($current !== [] && $current[array_key_last($current)]['id'] !== $start) {
                        $pieces[] = $current;
                        $current = [];
                    }
                    if ($current === []) {
                        $current[] = ['id' => $start, 'point' => $part['a']];
                    }
                    $current[] = ['id' => $end, 'point' => $part['b']];
                    if ($part['end'] < 1 - 1.0e-10) {
                        $pieces[] = $current;
                        $current = [];
                    }
                }
                if ($current !== [] && $current[array_key_last($current)]['id'] !== $to) {
                    $pieces[] = $current;
                    $current = [];
                }
            }
            if ($current !== []) {
                $pieces[] = $current;
            }

            $segmentIndex = 0;
            foreach ($pieces as $piece) {
                $start = 0;
                for ($i = 1, $count = count($piece); $i < $count; $i++) {
                    $id = $piece[$i]['id'];
                    $entrance = isset($nodes[$id]['tags']['entrance']) && $nodes[$id]['tags']['entrance'] !== 'no';
                    if ($i !== $count - 1 && ($uses[$id] ?? 0) < 2 && ! $entrance) {
                        continue;
                    }
                    $points = array_slice($piece, $start, $i - $start + 1);
                    $this->addSegment($graph, $features, $points, "way/{$way['id']}:".$segmentIndex++, $way['tags']['name'] ?? '', $oneway);
                    $start = $i;
                }
            }
        }
        if ($features === []) {
            throw new RuntimeException('El extracto no contiene calles públicas utilizables dentro del municipio.');
        }
        foreach ($graph['adjacency'] as &$edges) {
            sort($edges, SORT_STRING);
        }
        unset($edges);

        return [$graph, $features];
    }

    private function oneway(array $tags): int
    {
        return match ($tags['oneway'] ?? null) {
            'yes', '1', 'true' => 1,
            '-1' => -1,
            'no', '0', 'false' => 0,
            null => ($tags['junction'] ?? null) === 'roundabout' || in_array($tags['highway'] ?? '', ['motorway', 'motorway_link'], true) ? 1 : 0,
            default => throw new RuntimeException('Valor oneway no admitido: '.$tags['oneway'].'. No se asumió doble sentido.'),
        };
    }

    private function addSegment(array &$graph, array &$features, array $points, string $segment, string $name, int $oneway): void
    {
        $from = (string) $points[0]['id'];
        $to = (string) $points[array_key_last($points)]['id'];
        $coordinates = array_column($points, 'point');
        $distance = 0.0;
        for ($i = 1, $count = count($coordinates); $i < $count; $i++) {
            $distance += MapGeometry::distance($coordinates[$i - 1], $coordinates[$i]);
        }
        if ($distance <= 0) {
            throw new RuntimeException('Calle con longitud cero: '.$segment.'.');
        }
        foreach ([$points[0], $points[array_key_last($points)]] as $endpoint) {
            $graph['nodes'][$endpoint['id']] = $endpoint['point'];
            $graph['adjacency'][$endpoint['id']] ??= [];
        }
        foreach (['f' => [$from, $to], 'r' => [$to, $from]] as $direction => [$a, $b]) {
            if (($oneway === 1 && $direction === 'r') || ($oneway === -1 && $direction === 'f')) {
                continue;
            }
            $id = "{$segment}:{$direction}";
            $graph['edges'][$id] = ['from' => $a, 'to' => $b, 'distance_m' => $distance, 'name' => $name, 'segment' => $segment];
            $graph['adjacency'][$a][] = $id;
        }
        $geometry = ['type' => 'LineString', 'coordinates' => $coordinates];
        $features[] = ['type' => 'Feature', 'id' => $segment, 'properties' => ['id' => $segment, 'name' => $name, 'from' => $from, 'to' => $to, 'oneway' => $oneway], 'geometry' => $geometry, 'bbox' => MapGeometry::bounds($geometry)];
    }

    /** @return list<array> */
    private function parks(array $nodes, array $ways, array $relations, array $boundary): array
    {
        $features = [];
        $memberWays = [];
        foreach ($relations as $relation) {
            if (($relation['tags']['leisure'] ?? null) !== 'park') {
                continue;
            }
            $geometry = $this->relationGeometry($relation, $ways, $nodes);
            $ringNodes = [];
            foreach ($relation['members'] as $member) {
                if ($member['type'] === 'way' && in_array($member['role'], ['', 'outer', 'inner'], true)) {
                    $memberWays[(string) $member['ref']] = true;
                    array_push($ringNodes, ...$ways[(string) $member['ref']]['nodes']);
                }
            }
            $feature = $this->parkFeature('relation/'.$relation['id'], $relation['tags'], $geometry, $ringNodes, $nodes, $boundary);
            if ($feature !== null) {
                $features[] = $feature;
            }
        }
        foreach ($ways as $way) {
            if (($way['tags']['leisure'] ?? null) !== 'park' || isset($memberWays[$way['id']])) {
                continue;
            }
            if ($way['nodes'][0] !== $way['nodes'][array_key_last($way['nodes'])]) {
                throw new RuntimeException('Parque way/'.$way['id'].' sin anillo cerrado.');
            }
            $rings = $this->assembleRings([$way['nodes']], $nodes);
            $feature = $this->parkFeature('way/'.$way['id'], $way['tags'], ['type' => 'Polygon', 'coordinates' => $rings], $way['nodes'], $nodes, $boundary);
            if ($feature !== null) {
                $features[] = $feature;
            }
        }
        foreach ($nodes as $node) {
            if (($node['tags']['leisure'] ?? null) === 'park' && MapGeometry::contains($boundary, $node['point'])) {
                $id = 'node/'.$node['id'];
                $point = $node['point'];
                $approximate = ! isset($node['tags']['entrance']) || $node['tags']['entrance'] === 'no';
                $features[] = ['type' => 'Feature', 'id' => $id, 'properties' => ['id' => $id, 'name' => $node['tags']['name'] ?? '', 'connection_point' => $point, 'approximate' => $approximate], 'geometry' => ['type' => 'Point', 'coordinates' => $point], 'bbox' => [$point[0], $point[1], $point[0], $point[1]]];
            }
        }

        return $features;
    }

    private function parkFeature(string $id, array $tags, array $geometry, array $ringNodes, array $nodes, array $boundary): ?array
    {
        $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
        $connection = null;
        foreach ($polygons as $polygon) {
            $ring = $polygon[0];
            for ($i = 1, $count = count($ring); $i < $count; $i++) {
                foreach (MapGeometry::clipSegment($boundary, $ring[$i - 1], $ring[$i]) as $part) {
                    $middle = [($part['a'][0] + $part['b'][0]) / 2, ($part['a'][1] + $part['b'][1]) / 2];
                    foreach ([$part['a'], $part['b'], $middle] as $point) {
                        if (MapGeometry::contains($boundary, $point) && MapGeometry::contains($geometry, $point)) {
                            $connection = $point;
                            break 4;
                        }
                    }
                }
            }
        }
        if ($connection === null) {
            $municipalPolygons = $boundary['type'] === 'Polygon' ? [$boundary['coordinates']] : $boundary['coordinates'];
            foreach ($municipalPolygons as $polygon) {
                foreach ($polygon[0] as $point) {
                    if (MapGeometry::contains($boundary, $point) && MapGeometry::contains($geometry, $point)) {
                        $connection = $point;
                        break 2;
                    }
                }
            }
        }
        if ($connection === null) {
            return null;
        }
        $approximate = true;
        foreach (array_unique($ringNodes) as $nodeId) {
            $node = $nodes[$nodeId];
            if (isset($node['tags']['entrance']) && $node['tags']['entrance'] !== 'no' && MapGeometry::contains($boundary, $node['point'])) {
                $connection = $node['point'];
                $approximate = false;
                break;
            }
        }

        return ['type' => 'Feature', 'id' => $id, 'properties' => ['id' => $id, 'name' => $tags['name'] ?? '', 'connection_point' => $connection, 'approximate' => $approximate], 'geometry' => $geometry, 'bbox' => MapGeometry::bounds($geometry)];
    }
}
