<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Pages;

use App\Content\Definitions\DefinitionForm;
use App\Content\Definitions\DefinitionRefused;
use App\Content\ImageRefused;
use App\Filament\Resources\Definitions\DefinitionResource;
use App\Services\ContentDrafts;
use App\Services\ContentImages;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * What the pages that make and change drafts do the same way: the document that the form makes is checked by the schema before anything is kept, and what is wrong
 * is said (with the place in the document), and a language that is missing is said after the draft was kept.
 */
trait ChecksDefinitionDrafts
{
    /** @var list<string> the languages that the draft that was just kept has no text in */
    protected array $missingLanguages = [];

    /**
     * @param  array<string, mixed>  $state
     */
    protected function checkOrHalt(array $state, ?string $slug = null): void
    {
        /** @var class-string<DefinitionResource> $resource */
        $resource = static::getResource();
        $result = app(ContentDrafts::class)->validate($resource::kind(), DefinitionForm::toDocument($resource::kind(), $state), $slug);

        $this->missingLanguages = [];

        foreach ($result->warnings() as $issue) {
            if (preg_match('#^/(?:name|description)/([A-Za-z-]+)$#', $issue['pointer'], $found) === 1) {
                $this->missingLanguages[$found[1]] = $found[1];
            }
        }

        $this->missingLanguages = array_values($this->missingLanguages);

        if ($result->isValid()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('admin.definitions.invalid'))
            ->body(implode("\n", array_map(fn (array $issue): string => ($issue['pointer'] === '' ? '' : "{$issue['pointer']}: ").$issue['message'], array_slice($result->errors(), 0, 8))))
            ->persistent()
            ->send();

        $this->halt();
    }

    /**
     * The state of the form with the picture in it: a file that was chosen is kept and its name is the picture of the draft, a picture that is taken off is no
     * picture, and the two boxes of the form that are not a part of the document are gone. A file that the game does not take as a picture is said, and nothing is kept.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function withImage(array $state): array
    {
        $upload = $state['image_upload'] ?? null;
        $upload = is_array($upload) ? Arr::first($upload) : $upload;

        if (! empty($state['image_remove'])) {
            $state['image'] = null;
        }

        unset($state['image_upload'], $state['image_remove']);

        $bytes = match (true) {
            $upload instanceof TemporaryUploadedFile => $upload->get(),
            $upload instanceof UploadedFile => (string) file_get_contents($upload->getRealPath()),
            default => null,
        };

        if ($bytes === null) {
            return $state;
        }

        try {
            $state['image'] = app(ContentImages::class)->store((string) $bytes);
        } catch (ImageRefused $refusal) {
            Notification::make()->danger()->title(__("admin.definitions.image_refused.{$refusal->reason}", $refusal->params))->persistent()->send();

            $this->halt();
        }

        return $state;
    }

    protected function warnAboutLanguages(): void
    {
        if ($this->missingLanguages === []) {
            return;
        }

        Notification::make()->warning()->title(__('admin.definitions.missing_languages', ['languages' => implode(', ', $this->missingLanguages)]))->send();
    }

    protected function refuse(DefinitionRefused $refusal): never
    {
        Notification::make()->danger()->title(__("admin.definitions.refused.{$refusal->reason}", $refusal->params))->persistent()->send();

        $this->halt();
    }
}
