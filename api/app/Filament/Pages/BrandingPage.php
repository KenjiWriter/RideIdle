<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Definitions\BrandingForm;
use App\Content\ImageRefused;
use App\Filament\Forms\BrandingFields;
use App\Models\ContentDefinition;
use App\Services\ContentImages;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The look of the game: the colours and fonts of its app, a style sheet of its own, the blocks of its front page and the preview of a link to it. What is saved is the
 * draft of the document `rules/branding`, kept only when the schema and the rules about the content accept it (a style sheet that would bring something from
 * outside is said and not kept), and it reaches the game with the next pack that is made live. A game whose pack has no such document starts one with its first draft.
 */
class BrandingPage extends RulesDocumentPage
{
    protected static ?string $slug = 'branding';

    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string
    {
        return __('admin.branding.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.branding.title');
    }

    protected function documentSlug(): string
    {
        return 'branding';
    }

    /** A pack that has no such document (it is the app as it looks) is worked on as an empty one; the first draft makes the document. */
    protected function unstartedDefinition(): ?ContentDefinition
    {
        return (new ContentDefinition)->forceFill([
            'uid' => (string) Str::ulid(),
            'kind' => ContentDefinition::KIND_RULES,
            'slug' => $this->documentSlug(),
            'status' => ContentDefinition::STATUS_DRAFT,
        ]);
    }

    protected function fields(array $document): array
    {
        return BrandingFields::schema();
    }

    protected function toState(array $document): array
    {
        return BrandingForm::toState($document);
    }

    protected function toDocument(array $document, array $state): array
    {
        return BrandingForm::apply($document, $this->withPictures($state), $this->version());
    }

    /** A picture that cannot be kept stops the saving after it is said, and nothing is kept. */
    public function save(): void
    {
        try {
            parent::save();
        } catch (Halt) {
            // Said already.
        }
    }

    /**
     * The state of the form with the pictures in it: a file that was chosen is kept and its name is the picture of the block (or of the preview of a link), a picture
     * that is taken off is none, and the two boxes of the form that are not a part of the document are gone.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function withPictures(array $state): array
    {
        foreach ((array) ($state['blocks'] ?? []) as $key => $item) {
            if (in_array($item['type'] ?? null, ['hero', 'image'], true)) {
                $state['blocks'][$key]['data'] = $this->withPicture((array) ($item['data'] ?? []));
            }
        }

        $state['share'] = $this->withPicture((array) ($state['share'] ?? []));

        return $state;
    }

    /**
     * @param  array<string, mixed>  $fields  the fields of a block or of the preview of a link
     * @return array<string, mixed>
     */
    private function withPicture(array $fields): array
    {
        $upload = $fields['image_upload'] ?? null;
        $upload = is_array($upload) ? Arr::first($upload) : $upload;

        if (! empty($fields['image_remove'])) {
            $fields['image'] = null;
        }

        unset($fields['image_upload'], $fields['image_remove']);

        $bytes = match (true) {
            $upload instanceof TemporaryUploadedFile => $upload->get(),
            $upload instanceof UploadedFile => (string) file_get_contents($upload->getRealPath()),
            default => null,
        };

        if ($bytes === null) {
            return $fields;
        }

        try {
            $fields['image'] = app(ContentImages::class)->store((string) $bytes);
        } catch (ImageRefused $refusal) {
            Notification::make()->danger()->title(__("admin.definitions.image_refused.{$refusal->reason}", $refusal->params))->persistent()->send();

            $this->halt();
        }

        return $fields;
    }

    /** The version a new document is given: the one of the rules, which is what the other documents of a pack carry. */
    private function version(): string
    {
        $rules = ContentDefinition::query()->where('kind', ContentDefinition::KIND_RULES)->where('slug', 'starter')->with('latestVersion')->first();

        return (string) ($rules?->workingDocument()['version'] ?? '2026.10.0');
    }
}
