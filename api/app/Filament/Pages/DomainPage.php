<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\DomainJobRefused;
use App\Filament\Concerns\ForAGame;
use App\Filament\Resources\Games\GameResource;
use App\Games\CurrentGame;
use App\Models\Admin;
use App\Models\DomainJob;
use App\Models\Game;
use App\Services\AdminAudit;
use App\Services\DomainChecker;
use App\Services\DomainJobs;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * The domain of the game in play and its certificate: whether the domain points at this server, what was asked of the server about it, how that went, and until when the
 * certificate holds. Setting a domain up touches nginx and the certificates of the whole installation, so it is for an admin who works in every game, as making a game is.
 * The panel only asks: a worker on the server does it (see `DomainJobs`), and this page shows its answer.
 */
class DomainPage extends Page
{
    use ForAGame;

    /** After this many minutes without the worker taking a request, the page says to look at the worker. */
    private const WORKER_SLOW_MINUTES = 3;

    protected static ?string $slug = 'domain';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.games.domain';

    /** What the last look at the DNS of the domain found. */
    public ?array $dnsCheck = null;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin && $admin->all_games;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.domain.nav');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-lock-closed';
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.domain.title');
    }

    public function checkDns(): void
    {
        $domain = strtolower(trim((string) $this->game()?->domain));

        $this->dnsCheck = [...app(DomainChecker::class)->check($domain), 'domain' => $domain];
    }

    public function requestProvision(): void
    {
        $this->ask(DomainJob::ACTION_PROVISION);
    }

    public function requestCheck(): void
    {
        $this->ask(DomainJob::ACTION_CHECK);
    }

    private function ask(string $action): void
    {
        abort_unless(static::canAccess(), 403);

        $game = $this->game();
        $admin = Auth::guard('admin')->user();

        if ($game === null) {
            return;
        }

        try {
            $job = app(DomainJobs::class)->request($game, $action, $admin instanceof Admin ? $admin : null);
        } catch (DomainJobRefused $refused) {
            Notification::make()->danger()->title(__("admin.domain.refused.{$refused->reason}"))->send();

            return;
        }

        app(AdminAudit::class)->record('domain_requested', subject: "game:{$game->slug}", context: ['action' => $action, 'domain' => $job->domain, 'job' => $job->id]);

        Notification::make()->success()->title(__('admin.domain.requested'))->send();
    }

    private function game(): ?Game
    {
        return Game::query()->find(app(CurrentGame::class)->id());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();
        $domain = strtolower(trim((string) $game?->domain));
        $jobs = app(DomainJobs::class);
        $job = $game === null ? null : $jobs->latestOf($game);
        $now = CarbonImmutable::now();
        $certified = $game?->domainJobs()->reorder('id', 'desc')->whereNotNull('certificate_expires_at')->where('status', DomainJob::STATUS_DONE)->first();

        return [
            'game' => $game,
            'domain' => $domain,
            'job' => $job,
            'check' => $this->dnsCheck,
            'email' => $jobs->email(),
            'editUrl' => $game === null ? null : GameResource::getUrl('edit', ['record' => $game]),
            'busy' => $job?->isActive() ?? false,
            'workerSlow' => $job !== null && $job->status === DomainJob::STATUS_REQUESTED && $job->created_at !== null && $job->created_at->diffInMinutes($now) >= self::WORKER_SLOW_MINUTES,
            'expires' => $certified?->certificate_expires_at,
            'daysLeft' => $certified?->certificate_expires_at === null ? null : (int) floor($now->startOfDay()->diffInDays($certified->certificate_expires_at->startOfDay(), false)),
        ];
    }
}
