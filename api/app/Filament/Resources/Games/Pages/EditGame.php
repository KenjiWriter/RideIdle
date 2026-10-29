<?php

declare(strict_types=1);

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Services\AdminAudit;
use Filament\Resources\Pages\EditRecord;

/** Changes a game: its name, its domain, its colour, whether it is live. Its slug and which one is the default are not changed here. */
class EditGame extends EditRecord
{
    use HandlesGameIcons;

    protected static string $resource = GameResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->withIcons($data);
    }

    protected function afterSave(): void
    {
        /** @var Game $game */
        $game = $this->getRecord();
        $changes = array_values(array_diff(array_keys($game->getChanges()), ['updated_at']));

        if ($changes !== []) {
            app(AdminAudit::class)->record('game_updated', subject: "game:{$game->slug}", context: ['changes' => $changes]);
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('admin.games.saved');
    }
}
