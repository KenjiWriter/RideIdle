<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\ForAGame;
use App\Filament\Resources\ContentPublications\ContentPublicationResource;
use App\Filament\Resources\Parts\PartResource;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\ContentDefinition;
use App\Services\ContentPacks;
use App\Services\PendingChanges;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What the drafts would change in the game, definition by definition, against what is in the pack that is live now: the screen to read before a pack is asked for. It changes
 * nothing; a pack is asked for on the page of the publications, and goes through the gates there.
 */
class PendingChangesPage extends Page
{
    use ForAGame;

    protected static ?string $slug = 'pending-changes';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.publications.pending';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.pending.nav');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = app(PendingChanges::class)->waiting();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.pending.title');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $entries = app(PendingChanges::class)->all();

        return [
            'entries' => $entries,
            'changes' => array_sum(array_column($entries, 'count')),
            'pack' => app(ContentPacks::class)->active()?->number,
            'publicationsUrl' => ContentPublicationResource::getUrl(),
            'editUrls' => array_combine(
                array_map(fn (array $entry): int => $entry['definition']->id, $entries),
                array_map(fn (array $entry): ?string => $this->editUrl($entry['definition']), $entries),
            ),
        ];
    }

    /** Where the definition is changed: the form of a car or a part, or the page of the rules that has the document. */
    private function editUrl(ContentDefinition $definition): ?string
    {
        return match ($definition->kind) {
            ContentDefinition::KIND_VEHICLE => VehicleResource::getUrl('edit', ['record' => $definition]),
            ContentDefinition::KIND_PART => PartResource::getUrl('edit', ['record' => $definition]),
            default => match ($definition->slug) {
                'starter' => StudioPage::getUrl(),
                'tutorial' => TutorialPage::getUrl(),
                'vocabulary' => VocabularyPage::getUrl(),
                'branding' => BrandingPage::getUrl(),
                'model' => ModelPage::getUrl(),
                default => RulesJsonPage::getUrl(),
            },
        };
    }
}
