<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Definitions\JsonDocument;
use Closure;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Support\Htmlable;
use JsonException;

/**
 * The advanced way: the whole document of the rules or of the tutorial as text, for what the other pages do not show. It is judged exactly as the other pages are:
 * by the schema and by the rules of the world, and it is kept as a draft.
 */
class RulesJsonPage extends RulesDocumentPage
{
    /** The documents that can be edited as text: the ones that have a schema. */
    private const DOCUMENTS = ['starter', 'tutorial'];

    protected static ?string $slug = 'rules';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('admin.json.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.json.title');
    }

    protected function documentSlug(): string
    {
        $chosen = $this->data['document'] ?? null;

        return in_array($chosen, self::DOCUMENTS, true) ? $chosen : self::DOCUMENTS[0];
    }

    protected function fields(array $document): array
    {
        return [
            Select::make('document')
                ->label(__('admin.json.document'))
                ->helperText(__('admin.json.document_hint'))
                ->options(array_combine(self::DOCUMENTS, array_map(fn (string $slug): string => __("admin.json.documents.{$slug}"), self::DOCUMENTS)))
                ->selectablePlaceholder(false)
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $this->forgetDefinition();
                    $set('json', JsonDocument::encode($this->definition()?->workingDocument() ?? []));
                }),
            CodeEditor::make('json')
                ->label(__('admin.json.json'))
                ->language(Language::Json)
                ->required()
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        JsonDocument::decode((string) $value);
                    } catch (JsonException $problem) {
                        $fail(__('admin.json.not_json', ['problem' => $problem->getMessage()]));
                    }
                }),
        ];
    }

    protected function toState(array $document): array
    {
        return ['document' => $this->documentSlug(), 'json' => JsonDocument::encode($document)];
    }

    protected function toDocument(array $document, array $state): array
    {
        return JsonDocument::decode((string) ($state['json'] ?? ''));
    }
}
