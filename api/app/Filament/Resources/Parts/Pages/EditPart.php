<?php

declare(strict_types=1);

namespace App\Filament\Resources\Parts\Pages;

use App\Filament\Resources\Definitions\Pages\EditDefinition;
use App\Filament\Resources\Parts\PartResource;

class EditPart extends EditDefinition
{
    protected static string $resource = PartResource::class;
}
