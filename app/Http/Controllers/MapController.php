<?php

namespace App\Http\Controllers;

use App\Services\GraphSearch;
use App\Services\MeridaMapData;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class MapController extends Controller
{
    public function __construct(private MeridaMapData $mapData) {}

    public function home(): View
    {
        $metadata = null;
        $error = null;
        try {
            $metadata = $this->mapData->metadata();
        } catch (UnexpectedValueException $exception) {
            $error = $exception->getMessage();
        }

        return view('welcome', ['mapMetadata' => $metadata, 'mapError' => $error]);
    }

    public function parks(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'version' => ['required', 'string', 'max:100']], $this->messages());

        return $this->withData(function () use ($data): array {
            $this->checkVersion($data['version']);

            return $this->mapData->parks($data['q'] ?? '');
        });
    }

    public function map(Request $request): JsonResponse
    {
        $data = $request->validate([
            'west' => ['required', 'numeric', 'between:-180,180'],
            'south' => ['required', 'numeric', 'between:-90,90'],
            'east' => ['required', 'numeric', 'between:-180,180'],
            'north' => ['required', 'numeric', 'between:-90,90'],
            'zoom' => ['required', 'numeric', 'between:0,22'],
            'version' => ['required', 'string', 'max:100'],
        ], $this->messages());
        if ($data['west'] >= $data['east'] || $data['south'] >= $data['north']) {
            throw ValidationException::withMessages(['west' => 'El área visible del mapa no es válida.']);
        }

        return $this->withData(function () use ($data): array {
            $this->checkVersion($data['version']);

            return $this->mapData->map(array_map('floatval', [$data['west'], $data['south'], $data['east'], $data['north']]));
        });
    }

    public function origin(Request $request): JsonResponse
    {
        $data = $request->validate($this->originRules(), $this->messages());

        return $this->withData(function () use ($data): array {
            $this->checkVersion($data['version']);

            return ['version' => $data['version'], 'origin' => $this->mapData->connect((float) $data['longitude'], (float) $data['latitude'])];
        });
    }

    public function search(Request $request, GraphSearch $search): JsonResponse
    {
        $data = $request->validate([
            ...$this->originRules(),
            'park_id' => ['required', 'string', 'max:100'],
            'method' => ['required', Rule::in(['bfs', 'dfs', 'both'])],
        ], $this->messages());

        return $this->withData(function () use ($data, $search): array {
            $this->checkVersion($data['version']);
            $origin = $this->mapData->connect((float) $data['longitude'], (float) $data['latitude']);
            $destination = $this->mapData->destination($data['park_id']);
            $graph = $this->mapData->graph();
            $results = [];
            foreach ($data['method'] === 'both' ? ['bfs', 'dfs'] : [$data['method']] as $method) {
                $results[$method] = $search->run($graph, $origin['node_id'], $destination['node_id'], $method);
            }
            $winner = null;
            if (isset($results['bfs'], $results['dfs']) && $results['bfs']['status'] === 'found' && $results['dfs']['status'] === 'found') {
                $bfs = (int) round($results['bfs']['metrics']['distance_m']);
                $dfs = (int) round($results['dfs']['metrics']['distance_m']);
                $winner = $bfs === $dfs ? 'tie' : ($bfs < $dfs ? 'bfs' : 'dfs');
            }

            return [
                'version' => $data['version'], 'origin' => $origin, 'destination' => $destination,
                'results' => (object) $results, 'network' => $this->mapData->network($results),
                'comparison' => ['winner' => $winner],
            ];
        });
    }

    private function originRules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'version' => ['required', 'string', 'max:100'],
        ];
    }

    private function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.', 'numeric' => 'El campo :attribute debe ser un número.',
            'string' => 'El campo :attribute debe ser texto.', 'between' => 'El campo :attribute está fuera del intervalo permitido.',
            'max' => 'El campo :attribute excede el tamaño permitido.', 'method.in' => 'Selecciona BFS, DFS o Comparar ambos.',
        ];
    }

    private function checkVersion(string $version): void
    {
        abort_if($version !== $this->mapData->metadata()['version'], 409, 'Los datos locales cambiaron. Recarga la página para usar la misma versión del mapa.');
    }

    private function withData(Closure $operation): JsonResponse
    {
        try {
            return response()->json($operation());
        } catch (UnexpectedValueException $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }
}
