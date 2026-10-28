<?php

declare(strict_types=1);

use App\Models\ContentActivation;
use App\Models\ContentPack;
use Illuminate\Support\Facades\File;

// The commands that move the content between the repository and the database: import, export, status, activate, and the check that a deploy runs.

/** A copy of the content folder to change: the pack, the rules and the tables, and the schemas the validation reads. */
function contentCopy(): string
{
    $directory = sys_get_temp_dir().'/ride-content-'.bin2hex(random_bytes(4));
    File::copyDirectory((string) config('ride.content_path'), $directory);

    return $directory;
}

describe('content:import', function () {
    it('only checks with --dry-run, and says what is in the content', function () {
        $this->artisan('content:import', ['--dry-run' => true])
            ->expectsOutputToContain('The content is valid (23 vehicles, 74 parts)')
            ->assertExitCode(0);

        expect(ContentPack::query()->count())->toBe(0);
    });

    it('makes the pack, and makes it the live one with --activate', function () {
        $this->artisan('content:import', ['--activate' => true, '--reason' => 'first pack'])
            ->expectsOutputToContain('Pack 2026.10.0 made')
            ->expectsOutputToContain('Pack 2026.10.0 is the live one.')
            ->assertExitCode(0);

        expect(contentPacks()->active()->number)->toBe('2026.10.0')
            ->and(ContentActivation::query()->value('reason'))->toBe('first pack');

        $this->artisan('content:import')
            ->expectsOutputToContain('was there already with this content')
            ->assertExitCode(0);
    });

    it('does nothing with --if-none-active when a pack is live already, which is how a deploy installs the first one and no more', function () {
        $this->artisan('content:import', ['--if-none-active' => true, '--activate' => true])->assertExitCode(0);

        expect(contentPacks()->active()->number)->toBe('2026.10.0');

        $this->artisan('content:import', ['--if-none-active' => true, '--activate' => true])
            ->expectsOutputToContain('A pack is live already: 2026.10.0. Nothing to do.')
            ->assertExitCode(0);

        expect(ContentActivation::query()->count())->toBe(1);
    });

    it('refuses a folder with a document that does not pass, and says which, and makes nothing', function () {
        $directory = contentCopy();

        try {
            $path = "{$directory}/packs/core/vehicles/rdzak-gt-4.json";
            $vehicle = json_decode((string) file_get_contents($path), true);
            unset($vehicle['tier']);
            file_put_contents($path, json_encode($vehicle));

            $this->artisan('content:import', ['path' => $directory])
                ->expectsOutputToContain('vehicles/rdzak-gt-4.json')
                ->assertExitCode(1);

            expect(ContentPack::query()->count())->toBe(0);
        } finally {
            File::deleteDirectory($directory);
        }
    });

    it('refuses a number that other content has, and says what to do', function () {
        $this->artisan('content:import')->assertExitCode(0);

        $directory = contentCopy();

        try {
            $path = "{$directory}/packs/core/pack.json";
            $manifest = json_decode((string) file_get_contents($path), true);
            $manifest['description']['en'] = 'Changed';
            file_put_contents($path, json_encode($manifest));

            $this->artisan('content:import', ['path' => $directory])
                ->expectsOutputToContain('already exists with other content')
                ->assertExitCode(1);

            $this->artisan('content:import', ['path' => $directory, '--number' => '2026.10.1'])->assertExitCode(0);

            expect(ContentPack::query()->count())->toBe(2);
        } finally {
            File::deleteDirectory($directory);
        }
    });
});

describe('content:export', function () {
    it('writes the live pack, or the one named, and not over a folder that has something in it', function () {
        $this->artisan('content:import', ['--activate' => true])->assertExitCode(0);
        $directory = sys_get_temp_dir().'/ride-export-'.bin2hex(random_bytes(4));

        try {
            $this->artisan('content:export', ['path' => $directory])
                ->expectsOutputToContain('written: 106 files in')
                ->assertExitCode(0);

            expect(is_file("{$directory}/packs/core/vehicles/rdzak-gt-4.json"))->toBeTrue();

            $this->artisan('content:export', ['path' => $directory])
                ->expectsOutputToContain('is not empty')
                ->assertExitCode(1);

            $this->artisan('content:export', ['path' => $directory, '--number' => '2026.10.0', '--force' => true])->assertExitCode(0);
            $this->artisan('content:export', ['path' => $directory, '--number' => '2030.1.1', '--force' => true])
                ->expectsOutputToContain('There is no pack 2030.1.1')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($directory);
        }
    });

    it('has nothing to write when no pack is live', function () {
        $this->artisan('content:export', ['path' => sys_get_temp_dir().'/ride-export-nothing'])
            ->expectsOutputToContain('No pack is live')
            ->assertExitCode(1);
    });
});

describe('content:status and content:activate', function () {
    it('says where the game reads from and which pack is live', function () {
        $this->artisan('content:status')->expectsOutputToContain('There is no pack in the database')->assertExitCode(0);

        $this->artisan('content:import', ['--activate' => true])->assertExitCode(0);

        $this->artisan('content:status')
            ->expectsOutputToContain('The game reads its content from the files')
            ->expectsOutputToContain('2026.10.0')
            ->assertExitCode(0);

        config(['ride.content_source' => 'database']);

        $this->artisan('content:status')->expectsOutputToContain('The game reads its content from the database.')->assertExitCode(0);
    });

    it('makes a pack live by its number, goes back to an earlier one the same way, and does not know a number that is not there', function () {
        $first = contentPacks()->import(repositoryContent(), checkTables: false, activate: true)->pack;
        contentPacks()->import(repositoryContent(), '2026.10.1', checkTables: false);

        $this->artisan('content:activate', ['number' => '2026.10.1', '--reason' => 'try it'])
            ->expectsOutputToContain('Pack 2026.10.1 is the live one.')
            ->assertExitCode(0);
        $this->artisan('content:activate', ['number' => '2026.10.1'])
            ->expectsOutputToContain('is the live one already')
            ->assertExitCode(0);
        $this->artisan('content:activate', ['number' => '2026.10.0', '--reason' => 'back'])->assertExitCode(0);
        $this->artisan('content:activate', ['number' => '2031.1.1'])
            ->expectsOutputToContain('There is no pack 2031.1.1')
            ->assertExitCode(1);

        expect(contentPacks()->active()->id)->toBe($first->id)
            ->and(ContentActivation::query()->count())->toBe(3);
    });
});

describe('content:check, which a deploy runs', function () {
    it('checks the content files while the game reads from files', function () {
        $this->artisan('content:check')
            ->expectsOutputToContain('The content files is whole and fits this version of the game.')
            ->assertExitCode(0);
    });

    it('wants a pack to be live when the game reads from the database, and installs the one of the folder with --install', function () {
        config(['ride.content_source' => 'database']);

        $this->artisan('content:check')->expectsOutputToContain('No content pack is live')->assertExitCode(1);

        $this->artisan('content:check', ['--install' => true])
            ->expectsOutputToContain('Pack 2026.10.0 installed and made live.')
            ->expectsOutputToContain('The live pack 2026.10.0 is whole and fits this version of the game.')
            ->assertExitCode(0);

        // The second deploy finds it there and installs nothing.
        $this->artisan('content:check', ['--install' => true])
            ->doesntExpectOutputToContain('installed')
            ->assertExitCode(0);

        expect(ContentActivation::query()->count())->toBe(1);
    });

    it('fails when the live pack is not what it was made with, or does not fit this version of the game', function () {
        config(['ride.content_source' => 'database']);
        $this->artisan('content:check', ['--install' => true])->assertExitCode(0);

        $pack = contentPacks()->active();
        $pack->forceFill(['checksum' => str_repeat('0', 64)])->save();
        app()->forgetScopedInstances();

        $this->artisan('content:check')->expectsOutputToContain('are not the ones it was made with')->assertExitCode(1);
    });
});
