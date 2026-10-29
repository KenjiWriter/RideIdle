<?php

declare(strict_types=1);

use App\Exceptions\DomainJobRefused;
use App\Games\CurrentGame;
use App\Models\ContentPack;
use App\Models\DomainJob;
use App\Models\Game;
use App\Services\ContentPacks;
use App\Services\DomainChecker;
use App\Services\DomainJobs;
use App\Services\GameCreator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// What the panel asks of the server about the domain of a game, and what the worker on the server does with it: the requests, the claim (which no two workers can both
// win), the report, the two commands the worker runs, the look at the DNS, and the making of a game with a start.

const DOMAIN_NOW = '2026-10-29 12:00:00';

/** A game that is a draft, with a domain unless it is told otherwise. */
function domainGame(string $slug = 'kosmos', ?string $domain = 'kosmos.example.test'): Game
{
    return Game::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'domain' => $domain, 'locale' => 'en', 'theme_color' => '#101a3a', 'status' => Game::STATUS_DRAFT, 'is_default' => false]);
}

/** A job that waits for the worker. */
function domainJob(Game $game, string $action = DomainJob::ACTION_PROVISION, string $status = DomainJob::STATUS_REQUESTED, array $attributes = []): DomainJob
{
    return DomainJob::query()->create([
        'game_id' => $game->id,
        'domain' => (string) $game->domain,
        'action' => $action,
        'status' => $status,
        'email' => $action === DomainJob::ACTION_PROVISION ? 'ops@example.com' : null,
        ...$attributes,
    ]);
}

/** The DNS of the tests: every host resolves to the addresses given (by host), and to none that is not named. */
function domainDns(array $hosts): DomainChecker
{
    $checker = new DomainChecker(fn (string $host): array => $hosts[$host] ?? []);
    app()->instance(DomainChecker::class, $checker);

    return $checker;
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), checkTables: false, activate: true);
    app()->setLocale('pl');
    config(['ride.network.acme_email' => 'ops@example.com', 'ride.network.server_ips' => ['203.0.113.7']]);
    CarbonImmutable::setTestNow(DOMAIN_NOW);
});

describe('asking the server about a domain', function () {
    it('writes a request that waits for the worker: the domain in lower case, the address for Let\'s Encrypt and who asked', function () {
        $admin = signedInAdmin();
        $game = domainGame(domain: 'kosmos.example.test');

        $job = app(DomainJobs::class)->request($game, DomainJob::ACTION_PROVISION, $admin);

        expect($job->status)->toBe(DomainJob::STATUS_REQUESTED)
            ->and($job->action)->toBe('provision')
            ->and($job->domain)->toBe('kosmos.example.test')
            ->and($job->email)->toBe('ops@example.com')
            ->and($job->requested_by)->toBe($admin->id)
            ->and($job->game_id)->toBe($game->id)
            ->and($job->isActive())->toBeTrue();
    });

    it('asks Let\'s Encrypt for nothing when it only looks at a certificate, so it needs no address', function () {
        config(['ride.network.acme_email' => null]);

        $job = app(DomainJobs::class)->request(domainGame(), DomainJob::ACTION_CHECK);

        expect($job->action)->toBe('check')->and($job->email)->toBeNull();
    });

    it('is refused for a game that has no domain, or one that a certificate cannot be asked for', function (?string $domain) {
        $game = domainGame(domain: $domain);

        expect(fn () => app(DomainJobs::class)->request($game, DomainJob::ACTION_PROVISION))->toThrow(DomainJobRefused::class)
            ->and(DomainJob::query()->count())->toBe(0);
    })->with([null, 'kosmos', 'bad domain.example.test', 'kosmos.example.test;ls', '-kosmos.example.test']);

    it('is refused without an address for Let\'s Encrypt, for an action that there is not, and while the last request is not answered, and says why', function () {
        $game = domainGame();
        $reason = function (callable $do): string {
            try {
                $do();
            } catch (DomainJobRefused $refused) {
                return $refused->reason;
            }

            return 'none';
        };

        config(['ride.network.acme_email' => 'not an address']);
        expect($reason(fn () => app(DomainJobs::class)->request($game, DomainJob::ACTION_PROVISION)))->toBe('email');

        config(['ride.network.acme_email' => 'ops@example.com']);
        expect($reason(fn () => app(DomainJobs::class)->request($game, 'rm')))->toBe('action')
            ->and($reason(fn () => app(DomainJobs::class)->request($game, DomainJob::ACTION_PROVISION)))->toBe('none')
            ->and($reason(fn () => app(DomainJobs::class)->request($game, DomainJob::ACTION_CHECK)))->toBe('busy');
    });

    it('may be asked again once the last request is answered, and a game does not wait for the request of another', function () {
        $game = domainGame();
        $other = domainGame('rejsy', 'rejsy.example.test');
        $first = app(DomainJobs::class)->request($game, DomainJob::ACTION_PROVISION);

        expect(fn () => app(DomainJobs::class)->request($other, DomainJob::ACTION_PROVISION))->not->toThrow(DomainJobRefused::class);

        DomainJob::query()->whereKey($first->id)->update(['status' => DomainJob::STATUS_FAILED]);

        expect(app(DomainJobs::class)->request($game, DomainJob::ACTION_PROVISION)->id)->not->toBe($first->id)
            ->and(app(DomainJobs::class)->latestOf($game)?->status)->toBe(DomainJob::STATUS_REQUESTED);
    });
});

describe('what the worker claims', function () {
    it('is the oldest request first, which is then running, and nothing when none waits', function () {
        $first = domainJob(domainGame());
        $second = domainJob(domainGame('rejsy', 'rejsy.example.test'));

        $claimed = app(DomainJobs::class)->claim();

        expect($claimed?->id)->toBe($first->id)
            ->and($claimed?->status)->toBe(DomainJob::STATUS_RUNNING)
            ->and($claimed?->started_at?->toDateTimeString())->toBe(DOMAIN_NOW)
            ->and(app(DomainJobs::class)->claim()?->id)->toBe($second->id)
            ->and(app(DomainJobs::class)->claim())->toBeNull();
    });

    it('is never one that another worker has, however often it is asked', function () {
        domainJob(domainGame());

        expect(app(DomainJobs::class)->claim())->not->toBeNull()
            ->and(app(DomainJobs::class)->claim())->toBeNull()
            ->and(DomainJob::query()->where('status', DomainJob::STATUS_RUNNING)->count())->toBe(1);
    });

    it('is not a request for a domain that the game does not have any more: it is reported refused, and the next one is looked at', function () {
        $changed = domainGame();
        $stale = domainJob($changed);
        $changed->forceFill(['domain' => 'other.example.test'])->save();
        $good = domainJob(domainGame('rejsy', 'rejsy.example.test'));

        expect(app(DomainJobs::class)->claim()?->id)->toBe($good->id);

        $stale->refresh();

        expect($stale->status)->toBe(DomainJob::STATUS_FAILED)
            ->and($stale->exit_code)->toBe(DomainJobs::EXIT_REFUSED)
            ->and($stale->log)->toContain('not the one that was asked for');
    });

    it('is not a request that has a domain a root script must not be given', function () {
        $game = domainGame();
        $job = domainJob($game, attributes: ['domain' => 'kosmos.example.test;ls']);
        $game->forceFill(['domain' => 'kosmos.example.test;ls'])->save();

        expect(app(DomainJobs::class)->claim())->toBeNull()
            ->and($job->refresh()->status)->toBe(DomainJob::STATUS_FAILED);
    });

    it('gives up on a job that has been running for too long, and leaves one that has not', function () {
        $lost = domainJob(domainGame(), status: DomainJob::STATUS_RUNNING, attributes: ['started_at' => CarbonImmutable::parse(DOMAIN_NOW)->subMinutes(DomainJobs::STALE_MINUTES + 1)]);
        $busy = domainJob(domainGame('rejsy', 'rejsy.example.test'), status: DomainJob::STATUS_RUNNING, attributes: ['started_at' => CarbonImmutable::parse(DOMAIN_NOW)->subMinutes(5)]);

        expect(app(DomainJobs::class)->claim())->toBeNull();

        expect($lost->refresh()->status)->toBe(DomainJob::STATUS_FAILED)
            ->and($lost->exit_code)->toBe(DomainJobs::EXIT_LOST)
            ->and($busy->refresh()->status)->toBe(DomainJob::STATUS_RUNNING);
    });
});

describe('what the worker reports', function () {
    it('is done with an exit code of nothing, with the end of its log and the end date of the certificate', function () {
        $job = app(DomainJobs::class)->claim() ?? domainJob(domainGame(), status: DomainJob::STATUS_RUNNING);

        expect(app(DomainJobs::class)->report($job, 0, "game-site: done\n", '2027-01-20'))->toBeTrue();

        $job->refresh();

        expect($job->status)->toBe(DomainJob::STATUS_DONE)
            ->and($job->exit_code)->toBe(0)
            ->and($job->log)->toBe("game-site: done\n")
            ->and($job->certificate_expires_at?->toDateString())->toBe('2027-01-20')
            ->and($job->finished_at?->toDateTimeString())->toBe(DOMAIN_NOW);
    });

    it('is failed with any other exit code, and keeps what the worker said', function () {
        $job = domainJob(domainGame(), status: DomainJob::STATUS_RUNNING);

        app(DomainJobs::class)->report($job, 124, 'timed out');

        expect($job->refresh()->status)->toBe(DomainJob::STATUS_FAILED)->and($job->exit_code)->toBe(124)->and($job->certificate_expires_at)->toBeNull();
    });

    it('keeps the end of a long log, and nothing that the database or the page could not take: invalid text and control characters go, the line breaks stay', function () {
        $job = domainJob(domainGame(), status: DomainJob::STATUS_RUNNING);
        $log = str_repeat('x', 50_000)."\nthe end \x00\x07 is here \xC3\x28 ok\n";

        app(DomainJobs::class)->report($job, 1, $log);

        $kept = (string) $job->refresh()->log;

        expect(strlen($kept))->toBeLessThanOrEqual(DomainJobs::LOG_BYTES)
            ->and($kept)->toEndWith("the end  is here ( ok\n")
            ->and(mb_check_encoding($kept, 'UTF-8'))->toBeTrue();
    });

    it('takes an end date that is a date and no other, and no log at all', function (?string $expires) {
        $job = domainJob(domainGame(), status: DomainJob::STATUS_RUNNING);

        app(DomainJobs::class)->report($job, 0, null, $expires);

        expect($job->refresh()->certificate_expires_at)->toBeNull()->and($job->log)->toBeNull();
    })->with([null, 'tomorrow', '2027-13-45', '2027-1-5', "2027-01-20'; drop table games"]);

    it('leaves a job that is not running as it is: a report that comes twice, or after the job was given up on', function () {
        $job = domainJob(domainGame(), status: DomainJob::STATUS_RUNNING);
        $jobs = app(DomainJobs::class);

        expect($jobs->report($job, 0, 'first'))->toBeTrue()
            ->and($jobs->report($job, 1, 'second'))->toBeFalse()
            ->and($job->refresh()->status)->toBe(DomainJob::STATUS_DONE)
            ->and($job->log)->toBe('first')
            ->and($jobs->report(domainJob(domainGame('rejsy', 'rejsy.example.test')), 0))->toBeFalse();
    });
});

describe('the commands that the worker runs', function () {
    it('claims with one line of four fields separated by tabs, and prints nothing when there is nothing to do', function () {
        expect(Artisan::call('domains:claim'))->toBe(0)->and(Artisan::output())->toBe('');

        $job = domainJob(domainGame());

        expect(Artisan::call('domains:claim'))->toBe(0)
            ->and(rtrim(Artisan::output(), "\r\n"))->toBe("{$job->id}\tprovision\tkosmos.example.test\tops@example.com");

        $check = domainJob(domainGame('rejsy', 'rejsy.example.test'), DomainJob::ACTION_CHECK);

        Artisan::call('domains:claim');

        expect(rtrim(Artisan::output(), "\r\n"))->toBe("{$check->id}\tcheck\trejsy.example.test\t");
    });

    it('reports how a job ended, with the log of the worker and the end of the certificate', function () {
        $job = domainJob(domainGame(), status: DomainJob::STATUS_RUNNING);
        $file = tempnam(sys_get_temp_dir(), 'domains');
        File::put($file, "step one\nstep two\n");

        expect(Artisan::call('domains:report', ['id' => $job->id, '--exit-code' => '0', '--log-file' => $file, '--expires' => '2027-02-01']))->toBe(0);

        File::delete($file);

        $job->refresh();

        expect($job->status)->toBe(DomainJob::STATUS_DONE)->and($job->log)->toBe("step one\nstep two\n")->and($job->certificate_expires_at?->toDateString())->toBe('2027-02-01');
    });

    it('reports a failure by any exit code but nothing, and works without a log file or a date', function () {
        $job = domainJob(domainGame(), status: DomainJob::STATUS_RUNNING);

        expect(Artisan::call('domains:report', ['id' => $job->id, '--exit-code' => '2', '--log-file' => '/no/such/file']))->toBe(0);

        expect($job->refresh()->status)->toBe(DomainJob::STATUS_FAILED)->and($job->exit_code)->toBe(2)->and($job->log)->toBeNull();
    });

    it('refuses what is not a report: a job that is not there, an exit code or a number that is not a number, and a date that is not one', function () {
        $job = domainJob(domainGame(), status: DomainJob::STATUS_RUNNING);

        expect(Artisan::call('domains:report', ['id' => '999', '--exit-code' => '0']))->toBe(1)
            ->and(Artisan::call('domains:report', ['id' => $job->id]))->toBe(1)
            ->and(Artisan::call('domains:report', ['id' => $job->id, '--exit-code' => 'x']))->toBe(1)
            ->and(Artisan::call('domains:report', ['id' => '1;ls', '--exit-code' => '0']))->toBe(1)
            ->and(Artisan::call('domains:report', ['id' => $job->id, '--exit-code' => '0', '--expires' => 'soon']))->toBe(1)
            ->and($job->refresh()->status)->toBe(DomainJob::STATUS_RUNNING);
    });

    it('says, and succeeds, when the job it is told of is not running any more', function () {
        $job = domainJob(domainGame(), status: DomainJob::STATUS_DONE);

        expect(Artisan::call('domains:report', ['id' => $job->id, '--exit-code' => '1']))->toBe(0)
            ->and(Artisan::output())->toContain('left as it is')
            ->and($job->refresh()->status)->toBe(DomainJob::STATUS_DONE);
    });
});

describe('the look at the DNS of a domain', function () {
    it('says whether the domain points at this server, somewhere else, or nowhere yet', function () {
        $checker = domainDns(['kosmos.example.test' => ['203.0.113.7'], 'rejsy.example.test' => ['198.51.100.9'], 'statki.example.test' => []]);

        expect($checker->check('kosmos.example.test'))->toBe(['status' => 'ok', 'found' => ['203.0.113.7'], 'expected' => ['203.0.113.7']])
            ->and($checker->check('rejsy.example.test')['status'])->toBe('wrong')
            ->and($checker->check('statki.example.test'))->toBe(['status' => 'missing', 'found' => [], 'expected' => ['203.0.113.7']]);
    });

    it('is ok when any one of the addresses is one of the server', function () {
        config(['ride.network.server_ips' => ['203.0.113.7', '2001:db8::1']]);

        expect(domainDns(['kosmos.example.test' => ['198.51.100.9', '2001:db8::1']])->check('kosmos.example.test')['status'])->toBe('ok');
    });

    it('says that it is not a domain, without asking the DNS about it', function (string $domain) {
        $asked = [];
        app()->instance(DomainChecker::class, $checker = new DomainChecker(function (string $host) use (&$asked): array {
            $asked[] = $host;

            return [];
        }));

        expect($checker->check($domain)['status'])->toBe('invalid')->and($asked)->not->toContain(strtolower(trim($domain)));
    })->with(['', 'kosmos', 'a b.example.test', 'kosmos.example.test/path', 'kosmos..example.test', '-kosmos.example.test', 'kosmos.example.test.', str_repeat('a', 64).'.example.test']);

    it('writes the domain in lower case, and without the spaces round it', function () {
        expect(domainDns(['kosmos.example.test' => ['203.0.113.7']])->check('  Kosmos.Example.TEST ')['status'])->toBe('ok');
    });

    it('compares with the address of the host of the web app when the addresses of the server are not named, and says that it cannot when it does not know them either', function () {
        config(['ride.network.server_ips' => [], 'ride.web_url' => 'https://raceidle.example.test']);

        $checker = domainDns(['raceidle.example.test' => ['203.0.113.50'], 'kosmos.example.test' => ['203.0.113.50'], 'rejsy.example.test' => ['198.51.100.9']]);

        expect($checker->serverAddresses())->toBe(['203.0.113.50'])
            ->and($checker->check('kosmos.example.test')['status'])->toBe('ok')
            ->and($checker->check('rejsy.example.test')['status'])->toBe('wrong');

        config(['ride.web_url' => 'https://nowhere.example.test']);

        expect($checker->serverAddresses())->toBe([])
            ->and($checker->check('kosmos.example.test')['status'])->toBe('unknown');
    });

    it('takes a host with at least two labels and no more than the standards allow', function (string $host, bool $valid) {
        expect(DomainChecker::isValidHost($host))->toBe($valid);
    })->with([
        ['kosmos.example.test', true],
        ['a.io', true],
        ['xn--bcher-kva.example', true],
        ['kosmos', false],
        ['Kosmos.example.test', false],
        ['kosmos.example.test:8080', false],
        ['kosmos_game.example.test', false],
        [str_repeat('a', 63).'.example.test', true],
        [str_repeat('a', 64).'.example.test', false],
    ]);
});

describe('making a game with a start', function () {
    function createGameFor(string $start, ?string $copyFrom = null, bool $requestDomain = false, ?string $domain = 'kosmos.example.test'): array
    {
        return app(GameCreator::class)->create(
            ['slug' => 'kosmos', 'name' => 'Kosmos', 'domain' => $domain, 'locale' => 'en', 'theme_color' => '#101a3a'],
            start: $start,
            copyFrom: $copyFrom,
            requestDomain: $requestDomain,
            checkTables: false,
        );
    }

    function livePackOf(Game $game): ?string
    {
        return app(CurrentGame::class)->within($game, fn (): ?string => app(ContentPacks::class)->active()?->number);
    }

    it('starts from the car game: a draft that is not the default one, with the content of the repository live in it', function () {
        $made = createGameFor(GameCreator::START_TEMPLATE);

        expect($made['warnings'])->toBe([])
            ->and($made['game']->status)->toBe(Game::STATUS_DRAFT)
            ->and($made['game']->is_default)->toBeFalse()
            ->and($made['started'])->not->toBeNull()
            ->and(livePackOf($made['game']))->toBe($made['started'])
            ->and(Game::query()->where('is_default', true)->count())->toBe(1);
    });

    it('starts from a copy of the pack that another game plays now: the same content, in a game of its own', function () {
        $source = domainGame('statki', 'statki.example.test');
        app(CurrentGame::class)->within($source, fn () => contentPacks()->import(contentWith(['vehicles/rdzak-gt-4' => fn (array $car): array => [...$car, 'name' => ['en' => 'Ship One', 'pl' => 'Statek Jeden']]]), checkTables: false, activate: true));

        $made = createGameFor(GameCreator::START_COPY, 'statki');
        $copied = app(CurrentGame::class)->within($made['game'], fn (): string => (string) app(ContentPacks::class)->activeSource()?->checksum());
        $original = app(CurrentGame::class)->within($source, fn (): string => (string) app(ContentPacks::class)->activeSource()?->checksum());

        expect($made['warnings'])->toBe([])
            ->and($copied)->toBe($original)
            ->and(livePackOf($made['game']))->toBe(livePackOf($source))
            ->and(app(CurrentGame::class)->within($made['game'], fn (): int => ContentPack::query()->count()))->toBe(1);
    });

    it('starts with no content when it is told to: the game has no live pack', function () {
        $made = createGameFor(GameCreator::START_NONE);

        expect($made['started'])->toBeNull()->and($made['warnings'])->toBe([])->and(livePackOf($made['game']))->toBeNull();
    });

    it('says what it could not copy and makes the game all the same: a game that is not there, or one that has no live pack', function () {
        $missing = createGameFor(GameCreator::START_COPY, 'no-such-game');

        expect($missing['warnings'][0]['key'])->toBe('copy_missing')->and($missing['game']->exists)->toBeTrue()->and(livePackOf($missing['game']))->toBeNull();

        $empty = domainGame('statki', 'statki.example.test');
        $made = app(GameCreator::class)->create(['slug' => 'rejsy', 'name' => 'Rejsy', 'domain' => null, 'locale' => 'en', 'theme_color' => '#101a3a'], start: GameCreator::START_COPY, copyFrom: $empty->slug, checkTables: false);

        expect($made['warnings'][0]['key'])->toBe('copy_empty')->and(Game::query()->where('slug', 'rejsy')->exists())->toBeTrue();
    });

    it('asks the server for the domain when it is told to, and says why it could not when it cannot', function () {
        $made = createGameFor(GameCreator::START_NONE, requestDomain: true);

        expect($made['warnings'])->toBe([])
            ->and(DomainJob::query()->where('game_id', $made['game']->id)->where('status', DomainJob::STATUS_REQUESTED)->count())->toBe(1);

        Game::query()->where('slug', 'kosmos')->delete();
        config(['ride.network.acme_email' => null]);

        $no = createGameFor(GameCreator::START_NONE, requestDomain: true);

        expect($no['warnings'])->toBe([['key' => 'domain_email', 'detail' => '']]);

        Game::query()->where('slug', 'kosmos')->delete();
        config(['ride.network.acme_email' => 'ops@example.com']);

        $bad = createGameFor(GameCreator::START_NONE, requestDomain: true, domain: 'kosmos');

        expect($bad['warnings'])->toBe([['key' => 'domain_domain', 'detail' => '']]);
    });
});
