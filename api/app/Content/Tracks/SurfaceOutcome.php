<?php

declare(strict_types=1);

namespace App\Content\Tracks;

/**
 * One car on one surface with each tire of the pack: the proof of the design that no tire is the best everywhere. The surface is
 * driven on a track and in a weather of the rules that make it, with nothing on the car but the tires.
 */
final readonly class SurfaceOutcome
{
    /**
     * @param  string  $surface  one of the surfaces of `Surface`
     * @param  string  $track  the track the surface is driven on
     * @param  string  $weather  the weather that makes the surface of that track
     * @param  string  $vehicle  the car that drives it
     * @param  array<string, int>  $lapMsByTires  the lap in ms, by slug of the tires
     * @param  array<string, string>  $kindByTires  the kind of the tires, by slug
     */
    public function __construct(
        public string $surface,
        public string $track,
        public string $weather,
        public string $vehicle,
        public array $lapMsByTires,
        public array $kindByTires,
    ) {}

    /** The tires with the shortest lap. When two have the same, the first of them. */
    public function bestTires(): string
    {
        return (string) array_search(min($this->lapMsByTires), $this->lapMsByTires, true);
    }

    public function bestKind(): string
    {
        return $this->kindByTires[$this->bestTires()];
    }
}
