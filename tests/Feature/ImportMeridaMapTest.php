<?php

namespace Tests\Feature;

use App\Services\MapGeometry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportMeridaMapTest extends TestCase
{
    public function test_imports_the_frozen_contract_without_network_and_keeps_the_previous_snapshot(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(12, 0));

        $this->importFixture();

        $first = $this->active();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first['version']);
        $this->assertNull($first['previous_version']);
        $metadata = $this->snapshot('metadata.json');
        $this->assertSame(1, $metadata['schema']);
        $this->assertSame($first['version'], $metadata['version']);
        $this->assertSame('2026-01-02T03:04:05Z', $metadata['source_date']);
        $this->assertStringStartsWith('2026-11-20T12:00:00', $metadata['imported_at']);
        $this->assertSame('OpenStreetMap', $metadata['source']);
        $this->assertSame('ODbL-1.0', $metadata['license']);
        $this->assertSame('Mérida', $metadata['municipality']);
        $this->assertSame('Yucatán', $metadata['state']);
        $this->assertSame('9000', $metadata['boundary_id']);
        $this->assertSame([-89.64, 20.96, -89.6, 21.0], $metadata['bounds']);
        $this->assertSame(['nodes' => 17, 'edges' => 23, 'streets' => 13, 'parks' => 3], $metadata['counts']);
        $graph = $this->snapshot('graph.json');
        $this->assertSame(['nodes', 'edges', 'adjacency'], array_keys($graph));
        $streets = array_column($this->snapshot('streets.geojson')['features'], null, 'id');
        foreach ($graph['edges'] as $id => $edge) {
            $this->assertSame(['from', 'to', 'distance_m', 'name', 'segment'], array_keys($edge));
            $this->assertIsString($edge['from']);
            $this->assertIsString($edge['to']);
            $this->assertIsFloat($edge['distance_m']);
            $this->assertArrayHasKey($edge['from'], $graph['nodes']);
            $this->assertArrayHasKey($edge['to'], $graph['nodes']);
            $this->assertArrayHasKey($edge['segment'], $streets);
            $this->assertContains($id, $graph['adjacency'][$edge['from']]);
        }
        $this->assertSame(['way/100:0:r', 'way/101:0:f', 'way/112:0:r'], $graph['adjacency']['4']);
        $this->assertSame([], $graph['adjacency']['6']);

        $this->importFixture();

        $second = $this->active();
        $this->assertNotSame($first['version'], $second['version']);
        $this->assertSame($first['version'], $second['previous_version']);
        Storage::disk('local')->assertExists('merida/versions/'.$first['version'].'/graph.json');
        Http::assertNothingSent();
    }

    public function test_compacts_curve_shape_nodes_but_preserves_the_full_length_parallel_edges_and_disconnected_crossings(): void
    {
        Storage::fake('local');

        $this->importFixture();

        $graph = $this->snapshot('graph.json');
        $this->assertArrayNotHasKey('2', $graph['nodes']);
        $this->assertSame($graph['nodes']['4'], $graph['nodes']['17']);
        $this->assertSame(['way/111:0:f'], $graph['adjacency']['17']);
        $this->assertSame('4', $graph['edges']['way/100:0:f']['to']);
        $this->assertSame('4', $graph['edges']['way/112:0:f']['to']);
        $this->assertGreaterThan(3700, $graph['edges']['way/103:0:f']['distance_m']);
        $this->assertLessThan(3900, $graph['edges']['way/103:0:f']['distance_m']);
        $this->assertSame($graph['edges']['way/103:0:f']['distance_m'], $graph['edges']['way/103:0:r']['distance_m']);
        $streets = array_column($this->snapshot('streets.geojson')['features'], null, 'id');
        $curve = $streets['way/103:0'];
        $this->assertSame([[-89.635, 20.97], [-89.638, 20.985], [-89.625, 20.97]], $curve['geometry']['coordinates']);
        $this->assertSame(['id' => 'way/103:0', 'name' => 'Curva de prueba', 'from' => '1', 'to' => '3', 'oneway' => 0], $curve['properties']);
        $this->assertSame([-89.638, 20.97, -89.625, 20.985], $curve['bbox']);
        $this->assertArrayNotHasKey('way/109:0', $streets);
        $this->assertEqualsWithDelta(111195.0802335, MapGeometry::distance([0, 0], [1, 0]), 0.001);
    }

    public function test_clips_crossing_streets_even_with_both_endpoints_outside_and_never_bridges_a_hole(): void
    {
        Storage::fake('local');

        $this->importFixture();

        $streets = array_column($this->snapshot('streets.geojson')['features'], null, 'id');
        $crossing = $streets['way/108:0'];
        $this->assertSame([[-89.64, 20.98], [-89.6, 20.98]], $crossing['geometry']['coordinates']);
        $this->assertSame([[-89.62, 20.99], [-89.613, 20.99]], $streets['way/110:0']['geometry']['coordinates']);
        $this->assertSame([[-89.607, 20.99], [-89.602, 20.99]], $streets['way/110:1']['geometry']['coordinates']);
        $this->assertNotSame($streets['way/110:0']['properties']['to'], $streets['way/110:1']['properties']['from']);
        $graph = $this->snapshot('graph.json');
        $this->assertArrayNotHasKey('13', $graph['nodes']);
        $this->assertArrayNotHasKey('14', $graph['nodes']);
        $boundary = $this->snapshot('boundary.geojson');
        $this->assertSame('Feature', $boundary['type']);
        $this->assertSame('Mérida', $boundary['properties']['name']);
        $this->assertCount(2, $boundary['geometry']['coordinates']);
        $this->assertTrue(MapGeometry::contains($boundary['geometry'], [-89.64, 20.98]));
        $this->assertFalse(MapGeometry::contains($boundary['geometry'], [-89.61, 20.99]));
        $this->assertFalse(MapGeometry::contains($boundary['geometry'], [-89.65, 20.98]));
    }

    public function test_assembles_park_multipolygons_and_uses_only_mapped_ring_entrances(): void
    {
        Storage::fake('local');

        $this->importFixture();

        $parks = array_column($this->snapshot('parks.geojson')['features'], null, 'id');
        $this->assertSame(['relation/300', 'way/200', 'node/200'], array_keys($parks));
        $this->assertSame('MultiPolygon', $parks['relation/300']['geometry']['type']);
        $this->assertCount(2, $parks['relation/300']['geometry']['coordinates']);
        $this->assertCount(2, $parks['relation/300']['geometry']['coordinates'][0]);
        $this->assertFalse(MapGeometry::contains($parks['relation/300']['geometry'], [-89.6365, 20.9885]));
        $this->assertTrue(MapGeometry::contains($parks['relation/300']['geometry'], [-89.629, 20.988]));
        $this->assertTrue($parks['relation/300']['properties']['approximate']);
        $this->assertSame(['id' => 'way/200', 'name' => 'Parque de prueba', 'connection_point' => [-89.62, 20.97], 'approximate' => false], $parks['way/200']['properties']);
        $this->assertSame('Point', $parks['node/200']['geometry']['type']);
        $this->assertSame('Parque Álamo', $parks['node/200']['properties']['name']);
        $this->assertTrue($parks['node/200']['properties']['approximate']);
    }

    #[DataProvider('directions')]
    public function test_applies_explicit_oneway_values_before_the_roundabout_default(?string $value, bool $roundabout, int $expected): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        foreach ($extract['elements'] as &$element) {
            if ($element['type'] === 'way' && $element['id'] === 104) {
                unset($element['tags']['oneway'], $element['tags']['junction']);
                if ($value !== null) {
                    $element['tags']['oneway'] = $value;
                }
                if ($roundabout) {
                    $element['tags']['junction'] = 'roundabout';
                }
            }
        }
        unset($element);

        $this->importFixture($extract);

        $graph = $this->snapshot('graph.json');
        $directions = array_keys(array_filter($graph['edges'], static fn (array $edge): bool => $edge['segment'] === 'way/104:0'));
        $this->assertSame(match ($expected) {
            1 => ['way/104:0:f'], -1 => ['way/104:0:r'], 0 => ['way/104:0:f', 'way/104:0:r'],
        }, $directions);
        $this->assertSame('7', $graph['edges']['way/105:0:r']['from']);
        $this->assertSame('6', $graph['edges']['way/105:0:r']['to']);
        $this->assertArrayNotHasKey('way/105:0:f', $graph['edges']);
        $this->assertArrayHasKey('way/106:0:f', $graph['edges']);
        $this->assertArrayNotHasKey('way/106:0:r', $graph['edges']);
        $this->assertArrayHasKey('way/107:0:r', $graph['edges']);
    }

    public static function directions(): array
    {
        return [
            'yes' => ['yes', false, 1], 'one' => ['1', false, 1], 'true' => ['true', false, 1],
            'reverse' => ['-1', false, -1], 'roundabout' => [null, true, 1],
            'no overrides roundabout' => ['no', true, 0], 'zero overrides roundabout' => ['0', true, 0],
            'false overrides roundabout' => ['false', true, 0], 'default' => [null, false, 0],
        ];
    }

    public function test_rejects_incomplete_references_without_replacing_or_deleting_the_active_snapshot(): void
    {
        Storage::fake('local');
        $this->importFixture();
        $active = Storage::disk('local')->get('merida/active.json');
        $graph = $this->snapshot('graph.json');
        $extract = $this->fixture();
        $extract['elements'] = array_values(array_filter($extract['elements'], static fn (array $element): bool => ! ($element['type'] === 'node' && $element['id'] === 2)));
        $path = $this->writeExtract($extract);

        $this->artisan('mapa:importar', ['archivo' => $path, '--boundary' => '9000'])
            ->expectsOutputToContain('falta node/2')
            ->assertFailed();

        $this->assertSame($active, Storage::disk('local')->get('merida/active.json'));
        $this->assertSame($graph, $this->snapshot('graph.json'));
        $this->assertCount(1, Storage::disk('local')->directories('merida/versions'));
    }

    #[DataProvider('invalidExtracts')]
    public function test_rejects_invalid_inputs_without_activating_a_snapshot(string $case, string $message): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        foreach ($extract['elements'] as &$element) {
            if ($element['type'] === 'relation' && $element['id'] === 9000) {
                match ($case) {
                    'name' => $element['tags'] = ['name' => 'Otra ciudad', 'boundary' => 'administrative', 'admin_level' => '6'],
                    'level' => $element['tags']['admin_level'] = '8',
                    'state' => $element['tags']['is_in:state'] = 'Campeche',
                    'inegi' => $element['tags']['INEGI:MUNID'] = '04001',
                    'missing way' => $element['members'][0]['ref'] = 999999,
                    default => null,
                };
            }
            if ($element['type'] === 'node' && $element['id'] === 901 && $case === 'coordinate') {
                $element['lon'] = -181;
            }
            if ($element['type'] === 'way' && $element['id'] === 901 && $case === 'open ring') {
                $element['nodes'] = [900, 903, 907];
            }
            if ($element['type'] === 'way' && $element['id'] === 104 && $case === 'oneway') {
                $element['tags']['oneway'] = 'reversible';
            }
        }
        unset($element);
        if ($case === 'duplicate') {
            $extract['elements'][] = $extract['elements'][0];
        }
        $path = $this->writeExtract($extract);

        $this->artisan('mapa:importar', ['archivo' => $path, '--boundary' => '9000'])
            ->expectsOutputToContain($message)
            ->assertFailed();

        Storage::disk('local')->assertMissing('merida/active.json');
    }

    public static function invalidExtracts(): array
    {
        return [
            'municipality' => ['name', 'municipio administrativo Mérida'],
            'admin level' => ['level', 'nivel observado: 8'],
            'state' => ['state', 'estado distinto de Yucatán'],
            'INEGI' => ['inegi', 'INEGI:MUNID'],
            'missing relation member' => ['missing way', 'falta way/999999'],
            'coordinate range' => ['coordinate', 'Coordenadas inválidas'],
            'unclosed boundary' => ['open ring', 'Anillo abierto'],
            'unsupported direction' => ['oneway', 'oneway no admitido'],
            'duplicate node' => ['duplicate', 'duplicado: node/1'],
        ];
    }

    public function test_requires_an_explicit_boundary_and_a_local_file(): void
    {
        Storage::fake('local');

        $this->artisan('mapa:importar', ['archivo' => base_path('tests/Fixtures/merida/extract.json')])
            ->expectsOutputToContain('--boundary es obligatoria')->assertFailed();
        $this->artisan('mapa:importar', ['archivo' => 'https://example.test/extract.json', '--boundary' => '9000'])
            ->expectsOutputToContain('JSON local legible')->assertFailed();
        $this->artisan('mapa:importar', ['archivo' => '//servidor-remoto/extract.json', '--boundary' => '9000'])
            ->expectsOutputToContain('JSON local legible')->assertFailed();

        Storage::disk('local')->assertMissing('merida/active.json');
    }

    public function test_keeps_missing_source_date_distinct_from_the_import_time_and_preserves_unnamed_parks(): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        unset($extract['osm3s']);
        foreach ($extract['elements'] as &$element) {
            if ($element['type'] === 'way' && $element['id'] === 200) {
                unset($element['tags']['name']);
            }
        }
        unset($element);
        $path = $this->writeExtract($extract);

        $this->artisan('mapa:importar', ['archivo' => $path, '--boundary' => '9000'])
            ->expectsOutputToContain('Fuente sin fecha OSM: source_date=null')
            ->assertSuccessful();

        $this->assertNull($this->snapshot('metadata.json')['source_date']);
        $this->assertIsString($this->snapshot('metadata.json')['imported_at']);
        $parks = array_column($this->snapshot('parks.geojson')['features'], null, 'id');
        $this->assertSame('', $parks['way/200']['properties']['name']);
    }

    public function test_does_not_activate_a_partially_written_snapshot(): void
    {
        $disk = Storage::fake('local');
        $this->importFixture();
        $active = $disk->get('merida/active.json');
        $failingDisk = Mockery::mock($disk);
        $failingDisk->shouldReceive('put')->andReturnUsing(fn (...$arguments): bool => $disk->put(...$arguments))->byDefault();
        $failingDisk->shouldReceive('put')
            ->withArgs(static fn (string $path, mixed $contents): bool => str_ends_with($path, '/streets.geojson'))
            ->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);

        $this->artisan('mapa:importar', ['archivo' => base_path('tests/Fixtures/merida/extract.json'), '--boundary' => '9000'])
            ->expectsOutputToContain('No se pudo escribir streets.geojson')
            ->assertFailed();

        $this->assertSame($active, $disk->get('merida/active.json'));
        $version = json_decode($active, true, 512, JSON_THROW_ON_ERROR)['version'];
        $disk->assertExists("merida/versions/{$version}/streets.geojson");
    }

    public function test_accepts_the_official_municipality_name_without_a_name_tag(): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        foreach ($extract['elements'] as &$element) {
            if ($element['type'] === 'relation' && $element['id'] === 9000) {
                unset($element['tags']['name']);
            }
        }
        unset($element);

        $this->importFixture($extract);

        $this->assertSame('Mérida', $this->snapshot('metadata.json')['municipality']);
    }

    public function test_keeps_clipped_points_of_unconnected_ways_separate_even_at_identical_coordinates(): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        $extract['elements'][] = ['type' => 'way', 'id' => 113, 'nodes' => [13, 14], 'tags' => ['highway' => 'residential']];

        $this->importFixture($extract);

        $streets = array_column($this->snapshot('streets.geojson')['features'], null, 'id');
        $this->assertSame($streets['way/108:0']['geometry'], $streets['way/113:0']['geometry']);
        $this->assertNotSame($streets['way/108:0']['properties']['from'], $streets['way/113:0']['properties']['from']);
        $this->assertNotSame($streets['way/108:0']['properties']['to'], $streets['way/113:0']['properties']['to']);
    }

    public function test_cross_border_parks_keep_their_real_geometry_but_connect_inside_the_municipality(): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        foreach ($extract['elements'] as &$element) {
            if ($element['type'] === 'node' && $element['id'] === 201) {
                $element['lon'] = -89.59;
            }
        }
        unset($element);

        $this->importFixture($extract);
        $parks = array_column($this->snapshot('parks.geojson')['features'], null, 'id');
        $this->assertArrayHasKey('way/200', $parks);
        $this->assertTrue(MapGeometry::contains($this->snapshot('boundary.geojson')['geometry'], $parks['way/200']['properties']['connection_point']));
        $this->assertContains([-89.59, 20.97], $parks['way/200']['geometry']['coordinates'][0]);
    }

    public function test_clips_multiple_polygon_components_and_collinear_boundary_streets_without_connecting_islands(): void
    {
        $geometry = ['type' => 'MultiPolygon', 'coordinates' => [
            [[[0, 0], [2, 0], [2, 2], [0, 2], [0, 0]]],
            [[[4, 0], [6, 0], [6, 2], [4, 2], [4, 0]]],
        ]];

        $parts = MapGeometry::clipSegment($geometry, [-1, 0], [7, 0]);

        $this->assertCount(2, $parts);
        $this->assertSame([0.0, 0.0], $parts[0]['a']);
        $this->assertSame([2.0, 0.0], $parts[0]['b']);
        $this->assertSame([4.0, 0.0], $parts[1]['a']);
        $this->assertSame([6.0, 0.0], $parts[1]['b']);
        $this->assertTrue(MapGeometry::contains($geometry, [0, 1]));
        $this->assertFalse(MapGeometry::contains($geometry, [3, 1]));
        $this->assertSame([0, 0, 6, 2], MapGeometry::bounds($geometry));
        $this->assertSame([], MapGeometry::clipSegment($geometry, [1, 1], [1.0, 1.0]));
    }

    public function test_a_park_covering_a_municipal_hole_gets_a_connection_outside_the_hole(): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        foreach ([[301, -89.614, 20.986], [302, -89.606, 20.986], [303, -89.606, 20.994], [304, -89.614, 20.994]] as [$id, $longitude, $latitude]) {
            $extract['elements'][] = ['type' => 'node', 'id' => $id, 'lon' => $longitude, 'lat' => $latitude];
        }
        $extract['elements'][] = ['type' => 'way', 'id' => 205, 'nodes' => [301, 302, 303, 304, 301], 'tags' => ['leisure' => 'park']];

        $this->importFixture($extract);
        $parks = array_column($this->snapshot('parks.geojson')['features'], null, 'id');
        $point = $parks['way/205']['properties']['connection_point'];
        $this->assertTrue(MapGeometry::contains($this->snapshot('boundary.geojson')['geometry'], $point));
        $this->assertTrue(MapGeometry::contains($parks['way/205']['geometry'], $point));
    }

    public function test_rejects_overpass_partial_results_even_when_their_references_are_complete(): void
    {
        Storage::fake('local');
        $extract = [...$this->fixture(), 'remark' => 'runtime error: Query timed out'];
        $this->artisan('mapa:importar', ['archivo' => $this->writeExtract($extract), '--boundary' => '9000'])
            ->expectsOutputToContain('resultado parcial')->assertFailed();
        Storage::disk('local')->assertMissing('merida/active.json');
    }

    public function test_motorways_default_to_one_way_unless_explicitly_overridden(): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        foreach ($extract['elements'] as &$element) {
            if ($element['type'] === 'way' && $element['id'] === 104) {
                $element['tags']['highway'] = 'motorway';
                unset($element['tags']['oneway']);
            }
        }
        unset($element);
        $this->importFixture($extract);
        $graph = $this->snapshot('graph.json');
        $this->assertArrayHasKey('way/104:0:f', $graph['edges']);
        $this->assertArrayNotHasKey('way/104:0:r', $graph['edges']);
    }

    public function test_a_park_crossing_a_municipal_hole_does_not_connect_on_the_excluded_hole_boundary(): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        foreach ([[301, -89.610, 20.990], [302, -89.605, 20.990], [303, -89.605, 20.991], [304, -89.610, 20.991]] as [$id, $longitude, $latitude]) {
            $extract['elements'][] = ['type' => 'node', 'id' => $id, 'lon' => $longitude, 'lat' => $latitude];
        }
        $extract['elements'][] = ['type' => 'way', 'id' => 205, 'nodes' => [301, 302, 303, 304, 301], 'tags' => ['leisure' => 'park', 'name' => 'Parque junto al hueco']];
        $this->importFixture($extract);
        $parks = array_column($this->snapshot('parks.geojson')['features'], null, 'id');
        $point = $parks['way/205']['properties']['connection_point'];
        $this->assertTrue(MapGeometry::contains($this->snapshot('boundary.geojson')['geometry'], $point));
        $this->assertTrue(MapGeometry::contains($parks['way/205']['geometry'], $point));
        $this->assertNotSame([-89.607, 20.99], $point);
    }

    public function test_a_self_intersecting_municipal_ring_is_rejected_even_with_nonzero_algebraic_area(): void
    {
        Storage::fake('local');
        $extract = $this->fixture();
        foreach ([[930, -89.64, 20.96], [931, -89.60, 21.0], [932, -89.64, 21.0], [933, -89.61, 20.96]] as [$id, $longitude, $latitude]) {
            $extract['elements'][] = ['type' => 'node', 'id' => $id, 'lon' => $longitude, 'lat' => $latitude];
        }
        $extract['elements'][] = ['type' => 'way', 'id' => 930, 'nodes' => [930, 931, 932, 933, 930]];
        foreach ($extract['elements'] as &$element) {
            if ($element['type'] === 'relation' && $element['id'] === 9000) {
                $element['members'] = [['type' => 'way', 'ref' => 930, 'role' => 'outer']];
            }
        }
        unset($element);
        $this->artisan('mapa:importar', ['archivo' => $this->writeExtract($extract), '--boundary' => '9000'])
            ->expectsOutputToContain('Anillo autointersectado')->assertFailed();
        Storage::disk('local')->assertMissing('merida/active.json');
    }

    private function importFixture(?array $extract = null): void
    {
        $path = $extract === null ? base_path('tests/Fixtures/merida/extract.json') : $this->writeExtract($extract);
        $this->artisan('mapa:importar', ['archivo' => $path, '--boundary' => '9000'])->assertSuccessful();
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/merida/extract.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeExtract(array $extract): string
    {
        Storage::disk('local')->put('extract.json', json_encode($extract, JSON_THROW_ON_ERROR));

        return Storage::disk('local')->path('extract.json');
    }

    private function active(): array
    {
        return json_decode(Storage::disk('local')->get('merida/active.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function snapshot(string $name): array
    {
        return json_decode(Storage::disk('local')->get('merida/versions/'.$this->active()['version'].'/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }
}
