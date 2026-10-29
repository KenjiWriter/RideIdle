<?php

declare(strict_types=1);

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Services\AdminAudit;
use Filament\Resources\Pages\CreateRecord;

/** Makes a game: it starts as a draft, which answers to nobody until it is made live. */
class CreateGame extends CreateRecord
{
    use HandlesGameIcons;

    protected static string $resource = GameResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->withIcons($data);
    }

    protected function afterCreate(): void
    {
        /** @var Game $game */
        $game = $this->getRecord();

        app(AdminAudit::class)->record('game_created', subject: "game:{$game->slug}", context: ['name' => $game->name, 'domain' => $game->domain, 'status' => $game->status]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('admin.games.created');
    }
}
