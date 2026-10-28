<?php

declare(strict_types=1);

use App\Content\Publishing\ContentBundle;
use App\Filament\Resources\ContentPacks\Pages\ManageContentPacks;
use App\Models\Admin;
use App\Models\ContentPack;
use App\Services\ContentPublisher;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

// A pack as a file in the panel: any admin downloads one, a publisher uploads one that came from another environment. The content of the repository is what is
// in the database, and it is live.

function aBundleFile(string $number = '2026.10.7', ?Closure $change = null): UploadedFile
{
    $source = contentWith(['vehicles/rdzak-gt-4' => function (array $car) use ($change): array {
        $car['name']['fr'] = 'Rdzak GT-4 (autre)';

        return $change === null ? $car : $change($car);
    }]);

    return UploadedFile::fake()->createWithContent('pack.json', app(ContentBundle::class)->export($source, $number));
}

beforeEach(function () {
    contentPacks()->import(repositoryContent(), activate: true, checkTables: false);
    app()->setLocale('pl');
});

describe('downloading a pack', function () {
    it('is for any admin, and the file is the pack as a bundle', function () {
        signedInAdmin(Admin::ROLE_EDITOR);
        $pack = ContentPack::query()->firstOrFail();

        Livewire::test(ManageContentPacks::class)
            ->callTableAction('export', $pack)
            ->assertFileDownloaded("pack-{$pack->number}.json", app(ContentPublisher::class)->export($pack));
    });
});

describe('importing a pack', function () {
    it('is offered to a publisher and not to an editor', function () {
        signedInAdmin(Admin::ROLE_EDITOR);
        Livewire::test(ManageContentPacks::class)->assertActionHidden('import');

        signedInAdmin(Admin::ROLE_PUBLISHER, 'publisher@example.com');
        Livewire::test(ManageContentPacks::class)->assertActionVisible('import');
    });

    it('makes the pack of a file, and it is not live unless the publisher says so', function () {
        signedInAdmin(Admin::ROLE_PUBLISHER);
        $live = contentPacks()->active();

        Livewire::test(ManageContentPacks::class)
            ->callAction('import', ['bundle' => aBundleFile(), 'number' => null, 'activate' => false])
            ->assertNotified(__('admin.packs.imported', ['number' => '2026.10.7']));

        expect(ContentPack::query()->where('number', '2026.10.7')->exists())->toBeTrue()->and(contentPacks()->active()->id)->toBe($live->id);
    });

    it('makes it live when the publisher says so', function () {
        signedInAdmin(Admin::ROLE_PUBLISHER);

        Livewire::test(ManageContentPacks::class)->callAction('import', ['bundle' => aBundleFile(), 'number' => null, 'activate' => true]);

        expect(contentPacks()->active()->number)->toBe('2026.10.7');
    });

    it('says when the pack was there already', function () {
        signedInAdmin(Admin::ROLE_PUBLISHER);

        Livewire::test(ManageContentPacks::class)->callAction('import', ['bundle' => aBundleFile(), 'number' => null, 'activate' => false]);
        Livewire::test(ManageContentPacks::class)
            ->callAction('import', ['bundle' => aBundleFile(), 'number' => null, 'activate' => false])
            ->assertNotified(__('admin.packs.import_existed', ['number' => '2026.10.7']));
    });

    it('is refused for a file that is not a bundle, and for content that does not validate', function () {
        signedInAdmin(Admin::ROLE_PUBLISHER);

        Livewire::test(ManageContentPacks::class)
            ->callAction('import', ['bundle' => UploadedFile::fake()->createWithContent('pack.json', '{"hello": "world"}'), 'number' => null, 'activate' => false])
            ->assertNotified(__('admin.packs.import_refused'));

        Livewire::test(ManageContentPacks::class)
            ->callAction('import', ['bundle' => aBundleFile('2026.10.8', fn (array $car): array => [...$car, 'power_kw' => -5]), 'number' => null, 'activate' => false])
            ->assertNotified(__('admin.packs.import_refused'));

        expect(ContentPack::query()->count())->toBe(1);
    });
});
