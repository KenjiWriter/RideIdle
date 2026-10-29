<?php

declare(strict_types=1);

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Resources\Players\PlayerResource;
use App\Games\CurrentGame;
use App\Services\AdminAudit;
use App\Services\PlayersCsv;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListPlayers extends ListRecords
{
    protected static string $resource = PlayerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label(__('admin.players.csv'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->downloadCsv()),
        ];
    }

    /** The accounts that the list shows now (its filters, its search and its order) as a CSV file, with a line in the trail of the panel about who took them. */
    public function downloadCsv(): StreamedResponse
    {
        $players = $this->getTableQueryForExport();
        $slug = (string) app(CurrentGame::class)->game()?->slug;

        app(AdminAudit::class)->record('players_exported', subject: "game:{$slug}", context: [
            'players' => (clone $players)->reorder()->count(),
            'filters' => $this->activeFilters(),
            'searched' => filled($this->tableSearch),
        ]);

        return response()->streamDownload(function () use ($players): void {
            $output = fopen('php://output', 'w');
            app(PlayersCsv::class)->write($players, $output);
            fclose($output);
        }, "players-{$slug}-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The filters that were set, as a name and what it was set to.
     *
     * @return array<string, mixed>
     */
    private function activeFilters(): array
    {
        $set = [];

        foreach ((array) $this->tableFilters as $name => $data) {
            $value = is_array($data) ? ($data['value'] ?? ($data['isActive'] ?? null)) : $data;

            if (filled($value) && $value !== false) {
                $set[$name] = $value;
            }
        }

        return $set;
    }
}
