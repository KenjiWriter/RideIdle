<?php

declare(strict_types=1);

use App\Models\LedgerEntry;
use App\Models\RaceRun;
use App\Models\User;
use App\Services\Ledger;

// Accounts for a load test: made with a token each in a file, because the API lets one address sign up ten guests an hour, and removed again by their names.

function loadTestFile(): string
{
    return sys_get_temp_dir().'/loadtest-'.bin2hex(random_bytes(4)).'.json';
}

describe('loadtest:accounts', function () {
    it('makes the accounts, with names that loadtest:cleanup finds, and writes a token for each to the file and not to the screen', function () {
        $path = loadTestFile();

        $this->artisan('loadtest:accounts', ['count' => 3, '--output' => $path])
            ->expectsOutputToContain('Made 3 accounts (LoadTest_0001 to LoadTest_0003)')
            ->doesntExpectOutputToContain('|')
            ->assertSuccessful();

        $made = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        expect(array_column($made, 'name'))->toBe(['LoadTest_0001', 'LoadTest_0002', 'LoadTest_0003'])
            ->and(User::query()->where('name', 'like', 'LoadTest_%')->count())->toBe(3);

        // A token of the file is a token of that driver.
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$made[1]['token']])->assertOk()->assertJsonPath('data.name', 'LoadTest_0002');

        unlink($path);
    });

    it('goes on from the names that are taken', function () {
        User::factory()->guest()->create(['name' => 'LoadTest_0001', 'name_key' => User::nameKey('LoadTest_0001')]);
        $path = loadTestFile();

        $this->artisan('loadtest:accounts', ['count' => 2, '--output' => $path])->assertSuccessful();

        expect(array_column(json_decode((string) file_get_contents($path), true), 'name'))->toBe(['LoadTest_0002', 'LoadTest_0003']);

        unlink($path);
    });

    it('does not write over a file that is there, and asks for a file, a count and a prefix that are right', function () {
        $path = loadTestFile();
        file_put_contents($path, 'precious');

        $this->artisan('loadtest:accounts', ['count' => 1, '--output' => $path])->assertFailed();
        $this->artisan('loadtest:accounts', ['count' => 1])->assertFailed();
        $this->artisan('loadtest:accounts', ['count' => 0, '--output' => loadTestFile()])->assertFailed();
        $this->artisan('loadtest:accounts', ['count' => 2001, '--output' => loadTestFile()])->assertFailed();
        $this->artisan('loadtest:accounts', ['count' => 1, '--output' => loadTestFile(), '--prefix' => 'Maciek_'])->assertFailed();

        expect(file_get_contents($path))->toBe('precious')
            ->and(User::query()->count())->toBe(0);

        unlink($path);
    });

    it('does not make accounts in a production environment unless it is made to', function () {
        app()->detectEnvironment(fn () => 'production');
        $path = loadTestFile();

        $this->artisan('loadtest:accounts', ['count' => 1, '--output' => $path])->assertFailed();

        expect(file_exists($path))->toBeFalse()->and(User::query()->count())->toBe(0);

        $this->artisan('loadtest:accounts', ['count' => 1, '--output' => $path, '--force' => true])->assertSuccessful();

        expect(User::query()->count())->toBe(1);

        unlink($path);
    });
});

describe('loadtest:cleanup', function () {
    it('removes the test accounts with what they did, and no other account', function () {
        $test = User::factory()->guest()->create(['name' => 'LoadTest_0001', 'name_key' => User::nameKey('LoadTest_0001')]);
        $run = RaceRun::factory()->create(['user_id' => $test->id]);
        (new Ledger)->post($test->id, 500, 'gift', 'loadtest-gift');

        // Names that look like a test account but are not one: another underscore, another prefix, another case.
        $keep = [
            User::factory()->guest()->create(['name' => 'LoadTestX0001', 'name_key' => User::nameKey('LoadTestX0001')]),
            User::factory()->guest()->create(['name' => 'LoadTester', 'name_key' => User::nameKey('LoadTester')]),
            User::factory()->guest()->create(['name' => 'Maciek_Turbo', 'name_key' => User::nameKey('Maciek_Turbo')]),
        ];

        $this->artisan('loadtest:cleanup')->expectsOutputToContain('Removed 1 accounts.')->assertSuccessful();

        expect(User::query()->whereKey($test->id)->exists())->toBeFalse()
            ->and(RaceRun::query()->withoutGlobalScopes()->whereKey($run->id)->exists())->toBeFalse()
            ->and(LedgerEntry::query()->where('user_id', $test->id)->count())->toBe(0)
            ->and(User::query()->whereIn('id', array_map(fn (User $user) => $user->id, $keep))->count())->toBe(3);
    });

    it('says how many it would remove, and removes nothing, on a dry run', function () {
        User::factory()->guest()->create(['name' => 'LoadTest_0001', 'name_key' => User::nameKey('LoadTest_0001')]);

        $this->artisan('loadtest:cleanup', ['--dry-run' => true])->expectsOutputToContain('1 accounts would be removed')->assertSuccessful();

        expect(User::query()->count())->toBe(1);
    });

    it('does not take a prefix that could be any account\'s', function () {
        User::factory()->guest()->create(['name' => 'Maciek_Turbo', 'name_key' => User::nameKey('Maciek_Turbo')]);

        $this->artisan('loadtest:cleanup', ['--prefix' => 'Maciek'])->assertFailed();
        $this->artisan('loadtest:cleanup', ['--prefix' => ''])->assertFailed();

        expect(User::query()->count())->toBe(1);
    });
});
