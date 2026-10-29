<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Pages;

use App\Content\Definitions\DefinitionForm;
use App\Content\Definitions\DefinitionRefused;
use App\Filament\Resources\Definitions\DefinitionResource;
use App\Services\ContentDrafts;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Makes the first draft of a car or of a part. It is kept only when its document passes the schema, and says what is wrong when it does not. */
abstract class CreateDefinition extends CreateRecord
{
    use ChecksDefinitionDrafts;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->withImage($data);
        $this->checkOrHalt($data);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var class-string<DefinitionResource> $resource */
        $resource = static::getResource();

        try {
            return app(ContentDrafts::class)->create($resource::kind(), DefinitionForm::toDocument($resource::kind(), $data, app(ContentDrafts::class)->workingModel()), Auth::guard('admin')->user());
        } catch (DefinitionRefused $refusal) {
            $this->refuse($refusal);
        }
    }

    protected function afterCreate(): void
    {
        $this->warnAboutLanguages();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('admin.definitions.created');
    }
}
