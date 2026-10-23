<?php

declare(strict_types=1);

use App\Models\AccountIdentity;
use App\Models\Settlement;
use App\Models\SettlementEvent;
use App\Models\User;
use App\Services\AccountErasure;
use App\Services\Ledger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

function erasure(): AccountErasure
{
    return new AccountErasure;
}

/** A row of the queue that is about an address: what the payload of a mail has in it. */
function queuedMailTo(string $address, string $table = 'jobs'): int|string
{
    $payload = json_encode(['displayName' => 'App\\Mail\\AccountLinkMail', 'data' => ['command' => 's:'.strlen($address).':"'.$address.'";']], JSON_THROW_ON_ERROR);

    if ($table === 'failed_jobs') {
        return DB::table('failed_jobs')->insertGetId(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => $payload, 'exception' => 'boom', 'failed_at' => now()]);
    }

    return DB::table('jobs')->insertGetId(['queue' => 'default', 'payload' => $payload, 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
}

describe('what is removed', function () {
    it('is the account and everything it holds', function () {
        $user = accountWithHistory();
        tokenWith(['user_id' => $user->id, 'email' => 'turbo@example.com']);

        erasure()->erase($user);

        expect(rowsOf())->toBe(['users' => 0, 'identities' => 0, 'devices' => 0, 'links' => 0, 'wallets' => 0, 'cars' => 0, 'parts' => 0, 'jobs' => 0, 'runs' => 0, 'settlements' => 0, 'events' => 0, 'ledger' => 0])
            ->and(User::query()->find($user->id))->toBeNull();
    });

    it('says what it removed, by kind, as the inventory said before', function () {
        $user = accountWithHistory();
        $before = erasure()->inventory($user);

        $removed = erasure()->erase($user);

        expect($removed)->toBe($before)
            ->and($removed)->toMatchArray(['accounts' => 1, 'sign-ins' => 2, 'devices' => 1, 'wallets' => 1, 'cars' => 1, 'parts' => 2, 'workshop jobs' => 1, 'tutorial steps' => 1, 'runs' => 1, 'settlements' => 1, 'settlement events' => 1, 'ledger entries' => 1]);
    });

    it('leaves another account and everything of it as it was', function () {
        $user = accountWithHistory();
        $other = User::factory()->create(['name' => 'Somebody_Else', 'email' => 'other@example.com']);
        identityOf($other, AccountIdentity::GOOGLE, 'other-google', 'other@example.com');
        $other->createToken('other-device');
        $run = aRun(['user_id' => $other->id]);
        $settlement = Settlement::query()->create(['race_run_id' => $run->id, 'state_version' => 1, 'cursor_from_ms' => 0, 'cursor_to_ms' => 1, 'now_ms' => 1, 'laps' => 0, 'cash_cents' => 0, 'xp' => 0, 'created_at' => now()]);
        SettlementEvent::query()->create(['settlement_id' => $settlement->id, 'seq' => 0, 'type' => 'lap_batch', 'at_ms' => 1, 'payload' => []]);
        (new Ledger)->post($other->id, 999, 'gift', 'other-gift');
        tokenWith(['user_id' => $other->id, 'email' => 'other@example.com']);

        erasure()->erase($user);

        expect(rowsOf())->toBe(['users' => 1, 'identities' => 1, 'devices' => 1, 'links' => 1, 'wallets' => 1, 'cars' => 1, 'parts' => 0, 'jobs' => 0, 'runs' => 1, 'settlements' => 1, 'events' => 1, 'ledger' => 1])
            ->and(User::query()->firstOrFail()->name)->toBe('Somebody_Else')
            ->and(cashOf($run))->toBe(999);
    });

    it('signs the devices out: their tokens do not work any more', function () {
        $user = accountWithHistory();
        $headers = bearerFor($user);

        erasure()->erase($user);

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    });

    it('removes the sessions of a browser and the requests for a new password', function () {
        $user = accountWithHistory();
        DB::table('sessions')->insert(['id' => 'mine', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'theirs', 'user_id' => User::factory()->create()->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => 'turbo@example.com', 'token' => 'x', 'created_at' => now()]);
        DB::table('password_reset_tokens')->insert(['email' => 'other@example.com', 'token' => 'y', 'created_at' => now()]);

        erasure()->erase($user);

        expect(DB::table('sessions')->pluck('id')->all())->toBe(['theirs'])
            ->and(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['other@example.com']);
    });
});

describe('the e-mails that wait or failed', function () {
    it('removes those to every address of the account, in the queue and among the failed', function () {
        $user = accountWithHistory();
        $queued = queuedMailTo('turbo@example.com');
        $reported = queuedMailTo('turbo@gmail.com');
        $failed = queuedMailTo('turbo@example.com', 'failed_jobs');

        $removed = erasure()->erase($user);

        expect(DB::table('jobs')->whereIn('id', [$queued, $reported])->exists())->toBeFalse()
            ->and(DB::table('failed_jobs')->where('id', $failed)->exists())->toBeFalse()
            ->and($removed['queued e-mails'])->toBe(2)
            ->and($removed['failed e-mails'])->toBe(1);
    });

    it('leaves those to other people', function () {
        $user = accountWithHistory();
        $others = queuedMailTo('other@example.com');
        $failedOthers = queuedMailTo('other@example.com', 'failed_jobs');

        erasure()->erase($user);

        expect(DB::table('jobs')->where('id', $others)->exists())->toBeTrue()
            ->and(DB::table('failed_jobs')->where('id', $failedOthers)->exists())->toBeTrue();
    });

    it('takes an underscore or a percent sign of an address for what it is, and not for a wildcard', function () {
        $user = User::factory()->create(['email' => 'turbo_x@example.com']);
        $theirs = queuedMailTo('turboXx@example.com');
        $mine = queuedMailTo('turbo_x@example.com');

        erasure()->erase($user);

        expect(DB::table('jobs')->where('id', $theirs)->exists())->toBeTrue()
            ->and(DB::table('jobs')->where('id', $mine)->exists())->toBeFalse();
    });

    it('finds an address written in capitals, as a mail may have been addressed', function () {
        $user = User::factory()->create(['email' => 'turbo@example.com']);
        $mail = queuedMailTo('Turbo@Example.com');

        erasure()->erase($user);

        expect(DB::table('jobs')->where('id', $mail)->exists())->toBeFalse();
    });

    it('is not about a guest, who has no address, and takes nobody else with it', function () {
        $guest = User::factory()->guest()->create();
        $others = queuedMailTo('other@example.com');

        $removed = erasure()->erase($guest);

        expect($removed['queued e-mails'])->toBe(0)
            ->and(DB::table('jobs')->where('id', $others)->exists())->toBeTrue();
    });
});

describe('when it goes wrong', function () {
    it('removes nothing: the account is as it was', function () {
        $user = accountWithHistory();
        $mail = queuedMailTo('turbo@example.com');
        $before = rowsOf();

        // The account itself is the last thing to go, so a failure there has to bring back everything before it.
        Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'delete from "users"') || str_starts_with(strtolower($query->sql), 'delete from `users`')) {
                throw new RuntimeException('the database refused');
            }
        });

        expect(fn () => erasure()->erase($user))->toThrow(RuntimeException::class, 'the database refused');

        expect(rowsOf())->toBe($before)
            ->and(DB::table('jobs')->where('id', $mail)->exists())->toBeTrue();
    });
});

describe('the record of it', function () {
    it('holds the id of the account and nothing else about the person', function () {
        Log::spy();
        $user = accountWithHistory();

        erasure()->erase($user);

        Log::shouldHaveReceived('info')->with('Account erased.', ['user_id' => $user->id])->once();
    });

    it('is not kept when the account was not erased', function () {
        Log::spy();
        $user = accountWithHistory();
        Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'delete from "users"') || str_starts_with(strtolower($query->sql), 'delete from `users`')) {
                throw new RuntimeException('the database refused');
            }
        });

        expect(fn () => erasure()->erase($user))->toThrow(RuntimeException::class);

        Log::shouldNotHaveReceived('info', ['Account erased.', ['user_id' => $user->id]]);
    });
});

describe('the command', function () {
    it('deletes the account chosen, with --force, and says what it removed', function () {
        $user = accountWithHistory();

        $this->artisan('accounts:delete', ['--id' => $user->id, '--force' => true])
            ->expectsOutputToContain("Account {$user->id} was deleted. Removed:")
            ->assertSuccessful();

        expect(User::query()->find($user->id))->toBeNull()->and(rowsOf()['ledger'])->toBe(0);
    });

    it('finds the account by an address it signs in with, or by its name', function () {
        $byAddress = accountWithHistory();
        $this->artisan('accounts:delete', ['--email' => 'turbo@gmail.com', '--force' => true])->assertSuccessful();
        expect(User::query()->find($byAddress->id))->toBeNull();

        $byName = User::factory()->guest()->create(['name' => 'Maciek_Turbo', 'name_key' => 'maciek_turbo']);
        $this->artisan('accounts:delete', ['--name' => 'MACIEK_TURBO', '--force' => true])->assertSuccessful();
        expect(User::query()->find($byName->id))->toBeNull();
    });

    it('asks first, and removes nothing when the answer is no', function () {
        $user = accountWithHistory();

        $this->artisan('accounts:delete', ['--id' => $user->id])
            ->expectsConfirmation("Delete account {$user->id} (Maciek_Turbo) and everything about it? This cannot be undone.", 'no')
            ->expectsOutputToContain('Nothing was removed.')
            ->assertFailed();

        expect(User::query()->find($user->id))->not->toBeNull();
    });

    it('removes it when the answer is yes', function () {
        $user = accountWithHistory();

        $this->artisan('accounts:delete', ['--id' => $user->id])
            ->expectsConfirmation("Delete account {$user->id} (Maciek_Turbo) and everything about it? This cannot be undone.", 'yes')
            ->assertSuccessful();

        expect(User::query()->find($user->id))->toBeNull();
    });

    it('removes nothing when there is nobody to ask', function () {
        $user = accountWithHistory();

        // Run for real, not through the mock of the test helper that would answer for the operator.
        $status = Artisan::call('accounts:delete', ['--id' => $user->id, '--no-interaction' => true]);

        expect($status)->toBe(1)
            ->and(Artisan::output())->toContain('Nothing was removed.')
            ->and(User::query()->find($user->id))->not->toBeNull();
    });

    it('only says what would be removed with --dry-run', function () {
        $user = accountWithHistory();

        $this->artisan('accounts:delete', ['--id' => $user->id, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was removed.')
            ->assertSuccessful();

        expect(User::query()->find($user->id))->not->toBeNull()->and(rowsOf()['ledger'])->toBe(1);
    });

    it('does not guess: it needs exactly one way to say which account', function (array $options) {
        accountWithHistory();

        $this->artisan('accounts:delete', [...$options, '--force' => true])->expectsOutputToContain('Give exactly one of --id, --email or --name.')->assertFailed();

        expect(User::query()->count())->toBe(1);
    })->with([
        'none' => [[]],
        'two' => [['--id' => '1', '--name' => 'Maciek_Turbo']],
    ]);

    it('removes none, and lists the candidates, when an address leads to more than one account', function () {
        $first = User::factory()->guest()->create(['name' => 'First_Garage']);
        identityOf($first, AccountIdentity::GOOGLE, 'g-1', 'shared@example.com');
        $second = User::factory()->guest()->create(['name' => 'Second_Garage']);
        identityOf($second, AccountIdentity::FACEBOOK, 'f-1', 'shared@example.com');

        $this->artisan('accounts:delete', ['--email' => 'shared@example.com', '--force' => true])
            ->expectsOutputToContain('More than one account matches.')
            ->assertFailed();

        expect(User::query()->count())->toBe(2);
    });

    it('does not remove an account that does not exist, and says so', function () {
        accountWithHistory();

        $this->artisan('accounts:delete', ['--id' => '999999', '--force' => true])->expectsOutputToContain('No account matches.')->assertFailed();

        expect(User::query()->count())->toBe(1);
    });

    it('keeps a record that it was done, with the id and nothing else about the person', function () {
        Log::spy();
        $user = accountWithHistory();

        $this->artisan('accounts:delete', ['--id' => $user->id, '--force' => true])->assertSuccessful();

        Log::shouldHaveReceived('info')->with('Account erased.', ['user_id' => $user->id])->once();
    });

    it('is an export away from the copy of the data a person asked for first', function () {
        $user = accountWithHistory();
        $copy = exported(['--id' => $user->id]);

        $this->artisan('accounts:delete', ['--id' => $user->id, '--force' => true])->assertSuccessful();

        expect($copy['account']['name'])->toBe('Maciek_Turbo')->and($copy['game']['ledger'])->toHaveCount(1);
    });
});
