<?php

namespace App\Services;

use InvalidArgumentException;

class MapGeometry
{
    private const Epsilon = 1.0e-10;

    /** @param array{type:string, coordinates:array} $geometry */
    public static function contains(array $geometry, array $point): bool
    {
        foreach (self::polygons($geometry) as $polygon) {
            if (! self::inRing($polygon[0], $point)) {
                continue;
            }

            foreach (array_slice($polygon, 1) as $hole) {
                if (self::inRing($hole, $point)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /** @param array{0:float, 1:float} $a
     * @param  array{0:float, 1:float}  $b
     */
    public static function distance(array $a, array $b): float
    {
        $latitude = deg2rad($b[1] - $a[1]);
        $longitude = deg2rad($b[0] - $a[0]);
        $haversine = sin($latitude / 2) ** 2
            + cos(deg2rad($a[1])) * cos(deg2rad($b[1])) * sin($longitude / 2) ** 2;

        return 6371008.8 * 2 * asin(sqrt(min(1.0, max(0.0, $haversine))));
    }

    /** @return array{0:float, 1:float, 2:float, 3:float} */
    public static function bounds(array $geometry): array
    {
        $points = match ($geometry['type']) {
            'Point' => [$geometry['coordinates']],
            'LineString' => $geometry['coordinates'],
            'Polygon' => array_merge(...$geometry['coordinates']),
            'MultiPolygon' => array_merge(...array_map(static fn (array $polygon): array => array_merge(...$polygon), $geometry['coordinates'])),
            default => throw new InvalidArgumentException('Tipo de geometría no admitido.'),
        };

        if ($points === []) {
            throw new InvalidArgumentException('Geometría vacía.');
        }

        $longitudes = array_column($points, 0);
        $latitudes = array_column($points, 1);

        return [min($longitudes), min($latitudes), max($longitudes), max($latitudes)];
    }

    /** Reject crossings or touches between non-adjacent edges of a closed ring. */
    public static function assertSimpleRing(array $ring): void
    {
        $segments = count($ring) - 1;
        for ($i = 0; $i < $segments; $i++) {
            $a = $ring[$i];
            $b = $ring[$i + 1];
            if ($a == $b) {
                throw new InvalidArgumentException('Anillo con coordenadas consecutivas duplicadas.');
            }
            for ($j = $i + 2; $j < $segments; $j++) {
                if ($i === 0 && $j === $segments - 1) {
                    continue;
                }
                $c = $ring[$j];
                $d = $ring[$j + 1];
                if (max($a[0], $b[0]) < min($c[0], $d[0]) - self::Epsilon
                    || min($a[0], $b[0]) > max($c[0], $d[0]) + self::Epsilon
                    || max($a[1], $b[1]) < min($c[1], $d[1]) - self::Epsilon
                    || min($a[1], $b[1]) > max($c[1], $d[1]) + self::Epsilon) {
                    continue;
                }
                if (self::intersectionParameters($a, $b, $c, $d) !== []) {
                    throw new InvalidArgumentException('Anillo autointersectado: sus segmentos no adyacentes se cruzan o se tocan.');
                }
            }
        }
    }

    /**
     * Clip each straight OSM segment, retaining disjoint intervals and holes.
     *
     * @return list<array{start:float, end:float, a:array, b:array}>
     */
    public static function clipSegment(array $geometry, array $a, array $b): array
    {
        if ($a[0] == $b[0] && $a[1] == $b[1]) {
            return [];
        }

        $cuts = [0.0, 1.0];
        foreach (self::polygons($geometry) as $polygon) {
            foreach ($polygon as $ring) {
                for ($i = 1, $count = count($ring); $i < $count; $i++) {
                    $c = $ring[$i - 1];
                    $d = $ring[$i];
                    if (max($a[0], $b[0]) < min($c[0], $d[0]) - self::Epsilon
                        || min($a[0], $b[0]) > max($c[0], $d[0]) + self::Epsilon
                        || max($a[1], $b[1]) < min($c[1], $d[1]) - self::Epsilon
                        || min($a[1], $b[1]) > max($c[1], $d[1]) + self::Epsilon) {
                        continue;
                    }

                    array_push($cuts, ...self::intersectionParameters($a, $b, $c, $d));
                }
            }
        }

        sort($cuts, SORT_NUMERIC);
        $unique = [];
        foreach ($cuts as $cut) {
            if ($unique === [] || abs($cut - $unique[array_key_last($unique)]) > self::Epsilon) {
                $unique[] = $cut;
            }
        }

        $parts = [];
        for ($i = 1, $count = count($unique); $i < $count; $i++) {
            $start = $unique[$i - 1];
            $end = $unique[$i];
            if (! self::contains($geometry, self::interpolate($a, $b, ($start + $end) / 2))) {
                continue;
            }

            $last = array_key_last($parts);
            if ($last !== null && abs($parts[$last]['end'] - $start) <= self::Epsilon) {
                $parts[$last]['end'] = $end;
                $parts[$last]['b'] = self::interpolate($a, $b, $end);
            } else {
                $parts[] = ['start' => $start, 'end' => $end, 'a' => self::interpolate($a, $b, $start), 'b' => self::interpolate($a, $b, $end)];
            }
        }

        return $parts;
    }

    /** @return list<array> */
    private static function polygons(array $geometry): array
    {
        return match ($geometry['type']) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => throw new InvalidArgumentException('Se requiere un polígono municipal.'),
        };
    }

    private static function inRing(array $ring, array $point): bool
    {
        $inside = false;
        for ($i = 1, $count = count($ring); $i < $count; $i++) {
            [$a, $b] = [$ring[$i - 1], $ring[$i]];
            $cross = ($point[0] - $a[0]) * ($b[1] - $a[1]) - ($point[1] - $a[1]) * ($b[0] - $a[0]);
            $length = hypot($b[0] - $a[0], $b[1] - $a[1]);
            if (abs($cross) <= self::Epsilon * $length
                && $point[0] >= min($a[0], $b[0]) - self::Epsilon && $point[0] <= max($a[0], $b[0]) + self::Epsilon
                && $point[1] >= min($a[1], $b[1]) - self::Epsilon && $point[1] <= max($a[1], $b[1]) + self::Epsilon) {
                return true;
            }

            if (($a[1] > $point[1]) !== ($b[1] > $point[1])
                && $point[0] < ($b[0] - $a[0]) * ($point[1] - $a[1]) / ($b[1] - $a[1]) + $a[0]) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** @return list<float> */
    private static function intersectionParameters(array $a, array $b, array $c, array $d): array
    {
        $r = [$b[0] - $a[0], $b[1] - $a[1]];
        $s = [$d[0] - $c[0], $d[1] - $c[1]];
        $q = [$c[0] - $a[0], $c[1] - $a[1]];
        $cross = $r[0] * $s[1] - $r[1] * $s[0];

        if (abs($cross) < 1.0e-20) {
            if (abs($q[0] * $r[1] - $q[1] * $r[0]) > 1.0e-16) {
                return [];
            }

            $length = $r[0] ** 2 + $r[1] ** 2;
            $start = ($q[0] * $r[0] + $q[1] * $r[1]) / $length;
            $end = (($d[0] - $a[0]) * $r[0] + ($d[1] - $a[1]) * $r[1]) / $length;

            return [max(0.0, min(1.0, $start)), max(0.0, min(1.0, $end))];
        }

        $t = ($q[0] * $s[1] - $q[1] * $s[0]) / $cross;
        $u = ($q[0] * $r[1] - $q[1] * $r[0]) / $cross;

        return $t >= -self::Epsilon && $t <= 1 + self::Epsilon && $u >= -self::Epsilon && $u <= 1 + self::Epsilon
            ? [max(0.0, min(1.0, $t))] : [];
    }

    private static function interpolate(array $a, array $b, float $t): array
    {
        if ($t === 0.0) {
            return $a;
        }

        if ($t === 1.0) {
            return $b;
        }

        return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
    }
}
