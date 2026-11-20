<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapTest extends TestCase
{
    public function test_home_explains_missing_local_data_without_inventing_a_map(): void
    {
        Storage::fake('local');
        $this->withoutVite();

        $this->get('/')->assertOk()->assertSee('Rutas entre parques de Mérida')
            ->assertSee('mapa:importar')->assertDontSee('tile.openstreetmap.org');
    }

    public function test_parks_are_searched_locally_without_accents_or_external_requests(): void
    {
        $this->dataset();
        Http::preventStrayRequests();

        $this->getJson('/parques?q=lucia&version=test-01')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('parks.0.id', 'node/park')
            ->assertJsonPath('parks.0.name', 'Parque de Santa Lucía');
        $this->getJson('/parques?q=inexistente&version=test-01')->assertOk()->assertJsonPath('parks', []);
        Http::assertNothingSent();
    }

    public function test_visible_geometry_is_filtered_but_search_uses_the_complete_graph(): void
    {
        $this->dataset();
        $this->getJson('/mapa?west=-89.6198&south=20.969&east=-89.6195&north=20.971&zoom=17&version=test-01')
            ->assertOk()->assertJsonCount(1, 'streets.features')->assertJsonCount(0, 'parks.features');

        $this->postJson('/busquedas', $this->searchInput())->assertOk()
            ->assertJsonPath('results.bfs.route_nodes', ['A', 'B', 'D'])
            ->assertJsonPath('results.dfs.route_nodes', ['A', 'B', 'C', 'D'])
            ->assertJsonPath('results.bfs.metrics.segments', 2)
            ->assertJsonPath('results.bfs.metrics.distance_m', 500)
            ->assertJsonPath('results.dfs.metrics.distance_m', 300)
            ->assertJsonPath('comparison.winner', 'dfs');
    }

    public function test_the_complete_local_http_flow_never_uses_an_external_service(): void
    {
        $this->dataset();
        Http::preventStrayRequests();
        $this->withoutVite();
        $this->get('/')->assertOk()->assertSee('Rutas entre parques de Mérida');
        $this->getJson('/parques?q=lucia&version=test-01')->assertOk()->assertJsonPath('parks.0.id', 'node/park');
        $this->postJson('/origen', ['latitude' => 20.97, 'longitude' => -89.62, 'version' => 'test-01'])
            ->assertOk()->assertJsonPath('origin.node_id', 'A')->assertJsonPath('origin.offset_m', 0);
        $response = $this->postJson('/busquedas', $this->searchInput())->assertOk()
            ->assertJsonPath('version', 'test-01')->assertJsonPath('origin.node_id', 'A')
            ->assertJsonPath('destination.node_id', 'D')->assertJsonPath('results.bfs.status', 'found')
            ->assertJsonPath('results.dfs.status', 'found')->assertJsonPath('network.edges.ab.geometry.0', [-89.62, 20.97]);
        $this->assertGreaterThanOrEqual(0, $response->json('results.bfs.metrics.search_ms'));
        $this->assertSame(['visit', 'A'], $response->json('results.bfs.trace.0'));
        Http::assertNothingSent();
    }

    public function test_an_imported_osm_extract_drives_both_algorithms_through_the_local_endpoints(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->artisan('mapa:importar', ['archivo' => base_path('tests/Fixtures/merida/extract.json'), '--boundary' => '9000'])
            ->assertSuccessful();
        $active = json_decode(Storage::disk('local')->get('merida/active.json'), true);
        $this->getJson('/parques?q=prueba&version='.$active['version'])->assertOk()
            ->assertJsonPath('parks.0.id', 'way/200');
        $this->postJson('/busquedas', [
            'latitude' => 20.97, 'longitude' => -89.635, 'park_id' => 'way/200', 'method' => 'both', 'version' => $active['version'],
        ])->assertOk()->assertJsonPath('origin.node_id', '1')->assertJsonPath('destination.node_id', '6')
            ->assertJsonPath('results.bfs.route_nodes', ['1', '3', '6'])
            ->assertJsonPath('results.dfs.route_nodes', ['1', '4', '5', '3', '6']);
        Http::assertNothingSent();
    }

    public function test_one_method_does_not_run_the_other_and_reverse_direction_is_unreachable(): void
    {
        $this->dataset();
        $input = [...$this->searchInput(), 'method' => 'dfs'];
        $this->postJson('/busquedas', $input)->assertOk()->assertJsonMissingPath('results.bfs')
            ->assertJsonPath('results.dfs.status', 'found');

        $this->postJson('/busquedas', [...$input, 'latitude' => 20.971, 'longitude' => -89.618, 'park_id' => 'node/start'])
            ->assertOk()->assertJsonPath('results.dfs.status', 'unreachable')
            ->assertJsonPath('results.dfs.metrics.distance_m', null)->assertJsonPath('results.dfs.route_edges', []);
    }

    public function test_same_origin_and_destination_and_equal_routes_are_reported_as_a_tie(): void
    {
        $this->dataset();
        $this->postJson('/busquedas', [...$this->searchInput(), 'latitude' => 20.971, 'longitude' => -89.618])
            ->assertOk()->assertJsonPath('results.bfs.metrics.segments', 0)
            ->assertJsonPath('results.bfs.metrics.visited_nodes', 1)
            ->assertJsonPath('results.bfs.metrics.distance_m', 0)
            ->assertJsonPath('comparison.winner', 'tie');
    }

    public function test_invalid_origin_outside_polygon_and_too_distant_origin_are_rejected(): void
    {
        $this->dataset();
        $this->postJson('/origen', ['latitude' => 21, 'longitude' => -89.62, 'version' => 'test-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('latitude')
            ->assertJsonPath('errors.latitude.0', 'Selecciona un punto dentro del municipio de Mérida.');
        $this->postJson('/origen', ['latitude' => 20.989, 'longitude' => -89.601, 'version' => 'test-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->postJson('/origen', ['latitude' => 'texto', 'longitude' => 999, 'version' => 'test-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_a_hole_in_the_municipal_polygon_is_not_a_valid_origin(): void
    {
        $this->dataset();
        $disk = Storage::disk('local');
        $boundary = json_decode($disk->get('merida/versions/test-01/boundary.geojson'), true);
        $boundary['geometry']['coordinates'][] = [[-89.625, 20.975], [-89.624, 20.975], [-89.624, 20.976], [-89.625, 20.976], [-89.625, 20.975]];
        $disk->put('merida/versions/test-01/boundary.geojson', json_encode($boundary));
        $this->postJson('/origen', ['latitude' => 20.9755, 'longitude' => -89.6245, 'version' => 'test-01'])
            ->assertUnprocessable()->assertJsonPath('errors.latitude.0', 'Selecciona un punto dentro del municipio de Mérida.');
    }

    public function test_unknown_destination_bad_method_missing_input_and_stale_version_have_clear_errors(): void
    {
        $this->dataset();
        $this->postJson('/busquedas', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude', 'version', 'park_id', 'method']);
        $this->postJson('/busquedas', [...$this->searchInput(), 'method' => 'dijkstra'])->assertUnprocessable()
            ->assertJsonPath('errors.method.0', 'Selecciona BFS, DFS o Comparar ambos.');
        $this->postJson('/busquedas', [...$this->searchInput(), 'park_id' => 'unknown'])->assertUnprocessable()
            ->assertJsonPath('errors.park_id.0', 'El parque seleccionado no existe en los datos locales.');
        $this->postJson('/busquedas', [...$this->searchInput(), 'version' => 'old'])->assertConflict();
        $this->getJson('/mapa?west=1&south=1&east=0&north=0&zoom=17&version=test-01')
            ->assertUnprocessable()->assertJsonValidationErrors('west');
    }

    public function test_missing_and_corrupt_datasets_fail_without_returning_fake_results(): void
    {
        Storage::fake('local');
        $this->getJson('/parques?version=missing')->assertServiceUnavailable();
        Storage::disk('local')->put('merida/active.json', '{broken');
        $this->getJson('/parques?version=missing')->assertServiceUnavailable()
            ->assertJsonPath('message', 'Los datos locales están dañados. Vuelve a importar el extracto.');
        Storage::disk('local')->put('merida/active.json', json_encode(['version' => '../../outside']));
        $this->getJson('/parques?version=missing')->assertServiceUnavailable();
    }

    public function test_inconsistent_graph_is_a_data_error_instead_of_an_unreachable_route(): void
    {
        $this->dataset();
        $disk = Storage::disk('local');
        $graph = json_decode($disk->get('merida/versions/test-01/graph.json'), true);
        unset($graph['nodes']['B']);
        $disk->put('merida/versions/test-01/graph.json', json_encode($graph));
        $this->postJson('/busquedas', $this->searchInput())->assertServiceUnavailable();
    }

    public function test_a_missing_adjacency_index_is_not_misreported_as_an_unreachable_destination(): void
    {
        $this->dataset();
        $disk = Storage::disk('local');
        $graph = json_decode($disk->get('merida/versions/test-01/graph.json'), true);
        unset($graph['adjacency']['B']);
        $disk->put('merida/versions/test-01/graph.json', json_encode($graph));
        $this->postJson('/busquedas', $this->searchInput())->assertServiceUnavailable();
    }

    public function test_integer_edge_endpoints_are_rejected_before_they_can_cause_false_unreachable_results(): void
    {
        $this->dataset();
        $graph = [
            'nodes' => ['1' => [-89.62, 20.97], '2' => [-89.619, 20.97]],
            'edges' => ['ab' => ['from' => '1', 'to' => 2, 'name' => 'Calle', 'segment' => 'ab', 'distance_m' => 100]],
            'adjacency' => ['1' => ['ab'], '2' => []],
        ];
        Storage::disk('local')->put('merida/versions/test-01/graph.json', json_encode($graph));
        $this->postJson('/busquedas', $this->searchInput())->assertServiceUnavailable();
    }

    public function test_malformed_metadata_and_polygon_are_reported_as_unavailable_data(): void
    {
        $this->dataset();
        $disk = Storage::disk('local');
        $path = 'merida/versions/test-01/metadata.json';
        $metadata = json_decode($disk->get($path), true);
        $disk->put($path, json_encode([...$metadata, 'bounds' => 'broken']));
        $this->getJson('/parques?version=test-01')->assertServiceUnavailable();

        $disk->put($path, json_encode($metadata));
        $disk->put('merida/versions/test-01/boundary.geojson', json_encode(['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => []]]));
        $this->postJson('/origen', ['latitude' => 20.97, 'longitude' => -89.62, 'version' => 'test-01'])
            ->assertServiceUnavailable()->assertJsonPath('message', 'El límite municipal local está dañado.');
    }

    private function searchInput(): array
    {
        return ['latitude' => 20.97, 'longitude' => -89.62, 'park_id' => 'node/park', 'method' => 'both', 'version' => 'test-01'];
    }

    /** Synthetic snapshot for HTTP contract tests only; never installed as application data. */
    private function dataset(): void
    {
        Storage::fake('local');
        $nodes = ['A' => [-89.62, 20.97], 'B' => [-89.619, 20.97], 'C' => [-89.619, 20.971], 'D' => [-89.618, 20.971]];
        $edges = [];
        $streets = [];
        foreach ([['ab', 'A', 'B', 100], ['bc', 'B', 'C', 100], ['bd', 'B', 'D', 400], ['cd', 'C', 'D', 100]] as [$id, $from, $to, $distance]) {
            $edges[$id] = ['from' => $from, 'to' => $to, 'distance_m' => $distance, 'name' => 'Calle '.$id, 'segment' => $id];
            $streets[] = ['type' => 'Feature', 'id' => $id, 'properties' => ['id' => $id, 'name' => 'Calle '.$id, 'from' => $from, 'to' => $to, 'oneway' => 1], 'geometry' => ['type' => 'LineString', 'coordinates' => [$nodes[$from], $nodes[$to]]]];
        }
        $parks = [];
        foreach ([['node/park', 'Parque de Santa Lucía', 'D'], ['node/start', 'Parque de prueba', 'A']] as [$id, $name, $node]) {
            $parks[] = ['type' => 'Feature', 'id' => $id, 'properties' => ['id' => $id, 'name' => $name, 'connection_point' => $nodes[$node], 'approximate' => true], 'geometry' => ['type' => 'Point', 'coordinates' => $nodes[$node]]];
        }
        $files = [
            'metadata.json' => ['schema' => 1, 'version' => 'test-01', 'municipality' => 'Mérida', 'state' => 'Yucatán', 'bounds' => [-89.63, 20.96, -89.60, 20.99], 'imported_at' => '2026-11-20T00:00:00Z', 'source_date' => null, 'source' => 'OpenStreetMap', 'license' => 'ODbL-1.0', 'counts' => ['nodes' => 4, 'edges' => 4, 'streets' => 4, 'parks' => 2]],
            'graph.json' => ['nodes' => $nodes, 'edges' => $edges, 'adjacency' => ['A' => ['ab'], 'B' => ['bc', 'bd'], 'C' => ['cd'], 'D' => []]],
            'streets.geojson' => ['type' => 'FeatureCollection', 'features' => $streets],
            'parks.geojson' => ['type' => 'FeatureCollection', 'features' => $parks],
            'boundary.geojson' => ['type' => 'Feature', 'properties' => ['name' => 'Mérida'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-89.63, 20.96], [-89.60, 20.96], [-89.60, 20.99], [-89.63, 20.99], [-89.63, 20.96]]]]],
        ];
        foreach ($files as $name => $contents) {
            Storage::disk('local')->put('merida/versions/test-01/'.$name, json_encode($contents));
        }
        Storage::disk('local')->put('merida/active.json', json_encode(['version' => 'test-01', 'previous_version' => null]));
    }
}
