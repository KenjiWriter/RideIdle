<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentPacks\Pages;

use App\Filament\Resources\ContentPacks\ContentPackResource;
use Filament\Resources\Pages\ManageRecords;

class ManageContentPacks extends ManageRecords
{
    protected static string $resource = ContentPackResource::class;
}
