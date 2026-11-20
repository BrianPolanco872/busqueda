<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use UnexpectedValueException;

class MeridaMapData
{
    public const MAX_SNAP_METERS = 250;

    private ?array $snapshot = null;

    private array $files = [];

    public function metadata(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }
        $active = $this->readJson('merida/active.json');
        $version = $active['version'] ?? '';
        if (! is_string($version) || ! preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $version)) {
            throw new UnexpectedValueException('La versión del mapa local no es válida. Vuelve a importar los datos.');
        }
        $metadata = $this->readJson("merida/versions/{$version}/metadata.json");
        $bounds = $metadata['bounds'] ?? null;
        if (($metadata['schema'] ?? null) !== 1 || ($metadata['version'] ?? null) !== $version
            || ($metadata['municipality'] ?? null) !== 'Mérida' || ($metadata['state'] ?? null) !== 'Yucatán'
            || ! is_array($bounds) || ! array_is_list($bounds) || count($bounds) !== 4
            || ! $this->validPoint([$bounds[0], $bounds[1]]) || ! $this->validPoint([$bounds[2], $bounds[3]])
            || $bounds[0] >= $bounds[2] || $bounds[1] >= $bounds[3]) {
            throw new UnexpectedValueException('El conjunto local no corresponde al municipio de Mérida o está dañado.');
        }

        return $this->snapshot = $metadata;
    }

    public function graph(): array
    {
        $graph = $this->file('graph.json');
        if (! isset($this->files['graph.validated'])) {
            foreach (['nodes', 'edges', 'adjacency'] as $key) {
                if (! isset($graph[$key]) || ! is_array($graph[$key])) {
                    throw new UnexpectedValueException('El grafo local está incompleto. Vuelve a importar los datos.');
                }
            }
            foreach ($graph['nodes'] as $id => $point) {
                if (! $this->validPoint($point) || ! isset($graph['adjacency'][$id]) || ! is_array($graph['adjacency'][$id])) {
                    throw new UnexpectedValueException('El grafo contiene coordenadas inválidas o nodos sin índice de conexiones.');
                }
            }
            $indexed = [];
            foreach ($graph['adjacency'] as $node => $edges) {
                if (! isset($graph['nodes'][$node]) || ! is_array($edges)) {
                    throw new UnexpectedValueException('Las conexiones del grafo están dañadas.');
                }
                foreach ($edges as $id) {
                    $edge = $graph['edges'][$id] ?? null;
                    if (! is_array($edge) || ! is_string($edge['from'] ?? null) || ! is_string($edge['to'] ?? null)
                        || $edge['from'] !== (string) $node
                        || ! isset($graph['nodes'][$edge['to'] ?? ''])
                        || ! is_numeric($edge['distance_m'] ?? null) || ! is_finite((float) $edge['distance_m'])
                        || $edge['distance_m'] < 0 || ! is_string($edge['segment'] ?? null)) {
                        throw new UnexpectedValueException('Una conexión dirigida del grafo no es válida.');
                    }
                    if (isset($indexed[$id])) {
                        throw new UnexpectedValueException('El grafo contiene conexiones duplicadas en su índice.');
                    }
                    $indexed[$id] = true;
                }
            }
            if (count($indexed) !== count($graph['edges']) || $graph['nodes'] === []) {
                throw new UnexpectedValueException('El índice de conexiones del grafo está incompleto.');
            }
            $this->files['graph.validated'] = true;
        }

        return $graph;
    }

    public function parks(string $query): array
    {
        $query = Str::lower(Str::ascii(trim($query)));
        $matches = [];
        foreach ($this->features('parks.geojson') as $park) {
            $properties = $park['properties'];
            if ($query !== '' && ! str_contains(Str::lower(Str::ascii($properties['name'])), $query)) {
                continue;
            }
            $matches[] = [
                'id' => (string) $park['id'], 'name' => $properties['name'],
                'coordinates' => $properties['connection_point'], 'approximate' => $properties['approximate'],
            ];
        }
        usort($matches, fn (array $a, array $b): int => strnatcasecmp(Str::ascii($a['name']), Str::ascii($b['name'])) ?: strcmp($a['id'], $b['id']));

        return ['version' => $this->metadata()['version'], 'parks' => array_slice($matches, 0, 50), 'total' => count($matches)];
    }

    public function map(array $bounds): array
    {
        $visible = fn (array $feature): bool => $this->intersects($feature['bbox'] ?? MapGeometry::bounds($feature['geometry']), $bounds);

        return [
            'version' => $this->metadata()['version'], 'boundary' => $this->boundary(),
            'streets' => ['type' => 'FeatureCollection', 'features' => array_values(array_filter($this->features('streets.geojson'), $visible))],
            'parks' => ['type' => 'FeatureCollection', 'features' => array_values(array_filter($this->features('parks.geojson'), $visible))],
        ];
    }

    public function connect(float $longitude, float $latitude): array
    {
        $point = [$longitude, $latitude];
        if (! MapGeometry::contains($this->boundary()['geometry'], $point)) {
            throw ValidationException::withMessages(['latitude' => 'Selecciona un punto dentro del municipio de Mérida.']);
        }
        $nearestId = null;
        $nearestDistance = INF;
        $graph = $this->graph();
        foreach ($graph['nodes'] as $id => $coordinates) {
            $distance = MapGeometry::distance($point, $coordinates);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestId = (string) $id;
            }
        }
        if ($nearestId === null || $nearestDistance > self::MAX_SNAP_METERS) {
            throw ValidationException::withMessages(['latitude' => 'No hay un nodo de la red a menos de 250 m. Selecciona un punto más cercano a una intersección.']);
        }

        return [
            'node_id' => $nearestId, 'coordinates' => $graph['nodes'][$nearestId], 'selected' => $point,
            'offset_m' => round($nearestDistance, 1), 'approximate' => $nearestDistance > 1,
        ];
    }

    public function destination(string $parkId): array
    {
        foreach ($this->features('parks.geojson') as $park) {
            if ((string) $park['id'] !== $parkId) {
                continue;
            }
            $point = $park['properties']['connection_point'];
            try {
                $connection = $this->connect((float) $point[0], (float) $point[1]);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['park_id' => 'Este parque no tiene una conexión a la red dentro de 250 m. Elige otro parque.']);
            }
            $connection['approximate'] = $connection['approximate'] || $park['properties']['approximate'];

            return [...$connection, 'park' => ['id' => $parkId, 'name' => $park['properties']['name']]];
        }

        throw ValidationException::withMessages(['park_id' => 'El parque seleccionado no existe en los datos locales.']);
    }

    public function network(array $results): array
    {
        $graph = $this->graph();
        $nodes = [];
        $edgeIds = [];
        foreach ($results as $result) {
            foreach ($result['visited_nodes'] as $id) {
                $nodes[$id] = $graph['nodes'][$id];
            }
            foreach ($result['route_edges'] as $id) {
                $edgeIds[$id] = true;
            }
            foreach ($result['trace'] as [$type, $id]) {
                if ($type === 'edge') {
                    $edgeIds[$id] = true;
                }
            }
        }
        $segments = [];
        foreach ($this->features('streets.geojson') as $feature) {
            $segments[(string) $feature['id']] = $feature;
        }
        $edges = [];
        foreach ($edgeIds as $id => $_) {
            $edge = $graph['edges'][$id];
            $segment = $segments[$edge['segment']] ?? null;
            if ($segment === null) {
                throw new UnexpectedValueException('Falta la geometría de un tramo del grafo local.');
            }
            $coordinates = $segment['geometry']['coordinates'];
            if ((string) $segment['properties']['from'] !== $edge['from']) {
                $coordinates = array_reverse($coordinates);
            }
            $edges[$id] = [...$edge, 'geometry' => $coordinates];
            $nodes[$edge['from']] = $graph['nodes'][$edge['from']];
            $nodes[$edge['to']] = $graph['nodes'][$edge['to']];
        }

        return ['nodes' => (object) $nodes, 'edges' => (object) $edges];
    }

    private function boundary(): array
    {
        $boundary = $this->file('boundary.geojson');
        if (($boundary['type'] ?? null) !== 'Feature'
            || ! in_array($boundary['geometry']['type'] ?? '', ['Polygon', 'MultiPolygon'], true)
            || ! $this->validGeometry($boundary['geometry'] ?? null)) {
            throw new UnexpectedValueException('El límite municipal local está dañado.');
        }

        return $boundary;
    }

    private function features(string $name): array
    {
        $collection = $this->file($name);
        if (($collection['type'] ?? null) !== 'FeatureCollection' || ! is_array($collection['features'] ?? null)) {
            throw new UnexpectedValueException('La cartografía local está incompleta.');
        }
        foreach ($collection['features'] as $feature) {
            if (! is_array($feature) || ($feature['type'] ?? null) !== 'Feature'
                || ! isset($feature['id'], $feature['properties'], $feature['geometry'])
                || ! is_array($feature['properties']) || ! $this->validGeometry($feature['geometry'])) {
                throw new UnexpectedValueException('La cartografía contiene elementos inválidos.');
            }
            if ($name === 'streets.geojson' && (($feature['geometry']['type'] ?? null) !== 'LineString'
                || count($feature['geometry']['coordinates']) < 2
                || ! isset($feature['properties']['from'], $feature['properties']['to'])
                || ! is_string($feature['properties']['name'] ?? null))) {
                throw new UnexpectedValueException('La geometría de calles está dañada.');
            }
            if ($name === 'parks.geojson' && (! in_array($feature['geometry']['type'], ['Point', 'Polygon', 'MultiPolygon'], true)
                || ! is_string($feature['properties']['name'] ?? null)
                || ! $this->validPoint($feature['properties']['connection_point'] ?? null)
                || ! is_bool($feature['properties']['approximate'] ?? null))) {
                throw new UnexpectedValueException('El catálogo de parques está dañado.');
            }
        }

        return $collection['features'];
    }

    private function file(string $name): array
    {
        $version = $this->metadata()['version'];

        return $this->files[$name] ??= $this->readJson("merida/versions/{$version}/{$name}");
    }

    private function readJson(string $path): array
    {
        $contents = Storage::disk('local')->get($path);
        if ($contents === null) {
            throw new UnexpectedValueException('No hay datos locales disponibles. Ejecuta mapa:importar antes de buscar rutas.');
        }
        try {
            $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Los datos locales están dañados. Vuelve a importar el extracto.', previous: $exception);
        }
        if (! is_array($value)) {
            throw new UnexpectedValueException('El formato de los datos locales no es válido.');
        }

        return $value;
    }

    private function validPoint(mixed $point): bool
    {
        return is_array($point) && count($point) === 2
            && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)
            && is_finite((float) $point[0]) && is_finite((float) $point[1])
            && abs((float) $point[0]) <= 180 && abs((float) $point[1]) <= 90;
    }

    private function validGeometry(mixed $geometry): bool
    {
        if (! is_array($geometry)) {
            return false;
        }
        $depth = match ($geometry['type'] ?? '') {
            'Point' => 0, 'LineString' => 1, 'Polygon' => 2, 'MultiPolygon' => 3, default => -1,
        };

        return $depth >= 0 && $this->validCoordinates($geometry['coordinates'] ?? null, $depth, $depth >= 2);
    }

    private function validCoordinates(mixed $coordinates, int $depth, bool $rings): bool
    {
        if ($depth === 0) {
            return $this->validPoint($coordinates);
        }
        $minimum = $depth === 1 ? ($rings ? 4 : 2) : 1;
        if (! is_array($coordinates) || ! array_is_list($coordinates) || count($coordinates) < $minimum) {
            return false;
        }
        if ($depth === 1 && $rings && $coordinates[0] != $coordinates[array_key_last($coordinates)]) {
            return false;
        }
        foreach ($coordinates as $child) {
            if (! $this->validCoordinates($child, $depth - 1, $rings)) {
                return false;
            }
        }

        return true;
    }

    private function intersects(array $a, array $b): bool
    {
        return $a[0] <= $b[2] && $a[2] >= $b[0] && $a[1] <= $b[3] && $a[3] >= $b[1];
    }
}
