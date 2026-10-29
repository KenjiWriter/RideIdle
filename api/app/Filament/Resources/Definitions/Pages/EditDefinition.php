<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Pages;

use App\Content\Definitions\DefinitionForm;
use App\Content\Definitions\DefinitionRefused;
use App\Filament\Resources\Definitions\DefinitionResource;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;
use App\Services\DefinitionLifecycle;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Changes the draft of a car or of a part: the form starts from the draft, or from what was published when there is none, and saving keeps a draft that passes the
 * schema (or no draft, when it says what was published says). A publisher also moves the definition in its life from here.
 */
abstract class EditDefinition extends EditRecord
{
    use ChecksDefinitionDrafts;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var class-string<DefinitionResource> $resource */
        $resource = static::getResource();

        return DefinitionForm::toState($resource::kind(), $this->definition()->workingDocument());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->withImage($data);
        $this->checkOrHalt($data, $this->definition()->slug);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var class-string<DefinitionResource> $resource */
        $resource = static::getResource();

        try {
            return app(ContentDrafts::class)->save($record, DefinitionForm::toDocument($resource::kind(), $data, app(ContentDrafts::class)->workingModel()), $this->admin());
        } catch (DefinitionRefused $refusal) {
            $this->refuse($refusal);
        }
    }

    protected function afterSave(): void
    {
        $this->warnAboutLanguages();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('admin.definitions.saved');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('discard')
                ->label(__('admin.definitions.discard'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('admin.definitions.discard_description'))
                ->visible(fn (): bool => $this->definition()->hasDraft() && $this->definition()->versions()->exists())
                ->action(fn () => $this->move(fn (ContentDrafts $drafts) => $drafts->discard($this->definition(), $this->admin()))),
            Action::make('withdraw')
                ->label(__('admin.definitions.withdraw'))
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('admin.definitions.withdraw_description'))
                ->visible(fn (): bool => $this->admin()->isPublisher() && $this->definition()->status === ContentDefinition::STATUS_ACTIVE)
                ->action(fn () => $this->move(fn ($unused, DefinitionLifecycle $life) => $life->withdraw($this->definition(), $this->admin()))),
            Action::make('restore')
                ->label(__('admin.definitions.restore'))
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->admin()->isPublisher() && in_array($this->definition()->status, [ContentDefinition::STATUS_WITHDRAWN, ContentDefinition::STATUS_ARCHIVED], true))
                ->action(fn () => $this->move(fn ($unused, DefinitionLifecycle $life) => $life->restore($this->definition(), $this->admin()))),
            Action::make('archive')
                ->label(__('admin.definitions.archive'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('admin.definitions.archive_description'))
                ->visible(fn (): bool => $this->admin()->isPublisher() && $this->definition()->status === ContentDefinition::STATUS_WITHDRAWN)
                ->action(fn () => $this->move(fn ($unused, DefinitionLifecycle $life) => $life->archive($this->definition(), $this->admin()))),
            Action::make('delete')
                ->label(__('admin.definitions.delete'))
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('admin.definitions.delete_description'))
                ->visible(fn (): bool => $this->definition()->status === ContentDefinition::STATUS_DRAFT && $this->definition()->versions()->doesntExist())
                ->action(function (): void {
                    try {
                        app(ContentDrafts::class)->delete($this->definition(), $this->admin());
                    } catch (DefinitionRefused $refusal) {
                        $this->refuse($refusal);
                    }

                    $this->redirect(static::getResource()::getUrl('index'));
                }),
        ];
    }

    /**
     * Does a change of the definition, says what it did, and starts the form again from what the definition is now. A refusal is said and nothing changes.
     *
     * @param  callable(ContentDrafts, DefinitionLifecycle): mixed  $change
     */
    private function move(callable $change): void
    {
        try {
            $change(app(ContentDrafts::class), app(DefinitionLifecycle::class));
        } catch (DefinitionRefused $refusal) {
            Notification::make()->danger()->title(__("admin.definitions.refused.{$refusal->reason}", $refusal->params))->persistent()->send();

            return;
        }

        $this->record->refresh();
        $this->fillForm();

        Notification::make()->success()->title(__('admin.definitions.done'))->send();
    }

    private function definition(): ContentDefinition
    {
        /** @var ContentDefinition $record */
        $record = $this->getRecord();

        return $record;
    }

    private function admin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
