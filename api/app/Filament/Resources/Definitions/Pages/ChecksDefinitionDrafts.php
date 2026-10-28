<?php

declare(strict_types=1);

namespace App\Filament\Resources\Definitions\Pages;

use App\Content\Definitions\DefinitionForm;
use App\Content\Definitions\DefinitionRefused;
use App\Filament\Resources\Definitions\DefinitionResource;
use App\Services\ContentDrafts;
use Filament\Notifications\Notification;

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
