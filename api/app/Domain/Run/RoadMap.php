<?php

declare(strict_types=1);

namespace App\Domain\Run;

/**
 * The roads between the cities. They run both ways, and a car takes the shortest way, which is found with Dijkstra: the map is a handful
 * of cities, so nothing cleverer is called for.
 */
final readonly class RoadMap
{
    /** @param list<array{from: string, to: string, km: int}> $roads */
    public function __construct(private array $roads) {}

    /** The length of the shortest way from one city to another in km: 0 for the same city, null when no road leads there. */
    public function distanceKm(string $from, string $to): ?int
    {
        if ($from === $to) {
            return 0;
        }

        $distance = [$from => 0];
        $unvisited = [$from => true];

        while ($unvisited !== []) {
            // The nearest city that has not been settled yet.
            $nearest = $this->nearest($distance, $unvisited);
            unset($unvisited[$nearest]);

            if ($nearest === $to) {
                return $distance[$to];
            }

            foreach ($this->roads as $road) {
                $other = match ($nearest) {
                    $road['from'] => $road['to'],
                    $road['to'] => $road['from'],
                    default => null,
                };

                if ($other !== null && (! isset($distance[$other]) || $distance[$other] > $distance[$nearest] + $road['km'])) {
                    $distance[$other] = $distance[$nearest] + $road['km'];
                    $unvisited[$other] = true;
                }
            }
        }

        return null;
    }

    /**
     * The cities a road leads to directly from a city.
     *
     * @return list<string>
     */
    public function neighbours(string $city): array
    {
        $neighbours = [];

        foreach ($this->roads as $road) {
            if ($road['from'] === $city) {
                $neighbours[] = $road['to'];
            } elseif ($road['to'] === $city) {
                $neighbours[] = $road['from'];
            }
        }

        return $neighbours;
    }

    /**
     * @param  array<string, int>  $distance
     * @param  array<string, true>  $unvisited
     */
    private function nearest(array $distance, array $unvisited): string
    {
        $nearest = null;

        foreach (array_keys($unvisited) as $city) {
            if ($nearest === null || $distance[$city] < $distance[$nearest]) {
                $nearest = $city;
            }
        }

        return (string) $nearest;
    }
}
