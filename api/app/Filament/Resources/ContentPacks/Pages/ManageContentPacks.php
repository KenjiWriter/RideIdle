<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentPacks\Pages;

use App\Content\ContentException;
use App\Content\ContentPackInvalid;
use App\Filament\Resources\ContentPacks\ContentPackResource;
use App\Filament\Resources\ContentPublications\ContentPublicationResource;
use App\Models\Admin;
use App\Services\ContentPublisher;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ManageContentPacks extends ManageRecords
{
    protected static string $resource = ContentPackResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label(__('admin.packs.import'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn (): bool => ContentPublicationResource::mayPublish())
                ->modalHeading(__('admin.packs.import'))
                ->modalDescription(__('admin.packs.import_description'))
                ->schema([
                    FileUpload::make('bundle')->label(__('admin.packs.import_file'))->required()->storeFiles(false)->acceptedFileTypes(['application/json', 'text/plain'])->maxSize(20480),
                    TextInput::make('number')->label(__('admin.packs.number'))->helperText(__('admin.packs.import_number_hint'))->regex('/^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$/'),
                    Toggle::make('activate')->label(__('admin.packs.import_activate'))->default(false),
                ])
                ->action(function (array $data): void {
                    abort_unless(ContentPublicationResource::mayPublish(), 403);

                    /** @var Admin $admin */
                    $admin = Auth::guard('admin')->user();
                    $file = $data['bundle'];
                    $text = $file instanceof TemporaryUploadedFile ? $file->get() : (string) file_get_contents($file->getRealPath());

                    try {
                        $import = app(ContentPublisher::class)->import($admin, (string) $text, (bool) ($data['activate'] ?? false), $data['number'] ?? null);
                    } catch (ContentPackInvalid $problem) {
                        Notification::make()
                            ->danger()
                            ->title(__('admin.packs.import_refused'))
                            ->body(implode("\n", array_slice(array_map(fn (array $issue): string => "{$issue['file']} {$issue['pointer']}: {$issue['message']}", $problem->result->errors()), 0, 5)))
                            ->persistent()
                            ->send();

                        return;
                    } catch (ContentException $problem) {
                        Notification::make()->danger()->title(__('admin.packs.import_refused'))->body($problem->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(__($import->created ? 'admin.packs.imported' : 'admin.packs.import_existed', ['number' => $import->pack->number]))->send();
                }),
        ];
    }
}
