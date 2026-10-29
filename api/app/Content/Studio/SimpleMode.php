<?php

declare(strict_types=1);

namespace App\Content\Studio;

/**
 * Which numbers of the rules the studio shows in its simple mode: the ones that set the pace and the money of the game, picked by their pointer in `ride.studio.simple` (a star
 * stands for one name in the pointer: a track, a product, a rung). Every other number is still there in the advanced mode.
 */
final class SimpleMode
{
    /** @var list<string> the patterns as a regular expression each */
    private readonly array $patterns;

    /** @param  list<string>|null  $patterns  the pointers with stars (the list of the configuration when left out) */
    public function __construct(?array $patterns = null)
    {
        $this->patterns = array_map(
            static fn (string $pointer): string => '#^'.str_replace('\*', '[^/]+', preg_quote($pointer, '#')).'$#',
            $patterns ?? (array) config('ride.studio.simple', []),
        );
    }

    public function keeps(Parameter $parameter): bool
    {
        $pointer = $parameter->pointer();

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $pointer) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The numbers of a list that the simple mode shows, at the positions they have in the whole section.
     *
     * @param  list<Parameter>  $parameters
     * @return array<int, Parameter>
     */
    public function only(array $parameters): array
    {
        return array_filter($parameters, fn (Parameter $parameter): bool => $this->keeps($parameter));
    }
}
