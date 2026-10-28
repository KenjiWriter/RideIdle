<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Resources\Definitions\Pages\EditDefinition;
use App\Filament\Resources\Vehicles\VehicleResource;

class EditVehicle extends EditDefinition
{
    protected static string $resource = VehicleResource::class;
}
