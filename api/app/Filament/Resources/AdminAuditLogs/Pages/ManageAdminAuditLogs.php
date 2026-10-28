<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminAuditLogs\Pages;

use App\Filament\Resources\AdminAuditLogs\AdminAuditLogResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAdminAuditLogs extends ManageRecords
{
    protected static string $resource = AdminAuditLogResource::class;
}
