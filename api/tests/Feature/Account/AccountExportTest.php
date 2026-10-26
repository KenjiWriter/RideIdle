<?php

declare(strict_types=1);

use App\Models\AccountIdentity;
use App\Models\AccountToken;
use App\Models\Settlement;
use App\Models\User;
use App\Services\AccountExport;
use App\Services\Ledger;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

describe('what is exported', function () {
    it('is who the account is to the game', function () {
        $user = accountWithHistory();

        $account = (new AccountExport)->for($user)['account'];

        expect($account)->toMatchArray([
            'id' => $user->id,
            'name' => 'Maciek_Turbo',
            'locale' => 'pl',
            'email' => 'turbo@example.com',
            'terms_version' => '2026-10-23',
            'has_recovery_code' => true,
        ])
            ->and($account['terms_accepted_at'])->toBeString()
            ->and($account['age_confirmed_at'])->toBeString()
            ->and($account['secured_at'])->toBeString()
            ->and($account['recovery_code_set_at'])->toBeString();
    });

    it('says when a moment never happened', function () {
        $guest = User::factory()->guest()->create(['last_seen_at' => null]);

        $account = (new AccountExport)->for($guest)['account'];

        expect($account['secured_at'])->toBeNull()
            ->and($account['last_seen_at'])->toBeNull()
            ->and($account['email'])->toBeNull()
            ->and($account['has_recovery_code'])->toBeFalse();
    });

    it('lists how the account signs in, with what each provider reported', function () {
        $user = accountWithHistory();

        $identities = (new AccountExport)->for($user)['identities'];

        expect(collect($identities)->map(fn (array $identity) => $identity['provider'].':'.$identity['subject'].':'.$identity['email'])->all())
            ->toBe(['email:turbo@example.com:turbo@example.com', 'google:1098765:turbo@gmail.com'])
            ->and($identities[0]['linked_at'])->toBeString();
    });

    it('lists the devices that are signed in, by name and time and not by token', function () {
        $user = accountWithHistory();

        $devices = (new AccountExport)->for($user)['devices'];

        expect($devices)->toHaveCount(1)
            ->and($devices[0]['name'])->toBe('google-login')
            ->and(array_keys($devices[0]))->toBe(['name', 'created_at', 'last_used_at']);
    });

    it('lists the links that wait to be used, without their secrets', function () {
        $user = User::factory()->create();
        tokenWith(['user_id' => $user->id, 'email' => 'turbo@example.com', 'purpose' => AccountToken::LOGIN]);

        $links = (new AccountExport)->for($user)['pending_links'];

        expect($links)->toHaveCount(1)
            ->and($links[0]['purpose'])->toBe('login')
            ->and($links[0]['email'])->toBe('turbo@example.com')
            ->and(array_keys($links[0]))->not->toContain('token_hash', 'challenge');
    });

    it('is what the driver earned and did: wallet, runs with their history, and the ledger', function () {
        $user = accountWithHistory();

        $game = (new AccountExport)->for($user)['game'];

        expect($game['wallet'])->toMatchArray(['cash_cents' => 1_550, 'xp' => 0])
            ->and($game['runs'])->toHaveCount(1)
            ->and($game['runs'][0]['settlements'])->toHaveCount(1)
            ->and($game['runs'][0]['settlements'][0]['events'][0])->toMatchArray(['type' => 'lap_batch', 'payload' => ['laps' => 1]])
            ->and($game['ledger'])->toHaveCount(1)
            ->and($game['ledger'][0])->toMatchArray(['delta' => 1_550, 'reason' => 'laps']);
    });

    it('has the cars with their parts, the spare parts and the jobs of the workshop', function () {
        $game = (new AccountExport)->for(accountWithHistory())['game'];

        expect($game['cars'])->toHaveCount(1)
            ->and($game['cars'][0])->toMatchArray(['vehicle_slug' => 'rdzak-gt-4', 'level' => 3])
            ->and($game['cars'][0]['parts'])->toHaveCount(1)
            ->and($game['cars'][0]['parts'][0])->toMatchArray(['part_slug' => 'sport-exhaust-t2', 'mounted_slot' => 'exhaust'])
            ->and($game['inventory'])->toHaveCount(1)
            ->and($game['inventory'][0])->toMatchArray(['part_slug' => 'street-tires-t2'])
            ->and($game['garages'])->toHaveCount(1)
            ->and($game['garages'][0])->toMatchArray(['region' => 'zlomowo', 'capacity' => 3])
            ->and($game['workshop_jobs'])->toHaveCount(1)
            ->and($game['workshop_jobs'][0])->toMatchArray(['kind' => 'mount', 'status' => 'queued']);
    });

    it('has the steps of the tutorial that the driver reached', function () {
        $game = (new AccountExport)->for(accountWithHistory())['game'];

        expect($game['tutorial'])->toHaveCount(1)
            ->and($game['tutorial'][0])->toMatchArray(['step_key' => 'first-lap', 'state' => 'active'])
            ->and($game['tutorial'][0])->not->toHaveKeys(['id', 'user_id']);
    });

    it('has an empty game for an account that never played', function () {
        $game = (new AccountExport)->for(User::factory()->guest()->create())['game'];

        expect($game)->toBe(['wallet' => null, 'cars' => [], 'inventory' => [], 'garages' => [], 'storage_openings' => [], 'workshop_jobs' => [], 'travels' => [], 'tutorial' => [], 'runs' => [], 'ledger' => []]);
    });

    it('is only about the account, and never about another', function () {
        $user = accountWithHistory();
        $other = User::factory()->create(['name' => 'Somebody_Else', 'email' => 'other@example.com']);
        identityOf($other, AccountIdentity::GOOGLE, 'other-google', 'other@example.com');
        aRun(['user_id' => $other->id]);
        (new Ledger)->post($other->id, 999, 'gift', 'other-gift');
        $other->createToken('other-device');

        $export = (new AccountExport)->for($user);
        $json = json_encode($export, JSON_THROW_ON_ERROR);

        expect($json)->not->toContain('Somebody_Else')->not->toContain('other@example.com')->not->toContain('other-google')->not->toContain('other-device')
            // The game of the other driver is not in it either: one run, one entry of the ledger, the cash of this account.
            ->and($export['game']['runs'])->toHaveCount(1)
            ->and($export['game']['runs'][0]['id'])->toBe($user->raceRuns()->firstOrFail()->id)
            ->and($export['game']['ledger'])->toHaveCount(1)
            ->and($export['game']['wallet']['cash_cents'])->toBe(1_550);
    });
});

describe('what is never exported', function () {
    it('is a secret: the password, the recovery code and the tokens of the devices', function () {
        $user = accountWithHistory();
        $user->forceFill(['password' => 'a-password-of-the-driver'])->save();

        // Slashes are not escaped, so that a hash that has one is found as it is.
        $json = json_encode((new AccountExport)->for($user->refresh()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        expect($json)
            ->not->toContain($user->getAuthPassword())
            ->not->toContain(str_repeat('a', 64))
            ->not->toContain(PersonalAccessToken::query()->firstOrFail()->token)
            ->not->toContain('remember_token')
            ->not->toContain('password');
    });

    it('is the internals of a run, which are not the data of the person', function () {
        $user = accountWithHistory();

        $run = (new AccountExport)->for($user)['game']['runs'][0];

        expect(array_keys($run))->not->toContain('seed', 'config', 'user_id')
            ->and($run)->toHaveKeys(['id', 'car_slug', 'track_slug', 'status', 'laps_done', 'fuel_ml']);
    });
});

describe('the command', function () {
    it('prints the export as JSON that a program can read, for the account chosen by id', function () {
        $user = accountWithHistory();

        $data = exported(['--id' => $user->id]);

        expect($data['account']['name'])->toBe('Maciek_Turbo')
            ->and($data)->toHaveKeys(['exported_at', 'account', 'identities', 'devices', 'pending_links', 'game']);
    });

    it('finds the account by the address it signs in with, or by its name', function () {
        $user = accountWithHistory();

        expect(exported(['--email' => 'turbo@gmail.com'])['account']['id'])->toBe($user->id)
            ->and(exported(['--name' => 'maciek_TURBO'])['account']['id'])->toBe($user->id);
    });

    it('keeps the letters of a name as they are, and the slashes of an address', function () {
        $user = User::factory()->guest()->create(['name' => 'Zażółć_Gęślą', 'name_key' => 'zażółć_gęślą']);

        Artisan::call('accounts:export', ['--id' => $user->id]);

        expect(Artisan::output())->toContain('Zażółć_Gęślą')->not->toContain('\u');
    });

    it('changes nothing', function () {
        $user = accountWithHistory();
        $before = [User::query()->count(), AccountIdentity::query()->count(), PersonalAccessToken::query()->count(), Settlement::query()->count()];

        exported(['--id' => $user->id]);

        expect([User::query()->count(), AccountIdentity::query()->count(), PersonalAccessToken::query()->count(), Settlement::query()->count()])->toBe($before)
            ->and($user->fresh()->last_seen_at?->equalTo($user->last_seen_at))->toBe(true);
    });

    it('does not guess: it needs exactly one way to say which account', function (array $options) {
        accountWithHistory();

        $this->artisan('accounts:export', $options)->expectsOutputToContain('Give exactly one of --id, --email or --name.')->assertFailed();
    })->with([
        'none' => [[]],
        'two' => [['--id' => '1', '--email' => 'turbo@example.com']],
        'all three' => [['--id' => '1', '--email' => 'turbo@example.com', '--name' => 'Maciek_Turbo']],
        'an empty one' => [['--name' => '  ']],
    ]);

    it('says when nothing matches, and when the id is not a number', function () {
        accountWithHistory();

        $this->artisan('accounts:export', ['--email' => 'nobody@example.com'])->expectsOutputToContain('No account matches.')->assertFailed();
        $this->artisan('accounts:export', ['--id' => '999999'])->expectsOutputToContain('No account matches.')->assertFailed();
        $this->artisan('accounts:export', ['--id' => '1 OR 1=1'])->expectsOutputToContain('The id must be a number.')->assertFailed();
    });

    it('exports none, and lists the candidates, when an address leads to more than one account', function () {
        $first = User::factory()->guest()->create(['name' => 'First_Garage']);
        identityOf($first, AccountIdentity::GOOGLE, 'g-1', 'shared@example.com');
        $second = User::factory()->guest()->create(['name' => 'Second_Garage']);
        identityOf($second, AccountIdentity::FACEBOOK, 'f-1', 'shared@example.com');

        $this->artisan('accounts:export', ['--email' => 'shared@example.com'])
            ->expectsOutputToContain('More than one account matches. Choose one by its id (--id):')
            ->expectsOutputToContain('First_Garage')
            ->expectsOutputToContain('Second_Garage')
            ->assertFailed();

        expect(exported(['--id' => $second->id])['account']['name'])->toBe('Second_Garage');
    });

    describe('with --output', function () {
        beforeEach(function () {
            $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ride-export-'.bin2hex(random_bytes(6)).'.json';
        });

        afterEach(function () {
            @unlink($this->file);
        });

        it('writes the export to a file, and prints only where it went', function () {
            $user = accountWithHistory();

            $this->artisan('accounts:export', ['--id' => $user->id, '--output' => $this->file])
                ->expectsOutputToContain("Exported account {$user->id} to {$this->file}.")
                ->assertSuccessful();

            $written = json_decode((string) file_get_contents($this->file), true, flags: JSON_THROW_ON_ERROR);
            expect($written['account']['id'])->toBe($user->id)->and($written['identities'])->toHaveCount(2);
        });

        it('makes the file readable by its owner only', function () {
            $user = accountWithHistory();

            $this->artisan('accounts:export', ['--id' => $user->id, '--output' => $this->file])->assertSuccessful();

            if (PHP_OS_FAMILY === 'Windows') {
                $this->markTestSkipped('Windows has no file modes.');
            }

            expect(substr(sprintf('%o', fileperms($this->file)), -4))->toBe('0600');
        });

        it('does not write over a file that is there', function () {
            $user = accountWithHistory();
            file_put_contents($this->file, 'precious');

            $this->artisan('accounts:export', ['--id' => $user->id, '--output' => $this->file])
                ->expectsOutputToContain('is there already. Nothing was written.')
                ->assertFailed();

            expect(file_get_contents($this->file))->toBe('precious');
        });

        it('says so when the file cannot be written', function () {
            $user = accountWithHistory();
            $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'no-such-folder-'.bin2hex(random_bytes(4)).DIRECTORY_SEPARATOR.'export.json';

            $this->artisan('accounts:export', ['--id' => $user->id, '--output' => $missing])->expectsOutputToContain('Could not write')->assertFailed();
        });

        it('writes nothing for an account it cannot choose', function () {
            $this->artisan('accounts:export', ['--email' => 'nobody@example.com', '--output' => $this->file])->assertFailed();

            expect(file_exists($this->file))->toBeFalse();
        });
    });
});
