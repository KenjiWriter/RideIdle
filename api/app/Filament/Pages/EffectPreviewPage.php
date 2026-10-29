<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\Publishing\PartPreview;
use App\Filament\Concerns\ForAGame;
use App\Filament\Forms\ContentOptions;
use App\Models\ContentDefinition;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;

/**
 * What a part does to a car and to its laps before it is in a pack (D5): the test car on every track, dry and in the rain, with the place among the bots and the
 * income of an hour, for a part as it is being worked on (a new part, or a draft) against the part that is live, or against the car without it.
 */
class EffectPreviewPage extends Page implements HasSchemas
{
    use ForAGame;
    use InteractsWithSchemas;

    protected static ?string $slug = 'preview';

    protected static ?int $navigationSort = 40;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null what was counted last */
    public ?array $result = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.preview.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.preview.title');
    }

    public function mount(): void
    {
        $this->form->fill(['part' => null, 'car' => PartPreview::CAR, 'rarity' => 1]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(3)->components([
            Select::make('part')->label(__('admin.preview.part'))->options(fn (): array => ContentOptions::parts())->searchable()->required(),
            Select::make('car')->label(__('admin.preview.car'))->options(fn (): array => ContentOptions::cars())->searchable()->required(),
            Select::make('rarity')->label(__('admin.preview.rarity'))->helperText(__('admin.preview.rarity_hint'))->options(array_combine(range(1, 5), range(1, 5)))->selectablePlaceholder(false)->required(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.preview.hint'),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('count')
                ->footer([
                    Actions::make([
                        Action::make('count')->label(__('admin.preview.count'))->submit('count')->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
            View::make('filament.preview.result')->viewData(fn (): array => ['result' => $this->result, 'names' => $this->trackNames()]),
        ]);
    }

    public function count(): void
    {
        $state = $this->form->getState();

        try {
            $this->result = app(PartPreview::class)->preview((string) $state['part'], (string) $state['car'], (int) $state['rarity']);
        } catch (InvalidArgumentException $problem) {
            $this->result = null;

            Notification::make()->danger()->title(__('admin.preview.failed', ['why' => $problem->getMessage()]))->persistent()->send();
        }
    }

    /**
     * The names of the tracks in the language of the panel, by slug.
     *
     * @return array<string, string>
     */
    private function trackNames(): array
    {
        $rules = ContentDefinition::query()->where('kind', ContentDefinition::KIND_RULES)->where('slug', 'starter')->with('latestVersion')->first()?->workingDocument() ?? [];
        $names = [];

        foreach ($rules['tracks'] ?? [] as $slug => $track) {
            $names[(string) $slug] = ContentOptions::name($track['name'] ?? [], (string) $slug);
        }

        return $names;
    }
}
