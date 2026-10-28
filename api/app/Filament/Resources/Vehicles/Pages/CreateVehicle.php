<?php

declare(strict_types=1);

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Resources\Definitions\Pages\CreateDefinition;
use App\Filament\Resources\Vehicles\VehicleResource;

class CreateVehicle extends CreateDefinition
{
    protected static string $resource = VehicleResource::class;
}
