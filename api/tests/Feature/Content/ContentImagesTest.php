<?php

declare(strict_types=1);

use App\Content\Catalog;
use App\Content\ContentException;
use App\Content\Definitions\DefinitionForm;
use App\Content\ImageRefused;
use App\Content\PackValidator;
use App\Content\Publishing\ContentBundle;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Http\Resources\PartResource;
use App\Models\Admin;
use App\Models\ContentDefinition;
use App\Models\ContentImage;
use App\Models\ContentPack;
use App\Models\User;
use App\Services\ContentImages;
use App\Services\ContentPublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

// The pictures of cars and parts: kept under the checksum of their bytes, named by the documents, carried by the bundles, served by the API and put in the
// forms of the panel. The files here are made of the headers a picture starts with, which is all the game looks at.

/**
 * A picture of a kind: a PNG that is whole, a WebP or a JPEG that is the header of one (the game never decodes a picture, it looks at the header).
 *
 * @param  string  $seed  what makes it another file of the same size
 */
function aPictureOf(string $kind = 'png', int $width = 64, int $height = 48, string $seed = ''): string
{
    if ($kind === 'png') {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = str_repeat("\x00".str_repeat("\x20\x40\x80", $width), $height);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('tEXt', "seed\x00{$seed}")
            .$chunk('IDAT', (string) gzcompress($rows))
            .$chunk('IEND', '');
    }

    if ($kind === 'webp') {
        $header = "VP8L\x0d\x00\x00\x00\x2f".pack('V', ($width - 1) | (($height - 1) << 14)).str_repeat("\x00", 8).$seed;

        return 'RIFF'.pack('V', strlen($header) + 4).'WEBP'.$header;
    }

    if ($kind === 'gif') {
        return 'GIF89a'.pack('vv', $width, $height)."\x00\x00\x00;".$seed;
    }

    return "\xFF\xD8\xFF\xC0".pack('n', 17)."\x08".pack('nn', $height, $width)."\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xD9".$seed;
}

function images(): ContentImages
{
    return app(ContentImages::class);
}

describe('keeping a picture', function () {
    it('takes PNG, WebP and JPEG, and names each by the checksum of its bytes and its kind', function () {
        foreach (['png' => 'png', 'webp' => 'webp', 'jpeg' => 'jpg'] as $made => $kind) {
            $bytes = aPictureOf($made === 'jpeg' ? 'jpg' : $made);
            $name = images()->store($bytes);

            expect($name)->toBe(hash('sha256', $bytes).'.'.$kind)
                ->and(images()->find($name)['bytes'])->toBe($bytes);
        }

        expect(ContentImage::query()->count())->toBe(3)
            ->and(ContentImage::query()->where('kind', 'webp')->first()->only(['mime', 'width', 'height']))->toBe(['mime' => 'image/webp', 'width' => 64, 'height' => 48]);
    });

    it('keeps the same file once, and another file next to it', function () {
        $first = images()->store(aPictureOf('png'));

        expect(images()->store(aPictureOf('png')))->toBe($first)
            ->and(images()->store(aPictureOf('png', seed: 'other')))->not->toBe($first)
            ->and(ContentImage::query()->count())->toBe(2);
    });

    it('refuses what is not a picture of the game, and says why', function (string $reason, string $bytes) {
        try {
            images()->store($bytes);
            $refused = null;
        } catch (ImageRefused $refusal) {
            $refused = $refusal->reason;
        }

        expect($refused)->toBe($reason)->and(ContentImage::query()->count())->toBe(0);
    })->with([
        'nothing' => ['empty', ''],
        'a text' => ['kind', 'this is not a picture'],
        'an SVG, which can carry a script' => ['kind', '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script></svg>'],
        'a GIF' => ['kind', aPictureOf('gif')],
        'more than a megabyte' => ['too_big', aPictureOf('png', seed: str_repeat('x', ContentImages::MAX_BYTES))],
        'a side that is too short' => ['dimensions', aPictureOf('png', 16, 64)],
        'a side that is too long' => ['dimensions', aPictureOf('webp', 4096, 64)],
    ]);

    it('says which names are not kept, and none when the store is not there yet', function () {
        $kept = images()->store(aPictureOf('png'));
        $absent = hash('sha256', 'nothing').'.png';

        expect(images()->missing([$kept, $absent, $absent]))->toBe([$absent]);

        Schema::drop('content_images');
        expect((new ContentImages)->missing([$absent]))->toBe([]);
    });
});

describe('serving a picture', function () {
    it('gives the bytes with the type of the file, for a year, and as nothing but a picture', function () {
        $bytes = aPictureOf('png');
        $name = images()->store($bytes);

        $response = $this->get("/api/v1/media/{$name}")->assertOk();

        expect($response->getContent())->toBe($bytes)
            ->and($response->headers->get('Content-Type'))->toBe('image/png')
            ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=31536000')->toContain('immutable')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'")
            ->and($response->headers->get('ETag'))->toBe('"'.hash('sha256', $bytes).'"');
    });

    it('needs no token, and answers a client that has the picture with nothing', function () {
        $name = images()->store(aPictureOf('webp'));

        $this->get("/api/v1/media/{$name}", ['If-None-Match' => '"'.hash('sha256', aPictureOf('webp')).'"'])->assertStatus(304);
    });

    it('is not there for a name that is not kept, or that is not a name', function () {
        $this->get('/api/v1/media/'.hash('sha256', 'nothing').'.png')->assertNotFound();
        $this->get('/api/v1/media/'.hash('sha256', 'nothing').'.svg')->assertNotFound();
        $this->get('/api/v1/media/logo.png')->assertNotFound();
    });

    it('is the address that a document gets, from its name', function () {
        $name = hash('sha256', 'a').'.jpg';

        expect(ContentImages::urlOf(['image' => $name]))->toBe("/api/v1/media/{$name}")
            ->and(ContentImages::urlOf(['slug' => 'x']))->toBeNull()
            ->and(ContentImages::urlOf(['image' => '../../.env']))->toBeNull();
    });
});

function carWithPicture(string $name): array
{
    return ['vehicles/rdzak-gt-4' => fn (array $car): array => [...$car, 'image' => $name]];
}

/** A pack, not live, whose Rdzak has a picture. @return array{0: ContentPack, 1: string, 2: string} the pack, the name and the bytes of the picture */
function packWithPicture(string $number = '2026.10.5'): array
{
    $bytes = aPictureOf('png', seed: 'bundle');
    $name = images()->store($bytes);
    $import = contentPacks()->import(contentWith(carWithPicture($name)), $number, checkTables: false);

    return [$import->pack, $name, $bytes];
}

function fileOf(string $bytes, string $name = 'car.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/** The draft of a car or of a part as the document it holds. @return array<string, mixed> */
function pictureDraft(string $kind, string $slug): array
{
    return json_decode((string) ContentDefinition::query()->where('kind', $kind)->where('slug', $slug)->firstOrFail()->draft, true);
}

describe('the pictures in a pack', function () {
    it('are named by the documents of cars and of parts, and the schema judges the name', function () {
        $name = images()->store(aPictureOf('png'));
        $result = PackValidator::fromConfig()->validateSource(contentWith([...carWithPicture($name), 'parts/street-tires-t2' => fn (array $part): array => [...$part, 'image' => $name]]));

        expect($result->errors())->toBe([])->and($result->warnings())->toBe([]);

        $wrong = PackValidator::fromConfig()->validateSource(contentWith(carWithPicture('rdzak.png')));

        expect($wrong->isValid())->toBeFalse()->and($wrong->errors()[0]['pointer'])->toBe('/image');
    });

    it('are warned about when the store does not have them, which does not stop a pack', function () {
        $name = hash('sha256', 'not kept').'.png';
        $result = PackValidator::fromConfig()->validateSource(contentWith(carWithPicture($name)));

        expect($result->isValid())->toBeTrue()
            ->and($result->warnings()[0])->toMatchArray(['file' => 'vehicles/rdzak-gt-4.json', 'pointer' => '/image'])
            ->and($result->warnings()[0]['message'])->toContain($name);
    });

    it('are found in the cars and the parts of a source', function () {
        $car = images()->store(aPictureOf('png'));
        $part = images()->store(aPictureOf('webp'));

        expect(images()->namesIn(contentWith([...carWithPicture($car), 'parts/street-tires-t2' => fn (array $p): array => [...$p, 'image' => $part]])))->toEqualCanonicalizing([$car, $part])
            ->and(images()->namesIn(repositoryContent()))->toBe([]);
    });

    it('go to the players as the address of the picture: of a starter car, of a car in the garage and of a part', function () {
        $name = images()->store(aPictureOf('png'));
        $part = images()->store(aPictureOf('webp'));
        contentPacks()->import(contentWith([...carWithPicture($name), 'parts/street-tires-t2' => fn (array $p): array => [...$p, 'image' => $part]]), activate: true, checkTables: false);
        readFromDatabase();

        $cars = $this->getJson('/api/v1/starter-cars', bearerFor(User::factory()->guest()->create()))->assertOk()->json('data');

        expect(collect($cars)->firstWhere('slug', 'rdzak-gt-4')['image_url'])->toBe("/api/v1/media/{$name}")
            ->and(collect($cars)->firstWhere('slug', 'kuna-4x4')['image_url'])->toBeNull();

        [$driver, $run] = driverWithRun();

        $this->getJson('/api/v1/garage', bearerFor($driver))->assertOk()->assertJsonPath('data.car.vehicle.image_url', "/api/v1/media/{$name}");
        $this->getJson('/api/v1/runs/current', bearerFor($driver))->assertOk()->assertJsonPath('data.car.image_url', "/api/v1/media/{$name}");

        expect(PartResource::describe(app(Catalog::class)->part('street-tires-t2'))['image_url'])->toBe("/api/v1/media/{$part}");
    });
});

describe('the pictures in a bundle', function () {
    beforeEach(function () {
        contentPacks()->import(repositoryContent(), activate: true, checkTables: false);
        app()->setLocale('pl');
    });

    it('carries the pictures that the pack names, and nothing else', function () {
        [$pack, $name, $bytes] = packWithPicture();
        images()->store(aPictureOf('png', seed: 'not named'));

        $bundle = json_decode(app(ContentPublisher::class)->export($pack), true);

        expect($bundle['images'])->toBe([$name => base64_encode($bytes)])
            ->and(json_decode(app(ContentPublisher::class)->export(contentPacks()->active()), true))->not->toHaveKey('images');
    });

    it('brings them to an environment that has none, before the pack is judged', function () {
        [$pack, $name, $bytes] = packWithPicture();
        $text = app(ContentPublisher::class)->export($pack);

        ContentImage::query()->delete();
        $admin = anAdmin(['role' => Admin::ROLE_PUBLISHER]);

        $import = app(ContentPublisher::class)->import($admin, $text, number: '2026.10.9');

        expect($import->warnings)->toBe([])
            ->and(images()->find($name)['bytes'])->toBe($bytes);
    });

    it('is refused when a picture in it is not the one it is named after', function () {
        [$pack, , $bytes] = packWithPicture();
        $text = str_replace(base64_encode($bytes), base64_encode(aPictureOf('png', seed: 'swapped')), app(ContentPublisher::class)->export($pack));
        ContentImage::query()->delete();

        expect(fn () => app(ContentPublisher::class)->import(anAdmin(['role' => Admin::ROLE_PUBLISHER]), $text, number: '2026.10.9'))
            ->toThrow(ContentException::class, 'its name is the checksum of')
            ->and(ContentImage::query()->count())->toBe(0);
    });

    it('is refused when the pictures in it are not names and base64', function () {
        $bundle = json_decode(app(ContentBundle::class)->export(repositoryContent(), '2026.10.6'), true);
        $bundle['images'] = ['logo.png' => 'AAAA'];

        expect(fn () => app(ContentBundle::class)->images(json_encode($bundle)))->toThrow(ContentException::class, 'not a picture that the game keeps');

        $bundle['images'] = [hash('sha256', 'x').'.png' => '!!! not base64 !!!'];

        expect(fn () => app(ContentBundle::class)->images(json_encode($bundle)))->toThrow(ContentException::class);
    });
});

describe('the picture in the forms of the panel', function () {
    beforeEach(function () {
        contentPacks()->import(repositoryContent(), checkTables: false);
        app()->setLocale('pl');
    });

    it('puts the file that is chosen in the store, and its name in the draft', function () {
        signedInAdmin();
        $record = ContentDefinition::query()->where('kind', 'vehicle')->where('slug', 'rdzak-gt-4')->firstOrFail();
        $bytes = aPictureOf('png');

        Livewire::test(EditVehicle::class, ['record' => $record->getRouteKey()])
            ->fillForm(['image_upload' => fileOf($bytes)])
            ->call('save')
            ->assertHasNoFormErrors();

        $name = hash('sha256', $bytes).'.png';

        expect(pictureDraft('vehicle', 'rdzak-gt-4')['image'])->toBe($name)
            ->and(images()->find($name)['bytes'])->toBe($bytes);
    });

    it('shows the picture that the draft names, and takes it off when asked', function () {
        signedInAdmin();
        $name = images()->store(aPictureOf('png'));
        $record = ContentDefinition::query()->where('kind', 'part')->where('slug', 'street-tires-t2')->firstOrFail();
        $record->forceFill(['draft' => json_encode([...json_decode((string) repositoryContent()->raw('parts/street-tires-t2'), true), 'image' => $name])])->save();

        $page = Livewire::test(EditPart::class, ['record' => $record->getRouteKey()])->assertSeeHtml("/api/v1/media/{$name}");

        $page->fillForm(['image_remove' => true])->call('save')->assertHasNoFormErrors();

        // The draft without the picture is the document that was published, so there is no draft any more.
        expect($record->fresh()->draft)->toBeNull();
    });

    it('says why a file is not taken, and keeps no draft', function () {
        signedInAdmin();
        $record = ContentDefinition::query()->where('kind', 'vehicle')->where('slug', 'rdzak-gt-4')->firstOrFail();

        Livewire::test(EditVehicle::class, ['record' => $record->getRouteKey()])
            ->fillForm(['image_upload' => fileOf('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"/>', 'car.png')])
            ->call('save')
            ->assertNotified(__('admin.definitions.image_refused.kind'));

        expect($record->fresh()->draft)->toBeNull()->and(ContentImage::query()->count())->toBe(0);
    });

    it('is a part of a new part too', function () {
        signedInAdmin();
        $document = json_decode((string) repositoryContent()->raw('parts/street-tires-t2'), true);
        $state = [...DefinitionForm::toState('part', $document), 'slug' => 'street-tires-t2b', 'image_upload' => fileOf(aPictureOf('webp'), 'tire.webp')];

        Livewire::test(CreatePart::class)->fillForm($state)->call('create')->assertHasNoFormErrors();

        expect(pictureDraft('part', 'street-tires-t2b')['image'])->toEndWith('.webp');
    });
});
