<?php

namespace Tests\Unit;

use App\Services\GraphSearch;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GraphSearchTest extends TestCase
{
    public function test_bfs_finds_fewer_segments_but_a_longer_distance_than_dfs(): void
    {
        $graph = $this->cyclicGraph();
        $originalGraph = $graph;
        $search = new GraphSearch;

        $bfs = $search->run($graph, 'A', 'D', 'bfs');
        $dfs = $search->run($graph, 'A', 'D', 'dfs');

        $this->assertResult([
            'status' => 'found',
            'route_nodes' => ['A', 'D'],
            'route_edges' => ['ad'],
            'visited_nodes' => ['A', 'B', 'D'],
            'trace' => [
                ['visit', 'A'], ['edge', 'ab'], ['edge', 'ad'],
                ['visit', 'B'], ['edge', 'bb'], ['edge', 'bc'], ['visit', 'D'],
            ],
            'metrics' => ['visited_nodes' => 3, 'segments' => 1, 'distance_m' => 100.0],
        ], $bfs);
        $this->assertResult([
            'status' => 'found',
            'route_nodes' => ['A', 'B', 'C', 'D'],
            'route_edges' => ['ab', 'bc', 'cd'],
            'visited_nodes' => ['A', 'B', 'C', 'D'],
            'trace' => [
                ['visit', 'A'], ['edge', 'ab'], ['visit', 'B'], ['edge', 'bb'],
                ['edge', 'bc'], ['visit', 'C'], ['edge', 'ca'], ['edge', 'cd'], ['visit', 'D'],
            ],
            'metrics' => ['visited_nodes' => 4, 'segments' => 3, 'distance_m' => 7.5],
        ], $dfs);
        $this->assertSame($originalGraph, $graph);
    }

    public function test_dfs_discovers_a_pending_sibling_through_the_deep_branch(): void
    {
        $graph = $this->graph(['A', 'B', 'C', 'D'], [
            'ab' => ['A', 'B', 1], 'ac' => ['A', 'C', 10],
            'bc' => ['B', 'C', 2], 'cd' => ['C', 'D', 3],
        ]);

        $result = (new GraphSearch)->run($graph, 'A', 'D', 'dfs');

        $this->assertResult([
            'status' => 'found',
            'route_nodes' => ['A', 'B', 'C', 'D'],
            'route_edges' => ['ab', 'bc', 'cd'],
            'visited_nodes' => ['A', 'B', 'C', 'D'],
            'trace' => [
                ['visit', 'A'], ['edge', 'ab'], ['visit', 'B'], ['edge', 'bc'],
                ['visit', 'C'], ['edge', 'cd'], ['visit', 'D'],
            ],
            'metrics' => ['visited_nodes' => 4, 'segments' => 3, 'distance_m' => 6.0],
        ], $result);
    }

    public function test_dfs_resumes_the_parent_cursor_after_backtracking(): void
    {
        $graph = $this->graph(['A', 'B', 'C', 'D', 'E'], [
            'ab' => ['A', 'B', 1], 'ac' => ['A', 'C', 2],
            'bd' => ['B', 'D', 3], 'da' => ['D', 'A', 4], 'ce' => ['C', 'E', 5],
        ]);

        $result = (new GraphSearch)->run($graph, 'A', 'E', 'dfs');

        $this->assertResult([
            'status' => 'found',
            'route_nodes' => ['A', 'C', 'E'],
            'route_edges' => ['ac', 'ce'],
            'visited_nodes' => ['A', 'B', 'D', 'C', 'E'],
            'trace' => [
                ['visit', 'A'], ['edge', 'ab'], ['visit', 'B'], ['edge', 'bd'],
                ['visit', 'D'], ['edge', 'da'], ['edge', 'ac'],
                ['visit', 'C'], ['edge', 'ce'], ['visit', 'E'],
            ],
            'metrics' => ['visited_nodes' => 5, 'segments' => 2, 'distance_m' => 7.0],
        ], $result);
    }

    #[DataProvider('unreachableTraversals')]
    public function test_unreachable_destination_preserves_the_complete_cyclic_traversal(string $method, array $visited, array $trace): void
    {
        $graph = $this->cyclicGraph();

        $result = (new GraphSearch)->run($graph, 'A', 'X', $method);

        $this->assertResult([
            'status' => 'unreachable',
            'route_nodes' => [],
            'route_edges' => [],
            'visited_nodes' => $visited,
            'trace' => $trace,
            'metrics' => ['visited_nodes' => 4, 'segments' => null, 'distance_m' => null],
        ], $result);
    }

    public static function unreachableTraversals(): array
    {
        return [
            'breadth first' => ['bfs', ['A', 'B', 'D', 'C'], [
                ['visit', 'A'], ['edge', 'ab'], ['edge', 'ad'],
                ['visit', 'B'], ['edge', 'bb'], ['edge', 'bc'], ['visit', 'D'],
                ['visit', 'C'], ['edge', 'ca'], ['edge', 'cd'],
            ]],
            'depth first' => ['dfs', ['A', 'B', 'C', 'D'], [
                ['visit', 'A'], ['edge', 'ab'], ['visit', 'B'], ['edge', 'bb'],
                ['edge', 'bc'], ['visit', 'C'], ['edge', 'ca'], ['edge', 'cd'],
                ['visit', 'D'], ['edge', 'ad'],
            ]],
        ];
    }

    #[DataProvider('methods')]
    public function test_same_origin_and_destination_is_visited_without_inspecting_outgoing_edges(string $method): void
    {
        $graph = $this->cyclicGraph();

        $result = (new GraphSearch)->run($graph, 'A', 'A', $method);

        $this->assertResult([
            'status' => 'found',
            'route_nodes' => ['A'],
            'route_edges' => [],
            'visited_nodes' => ['A'],
            'trace' => [['visit', 'A']],
            'metrics' => ['visited_nodes' => 1, 'segments' => 0, 'distance_m' => 0.0],
        ], $result);
    }

    #[DataProvider('methods')]
    public function test_parallel_edges_keep_the_first_imported_edge_as_parent(string $method): void
    {
        $graph = $this->graph(['A', 'B', 'C'], [
            'z-first' => ['A', 'B', 12.5], 'a-short' => ['A', 'B', 1],
            'ba' => ['B', 'A', 4], 'bc' => ['B', 'C', 5],
        ]);

        $result = (new GraphSearch)->run($graph, 'A', 'C', $method);

        $this->assertSame(['A', 'B', 'C'], $result['route_nodes']);
        $this->assertSame(['z-first', 'bc'], $result['route_edges']);
        $this->assertSame(17.5, $result['metrics']['distance_m']);
        $this->assertSame(2, $result['metrics']['segments']);
    }

    #[DataProvider('methods')]
    public function test_directed_edges_are_not_traversed_in_reverse(string $method): void
    {
        $graph = $this->graph(['A', 'B'], ['ab' => ['A', 'B', 2]]);

        $result = (new GraphSearch)->run($graph, 'B', 'A', $method);

        $this->assertResult([
            'status' => 'unreachable',
            'route_nodes' => [],
            'route_edges' => [],
            'visited_nodes' => ['B'],
            'trace' => [['visit', 'B']],
            'metrics' => ['visited_nodes' => 1, 'segments' => null, 'distance_m' => null],
        ], $result);
    }

    #[DataProvider('methods')]
    public function test_explicit_reverse_edges_can_be_used(string $method): void
    {
        $graph = $this->graph(['A', 'B'], [
            'ab' => ['A', 'B', 2], 'ba' => ['B', 'A', 3],
        ]);

        $result = (new GraphSearch)->run($graph, 'B', 'A', $method);

        $this->assertSame(['B', 'A'], $result['route_nodes']);
        $this->assertSame(['ba'], $result['route_edges']);
        $this->assertSame(3.0, $result['metrics']['distance_m']);
    }

    #[DataProvider('methods')]
    public function test_numeric_string_ids_remain_strings_in_results(string $method): void
    {
        $graph = $this->graph(['0', '1'], ['0' => ['0', '1', 2]]);

        $result = (new GraphSearch)->run($graph, '0', '1', $method);

        $this->assertSame(['0', '1'], $result['route_nodes']);
        $this->assertSame(['0'], $result['route_edges']);
        $this->assertSame(['0', '1'], $result['visited_nodes']);
    }

    #[DataProvider('methods')]
    public function test_each_run_has_independent_state_even_after_an_unreachable_search(string $method): void
    {
        $graph = $this->cyclicGraph();
        $search = new GraphSearch;
        $first = $search->run($graph, 'A', 'D', $method);

        $unreachable = $search->run($graph, 'X', 'A', $method);
        $repeated = $search->run($graph, 'A', 'D', $method);

        $this->assertResult([
            'status' => 'unreachable',
            'route_nodes' => [],
            'route_edges' => [],
            'visited_nodes' => ['X'],
            'trace' => [['visit', 'X']],
            'metrics' => ['visited_nodes' => 1, 'segments' => null, 'distance_m' => null],
        ], $unreachable);
        unset($first['metrics']['search_ms']);
        $this->assertResult($first, $repeated);
    }

    #[DataProvider('invalidSearches')]
    public function test_rejects_invalid_methods_or_node_ids(string $origin, string $destination, string $method): void
    {
        $graph = $this->cyclicGraph();
        $this->expectException(InvalidArgumentException::class);

        (new GraphSearch)->run($graph, $origin, $destination, $method);
    }

    public static function invalidSearches(): array
    {
        return [
            'unknown method' => ['A', 'D', 'dijkstra'],
            'uppercase method' => ['A', 'D', 'BFS'],
            'empty method' => ['A', 'D', ''],
            'unknown origin bfs' => ['missing', 'D', 'bfs'],
            'unknown origin dfs' => ['missing', 'D', 'dfs'],
            'unknown destination bfs' => ['A', 'missing', 'bfs'],
            'unknown destination dfs' => ['A', 'missing', 'dfs'],
            'empty origin' => ['', 'D', 'bfs'],
            'same unknown endpoints' => ['missing', 'missing', 'dfs'],
        ];
    }

    public static function methods(): array
    {
        return ['breadth first' => ['bfs'], 'depth first' => ['dfs']];
    }

    private function cyclicGraph(): array
    {
        return $this->graph(['A', 'B', 'C', 'D', 'X'], [
            'ab' => ['A', 'B', 1.25], 'ad' => ['A', 'D', 100],
            'bb' => ['B', 'B', 0.5], 'bc' => ['B', 'C', 2.5],
            'ca' => ['C', 'A', 1], 'cd' => ['C', 'D', 3.75],
        ]);
    }

    /**
     * @param  list<string>  $nodeIds
     * @param  array<string, array{string, string, int|float}>  $edges
     */
    private function graph(array $nodeIds, array $edges): array
    {
        $graph = ['nodes' => [], 'edges' => [], 'adjacency' => []];

        foreach ($nodeIds as $nodeId) {
            $graph['nodes'][$nodeId] = [-89.6, 20.9];
            $graph['adjacency'][$nodeId] = [];
        }

        foreach ($edges as $edgeId => [$from, $to, $distance]) {
            $graph['edges'][$edgeId] = [
                'from' => $from, 'to' => $to, 'distance_m' => $distance,
                'name' => 'Calle '.$edgeId, 'segment' => (string) $edgeId,
            ];
            $graph['adjacency'][$from][] = (string) $edgeId;
        }

        return $graph;
    }

    private function assertResult(array $expected, array $actual): void
    {
        $this->assertIsFloat($actual['metrics']['search_ms']);
        $this->assertGreaterThanOrEqual(0.0, $actual['metrics']['search_ms']);
        unset($actual['metrics']['search_ms']);
        $this->assertSame($expected, $actual);
    }
}
