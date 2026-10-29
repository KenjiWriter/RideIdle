<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\ContentException;
use App\Content\ContentPackInvalid;
use App\Content\ContentSource;
use App\Content\FileContentSource;
use App\Exceptions\DomainJobRefused;
use App\Exceptions\GameStartRefused;
use App\Games\CurrentGame;
use App\Models\Admin;
use App\Models\Game;

/**
 * Makes a game the way the wizard of the panel asks: the row of the game, the content it starts from, and (when it has a domain) the request to the server to set the domain
 * up. The game is a draft, which answers to nobody until somebody makes it live. What cannot be done after the game is made (the content does not pass the validation, nothing
 * is live in the game it was to be copied from, the request is refused) is said in the warnings and does not undo the game: it is there, and the panel says what is left to do.
 */
final class GameCreator
{
    /** The game starts from the car game: the content of the repository, which is what the first game plays. */
    public const START_TEMPLATE = 'template';

    /** The game starts from a copy of the pack that another game plays now. */
    public const START_COPY = 'copy';

    /** The game starts with no content: it has to be given a pack before it can be made live. */
    public const START_NONE = 'none';

    public const STARTS = [self::START_TEMPLATE, self::START_COPY, self::START_NONE];

    public function __construct(
        private readonly CurrentGame $current,
        private readonly ContentPacks $packs,
        private readonly DomainJobs $jobs,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  the columns of the game: slug, name, domain, locale, theme colour
     * @param  string|null  $copyFrom  the slug of the game whose pack is copied (with the start `copy`)
     * @return array{game: Game, started: string|null, warnings: list<array{key: string, detail: string}>}
     */
    public function create(array $attributes, string $start = self::START_TEMPLATE, ?string $copyFrom = null, bool $requestDomain = false, ?Admin $by = null, bool $checkTables = true): array
    {
        $game = Game::query()->create([...$attributes, 'status' => Game::STATUS_DRAFT, 'is_default' => false]);
        $warnings = [];
        $started = null;

        try {
            $started = $this->current->within($game, fn (): ?string => $this->startContent($start, $copyFrom, $checkTables));
        } catch (ContentPackInvalid|ContentException $problem) {
            $warnings[] = ['key' => 'content', 'detail' => $problem->getMessage()];
        } catch (GameStartRefused $problem) {
            $warnings[] = ['key' => $problem->reason, 'detail' => $problem->getMessage()];
        }

        if ($requestDomain) {
            try {
                $this->jobs->request($game, 'provision', $by);
            } catch (DomainJobRefused $problem) {
                $warnings[] = ['key' => "domain_{$problem->reason}", 'detail' => ''];
            }
        }

        return ['game' => $game, 'started' => $started, 'warnings' => $warnings];
    }

    /** Puts the first pack of the game in force and makes it live; the number of the pack, or null when the game starts with none. */
    private function startContent(string $start, ?string $copyFrom, bool $checkTables): ?string
    {
        $source = match ($start) {
            self::START_TEMPLATE => new FileContentSource((string) config('ride.content_path'), 'core'),
            self::START_COPY => $this->copyOf($copyFrom),
            default => null,
        };

        if ($source === null) {
            return null;
        }

        return $this->packs->import($source, reason: 'game created', activate: true, checkTables: $checkTables)->pack->number;
    }

    /** @throws GameStartRefused */
    private function copyOf(?string $slug): ContentSource
    {
        $from = $slug === null ? null : Game::query()->where('slug', $slug)->first();

        if ($from === null) {
            throw new GameStartRefused('copy_missing', 'There is no such game to copy.');
        }

        $source = $this->current->within($from, fn (): ?ContentSource => $this->packs->activeSource());

        return $source ?? throw new GameStartRefused('copy_empty', "{$from->name} has no live pack to copy.");
    }
}
