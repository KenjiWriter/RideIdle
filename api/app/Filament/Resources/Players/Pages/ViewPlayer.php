<?php

declare(strict_types=1);

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\User;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** One account: who it is, how far it got, its cars, its purchases and the latest postings of its ledger. Nothing on it can be changed. */
class ViewPlayer extends ViewRecord
{
    protected static string $resource = PlayerResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var User $player */
        $player = $this->getRecord();

        return $player->name;
    }
}
