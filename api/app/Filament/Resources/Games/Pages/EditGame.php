<?php

declare(strict_types=1);

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Concerns\ShowsChanges;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Services\AdminAudit;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/** Changes a game: its name, its domain, its colour, whether it is live. Its slug and which one is the default are not changed here. */
class EditGame extends EditRecord
{
    use HandlesGameIcons;
    use ShowsChanges;

    protected static string $resource = GameResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->withIcons($data);
    }

    protected function afterFill(): void
    {
        $this->rememberSavedState();
    }

    protected function afterSave(): void
    {
        $this->rememberSavedState();

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

    protected function changesEffectsGroup(): ?string
    {
        return 'game';
    }

    /** What is changed and not saved, how the game will look with it, and then the form. */
    public function content(Schema $schema): Schema
    {
        /** @var Game $game */
        $game = $this->getRecord();

        return $schema->components([
            $this->changesBar(),
            View::make('filament.games.preview')->viewData(['installation' => (string) parse_url((string) config('ride.web_url'), PHP_URL_HOST), 'fallback' => $game->name]),
            $this->getFormContentComponent(),
        ]);
    }
}
