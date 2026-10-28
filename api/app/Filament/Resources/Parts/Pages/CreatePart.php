<?php

declare(strict_types=1);

namespace App\Filament\Resources\Parts\Pages;

use App\Filament\Resources\Definitions\Pages\CreateDefinition;
use App\Filament\Resources\Parts\PartResource;

class CreatePart extends CreateDefinition
{
    protected static string $resource = PartResource::class;
}
