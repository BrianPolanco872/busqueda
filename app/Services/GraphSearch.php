<?php

namespace App\Services;

use InvalidArgumentException;
use SplQueue;

class GraphSearch
{
    /**
     * Search an imported graph; timing includes tracing but excludes validation and route reconstruction.
     *
     * @param  array{nodes: array<string, array{float, float}>, edges: array<string, array{from: string, to: string, distance_m: int|float, name: string, segment: string}>, adjacency: array<string, list<string>>}  $graph
     * @return array{status: 'found'|'unreachable', route_nodes: list<string>, route_edges: list<string>, visited_nodes: list<string>, trace: list<array{string, string}>, metrics: array{visited_nodes: int, segments: int|null, distance_m: float|null, search_ms: float}}
     */
    public function run(array $graph, string $origin, string $destination, string $method): array
    {
        if (! in_array($method, ['bfs', 'dfs'], true)) {
            throw new InvalidArgumentException('Search method must be bfs or dfs.');
        }

        if (! isset($graph['nodes'][$origin], $graph['nodes'][$destination])) {
            throw new InvalidArgumentException('Origin and destination must be graph node IDs.');
        }

        $visitedNodes = [];
        $trace = [];
        $parents = [];
        $discovered = [];
        $found = false;

        $startedAt = hrtime(true);

        if ($method === 'bfs') {
            $queue = new SplQueue;
            $queue->enqueue($origin);
            $discovered[$origin] = true;

            while (! $queue->isEmpty()) {
                $node = $queue->dequeue();
                $visitedNodes[] = $node;
                $trace[] = ['visit', $node];

                if ($node === $destination) {
                    $found = true;
                    break;
                }

                foreach ($graph['adjacency'][$node] as $edgeId) {
                    $trace[] = ['edge', $edgeId];
                    $neighbor = $graph['edges'][$edgeId]['to'];

                    if (! isset($discovered[$neighbor])) {
                        $discovered[$neighbor] = true;
                        $parents[$neighbor] = $edgeId;
                        $queue->enqueue($neighbor);
                    }
                }
            }
        } else {
            $frames = [['node' => $origin, 'cursor' => 0]];

            while ($frames !== []) {
                $frameIndex = count($frames) - 1;
                $node = $frames[$frameIndex]['node'];

                if (! isset($discovered[$node])) {
                    $discovered[$node] = true;
                    $visitedNodes[] = $node;
                    $trace[] = ['visit', $node];

                    if ($node === $destination) {
                        $found = true;
                        break;
                    }
                }

                $cursor = $frames[$frameIndex]['cursor'];

                if ($cursor >= count($graph['adjacency'][$node])) {
                    array_pop($frames);

                    continue;
                }

                $edgeId = $graph['adjacency'][$node][$cursor];
                $frames[$frameIndex]['cursor']++;
                $trace[] = ['edge', $edgeId];
                $neighbor = $graph['edges'][$edgeId]['to'];

                if (! isset($discovered[$neighbor])) {
                    $parents[$neighbor] = $edgeId;
                    $frames[] = ['node' => $neighbor, 'cursor' => 0];
                }
            }
        }

        $searchMs = (hrtime(true) - $startedAt) / 1_000_000.0;
        $routeNodes = [];
        $routeEdges = [];
        $distance = null;

        if ($found) {
            $node = $destination;
            $routeNodes[] = $node;
            $distance = 0.0;

            while ($node !== $origin) {
                $edgeId = $parents[$node];
                $routeEdges[] = $edgeId;
                $distance += $graph['edges'][$edgeId]['distance_m'];
                $node = $graph['edges'][$edgeId]['from'];
                $routeNodes[] = $node;
            }

            $routeNodes = array_reverse($routeNodes);
            $routeEdges = array_reverse($routeEdges);
        }

        return [
            'status' => $found ? 'found' : 'unreachable',
            'route_nodes' => $routeNodes,
            'route_edges' => $routeEdges,
            'visited_nodes' => $visitedNodes,
            'trace' => $trace,
            'metrics' => [
                'visited_nodes' => count($visitedNodes),
                'segments' => $found ? count($routeEdges) : null,
                'distance_m' => $distance,
                'search_ms' => $searchMs,
            ],
        ];
    }
}
