<?php

declare(strict_types=1);

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Pages\DomainPage;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Support\FieldHelp;
use App\Http\Middleware\SetAdminGame;
use App\Models\Admin;
use App\Models\Game;
use App\Services\AdminAudit;
use App\Services\DomainChecker;
use App\Services\DomainJobs;
use App\Services\GameCreator;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Makes a game in three steps: its name and domain, the content it starts from, and what the server does about the domain. It asks for no more than that: the slug follows from the
 * name, and the colours, the icons and the look are the app's until somebody changes them. A game starts as a draft, which answers to nobody until it is made live.
 */
class CreateGame extends CreateRecord
{
    use HasWizard;

    protected static string $resource = GameResource::class;

    /** What the last look at the DNS of the domain found (the look is asked for, not made on every change of the form). */
    public ?array $dnsCheck = null;

    /** @var list<array{key: string, detail: string}> what could not be done after the game was made */
    private array $warnings = [];

    /** @return array<int, Step> */
    public function getSteps(): array
    {
        return [
            Step::make(__('admin.games.wizard.step_name'))->description(__('admin.games.wizard.step_name_hint'))->schema([
                FieldHelp::guide('wizard'),
                View::make('filament.games.preview')->viewData(['installation' => (string) parse_url((string) config('ride.web_url'), PHP_URL_HOST), 'fallback' => __('admin.games.name')]),
                TextInput::make('name')
                    ->helpKey('game.name')
                    ->required()
                    ->maxLength(64)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if (blank($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->helpKey('game.slug')
                    ->required()
                    ->regex(GameResource::SLUG)
                    ->unique(Game::class, 'slug'),
                TextInput::make('domain')
                    ->helpKey('game.domain')
                    ->maxLength(190)
                    ->regex(DomainChecker::HOST)
                    ->validationMessages(['regex' => __('admin.games.wizard.domain_invalid')])
                    ->unique(Game::class, 'domain')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('domain', strtolower(trim((string) $state)))),
                Select::make('locale')->helpKey('game.locale')->options(config('ride.locale_names'))->required()->default('en'),
                TextInput::make('theme_color')->helpKey('game.theme_color')->required()->regex('/^#[0-9a-fA-F]{6}$/')->default('#0e1013'),
            ]),
            Step::make(__('admin.games.wizard.step_start'))->description(__('admin.games.wizard.step_start_hint'))->schema([
                Radio::make('start')
                    ->helpKey('wizard.start', options: false)
                    ->options(FieldHelp::options('wizard.start', [GameCreator::START_TEMPLATE, GameCreator::START_COPY, GameCreator::START_NONE]))
                    ->descriptions(FieldHelp::descriptions('wizard.start', [GameCreator::START_TEMPLATE, GameCreator::START_COPY, GameCreator::START_NONE]))
                    ->default(GameCreator::START_TEMPLATE)
                    ->required()
                    ->live(),
                Select::make('copy_from')
                    ->helpKey('wizard.copy_from')
                    ->options(fn (): array => Game::query()->orderBy('id')->pluck('name', 'slug')->all())
                    ->visible(fn (Get $get): bool => $get('start') === GameCreator::START_COPY)
                    ->required(fn (Get $get): bool => $get('start') === GameCreator::START_COPY),
            ]),
            Step::make(__('admin.games.wizard.step_domain'))->description(__('admin.games.wizard.step_domain_hint'))->schema([
                View::make('filament.games.wizard-domain')->viewData(fn (Get $get): array => [
                    'domain' => strtolower(trim((string) $get('domain'))),
                    'email' => app(DomainJobs::class)->email(),
                    'check' => $this->dnsCheck,
                ]),
                Toggle::make('request_domain')
                    ->helpKey('wizard.request_domain')
                    ->default(true)
                    ->visible(fn (Get $get): bool => filled($get('domain')) && app(DomainJobs::class)->email() !== null),
            ]),
        ];
    }

    /** Looks at the DNS of the domain that is in the form, and keeps what it found for the step to show. */
    public function checkDns(): void
    {
        $domain = strtolower(trim((string) ($this->data['domain'] ?? '')));

        $this->dnsCheck = [...app(DomainChecker::class)->check($domain), 'domain' => $domain];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $admin = Auth::guard('admin')->user();

        $made = app(GameCreator::class)->create(
            [...Arr::only($data, ['slug', 'name', 'locale', 'theme_color']), 'domain' => filled($data['domain'] ?? null) ? strtolower(trim((string) $data['domain'])) : null],
            start: (string) ($data['start'] ?? GameCreator::START_TEMPLATE),
            copyFrom: isset($data['copy_from']) ? (string) $data['copy_from'] : null,
            requestDomain: (bool) ($data['request_domain'] ?? false) && filled($data['domain'] ?? null),
            by: $admin instanceof Admin ? $admin : null,
        );

        $this->warnings = $made['warnings'];

        return $made['game'];
    }

    protected function afterCreate(): void
    {
        /** @var Game $game */
        $game = $this->getRecord();

        app(AdminAudit::class)->record('game_created', subject: "game:{$game->slug}", context: ['name' => $game->name, 'domain' => $game->domain, 'status' => $game->status]);

        foreach ($this->warnings as $warning) {
            Notification::make()->warning()->title(__("admin.games.wizard.warning.{$warning['key']}"))->body($warning['detail'] === '' ? null : $warning['detail'])->persistent()->send();
        }
    }

    /** The new game is the game that the panel works in from now on, and the page that tells what is left to do for its domain is where it goes. */
    protected function getRedirectUrl(): string
    {
        /** @var Game $game */
        $game = $this->getRecord();

        session()->put(SetAdminGame::SESSION_KEY, $game->slug);

        return DomainPage::getUrl();
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('admin.games.wizard.create'));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('admin.games.created');
    }
}
