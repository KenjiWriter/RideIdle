<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentPublications\Pages;

use App\Content\Publishing\PublicationRefused;
use App\Filament\Resources\ContentPublications\ContentPublicationResource;
use App\Models\Admin;
use App\Services\ContentPublisher;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageContentPublications extends ManageRecords
{
    protected static string $resource = ContentPublicationResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepare')
                ->label(__('admin.publications.prepare'))
                ->icon('heroicon-o-rocket-launch')
                ->visible(fn (): bool => ContentPublicationResource::mayPublish())
                ->modalHeading(__('admin.publications.prepare_heading'))
                ->modalDescription(__('admin.publications.prepare_description'))
                ->modalContent(fn () => view('filament.publications.changes', ['changes' => app(ContentPublisher::class)->changes()]))
                ->modalSubmitActionLabel(__('admin.publications.prepare_submit'))
                ->schema([
                    TextInput::make('note')->label(__('admin.publications.note'))->helperText(__('admin.publications.note_hint'))->maxLength(200),
                ])
                ->action(function (array $data): void {
                    abort_unless(ContentPublicationResource::mayPublish(), 403);

                    /** @var Admin $admin */
                    $admin = Auth::guard('admin')->user();

                    try {
                        $publication = app(ContentPublisher::class)->queue($admin, $data['note'] ?? null);
                    } catch (PublicationRefused $refusal) {
                        Notification::make()->danger()->title(__("admin.publications.refused.{$refusal->reason}", $refusal->params))->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(__('admin.publications.queued', ['number' => $publication->number]))->send();
                }),
        ];
    }
}
