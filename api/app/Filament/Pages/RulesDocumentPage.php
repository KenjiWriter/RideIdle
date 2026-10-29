<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\ContentPackInvalid;
use App\Content\Definitions\DefinitionForm;
use App\Content\Definitions\DefinitionRefused;
use App\Filament\Concerns\ForAGame;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Services\ContentDrafts;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * A page that changes a document of the rules (the rules of the world, the tutorial) in sections or as text. What it saves is the draft of the document, kept only
 * when the schema and the rules about the world accept it, and it reaches the game with the next pack that is made live. The form starts from the draft, or from
 * what was published when there is none.
 */
abstract class RulesDocumentPage extends Page implements HasSchemas
{
    use ForAGame;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    private ?ContentDefinition $loaded = null;

    /** The slug of the document of the rules that the page changes. */
    abstract protected function documentSlug(): string;

    /**
     * The fields of the form.
     *
     * @param  array<string, mixed>  $document  the document being worked on
     * @return list<mixed>
     */
    abstract protected function fields(array $document): array;

    /**
     * What the form shows of the document.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    abstract protected function toState(array $document): array;

    /**
     * The document with what the form holds in it.
     *
     * @param  array<string, mixed>  $document  the document being worked on
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    abstract protected function toDocument(array $document, array $state): array;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function form(Schema $schema): Schema
    {
        $definition = $this->definition();

        return $schema->components($definition === null ? [] : $this->fields($definition->workingDocument()))->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        if ($this->definition() === null) {
            return $schema->components([Text::make(__('admin.rules.missing'))]);
        }

        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('admin.rules.save'))->submit('save')->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function getSubheading(): ?string
    {
        $definition = $this->definition();

        if ($definition === null) {
            return null;
        }

        return $definition->hasDraft()
            ? __('admin.definitions.draft_since', ['when' => $definition->draft_saved_at?->format('Y-m-d H:i')])
            : __('admin.rules.no_draft');
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
                ->visible(fn (): bool => $this->definition()?->hasDraft() === true && $this->definition()->versions()->exists())
                ->action(function (): void {
                    try {
                        app(ContentDrafts::class)->discard($this->definition(), $this->admin());
                    } catch (DefinitionRefused $refusal) {
                        $this->say($refusal);

                        return;
                    }

                    $this->fillForm();

                    Notification::make()->success()->title(__('admin.definitions.done'))->send();
                }),
        ];
    }

    /** Keeps the form as the draft of the document, if the document that it makes is accepted, and says what is wrong when it is not. */
    public function save(): void
    {
        $definition = $this->definition();

        if ($definition === null) {
            return;
        }

        $this->keep($definition, $this->toDocument($definition->workingDocument(), $this->form->getState()));
    }

    /** Keeps a document as the draft of the definition, if it is accepted, and says what is wrong when it is not; a form is not the only way to a document. */
    protected function keep(ContentDefinition $definition, array $document): void
    {
        $drafts = app(ContentDrafts::class);
        $result = $drafts->validate($definition->kind, $document, $definition->slug);

        if (! $result->isValid()) {
            Notification::make()
                ->danger()
                ->title(__('admin.definitions.invalid'))
                ->body(implode("\n", array_map(fn (array $issue): string => ($issue['pointer'] === '' ? '' : "{$issue['pointer']}: ").$issue['message'], array_slice($result->errors(), 0, 8))))
                ->persistent()
                ->send();

            return;
        }

        try {
            $drafts->save($definition, $document, $this->admin());
        } catch (ContentPackInvalid) {
            // The document was checked above; this is a document that stopped being valid between the two.
            Notification::make()->danger()->title(__('admin.definitions.invalid'))->persistent()->send();

            return;
        } catch (DefinitionRefused $refusal) {
            $this->say($refusal);

            return;
        }

        $this->fillForm();

        Notification::make()->success()->title(__('admin.definitions.saved'))->send();

        $missing = self::missingLanguages($result->warnings());

        if ($missing !== []) {
            Notification::make()->warning()->title(__('admin.definitions.missing_languages', ['languages' => implode(', ', $missing)]))->send();
        }
    }

    /** Starts the form again from the document as it is now. */
    protected function fillForm(): void
    {
        $this->loaded = null;
        $definition = $this->definition();

        $this->form->fill($definition === null ? [] : $this->toState($definition->workingDocument()));
    }

    protected function definition(): ?ContentDefinition
    {
        return $this->loaded ??= ContentDefinition::query()
            ->where('kind', ContentDefinition::KIND_RULES)
            ->where('slug', $this->documentSlug())
            ->with('latestVersion')
            ->first() ?? $this->unstartedDefinition();
    }

    /**
     * The definition that a page may start, for a document that no pack has brought yet (a database that was made before the document existed): not saved
     * until the first draft is, and it has nothing published. Null, for the documents that every pack has.
     */
    protected function unstartedDefinition(): ?ContentDefinition
    {
        return null;
    }

    protected function forgetDefinition(): void
    {
        $this->loaded = null;
    }

    protected function say(DefinitionRefused $refusal): void
    {
        Notification::make()->danger()->title(__("admin.definitions.refused.{$refusal->reason}", $refusal->params))->persistent()->send();
    }

    protected function admin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    /**
     * The languages that the warnings say have no text, once each.
     *
     * @param  list<array{file: string, pointer: string, message: string}>  $warnings
     * @return list<string>
     */
    private static function missingLanguages(array $warnings): array
    {
        $languages = [];

        foreach ($warnings as $issue) {
            if (preg_match('#/([A-Za-z]{2}(?:-[A-Za-z]{2})?)$#', $issue['pointer'], $found) === 1 && in_array($found[1], DefinitionForm::LOCALES, true)) {
                $languages[$found[1]] = $found[1];
            }
        }

        return array_values($languages);
    }
}
