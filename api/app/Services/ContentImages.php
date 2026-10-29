<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\Branding\Branding;
use App\Content\ContentException;
use App\Content\ContentSource;
use App\Content\ImageRefused;
use App\Models\ContentImage;
use Illuminate\Support\Facades\Schema;

/**
 * The pictures of cars and parts. A picture is kept under the SHA-256 of its bytes, and a document names it as `<checksum>.<kind>`: the same file is one row, a
 * name always means the same bytes, and a pack that is made live again brings back its pictures with it. The game takes PNG, WebP and JPEG (no SVG, which can
 * carry a script), up to a megabyte, and not smaller or larger than a picture of a card should be. The file is looked at by its header and never decoded, so a
 * picture costs the server what its bytes cost.
 */
final class ContentImages
{
    public const MAX_BYTES = 1_048_576;

    public const MIN_SIDE = 32;

    public const MAX_SIDE = 2048;

    /** How a name looks, and the two parts of it. */
    public const NAME = '/^([0-9a-f]{64})\.(png|webp|jpg)$/';

    /** The same as a constraint of the route. */
    public const ROUTE = '[0-9a-f]{64}\.(?:png|webp|jpg)';

    /** The kinds of file by what the header says they are. */
    private const KINDS = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];

    private ?bool $available = null;

    /** Where a picture is served from, as a path of the API (the same on every host that serves it). */
    public static function url(string $name): string
    {
        return route('media', ['name' => $name], absolute: false);
    }

    /**
     * The address of the picture that a car or a part names, or null when it names none.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function urlOf(array $definition): ?string
    {
        $name = $definition['image'] ?? null;

        return is_string($name) && preg_match(self::NAME, $name) === 1 ? self::url($name) : null;
    }

    /**
     * Keeps a picture and gives back its name. A file that is already kept is not kept twice.
     *
     * @throws ImageRefused empty, too_big, kind or dimensions
     */
    public function store(string $bytes): string
    {
        $size = strlen($bytes);

        if ($size === 0) {
            throw new ImageRefused('empty');
        }

        if ($size > self::MAX_BYTES) {
            throw new ImageRefused('too_big', ['max' => intdiv(self::MAX_BYTES, 1024)]);
        }

        $header = @getimagesizefromstring($bytes);

        if ($header === false || ! isset(self::KINDS[$header['mime']])) {
            throw new ImageRefused('kind');
        }

        [$width, $height] = $header;

        if (min($width, $height) < self::MIN_SIDE || max($width, $height) > self::MAX_SIDE) {
            throw new ImageRefused('dimensions', ['min' => self::MIN_SIDE, 'max' => self::MAX_SIDE, 'width' => $width, 'height' => $height]);
        }

        $sha = hash('sha256', $bytes);
        $kind = self::KINDS[$header['mime']];

        ContentImage::query()->insertOrIgnore([
            'sha256' => $sha,
            'kind' => $kind,
            'mime' => $header['mime'],
            'bytes' => $size,
            'width' => $width,
            'height' => $height,
            'data' => base64_encode($bytes),
            'created_at' => now(),
        ]);

        return "{$sha}.{$kind}";
    }

    /**
     * Keeps the pictures that came in a bundle. Each is judged as a file that is uploaded is, and has to be the one its name says.
     *
     * @param  array<string, string>  $images  the bytes by name
     *
     * @throws ImageRefused when a file is not taken as a picture
     * @throws ContentException when the bytes are not the ones the name is the checksum of
     */
    public function keep(array $images): void
    {
        foreach ($images as $name => $bytes) {
            if (hash('sha256', $bytes) !== substr($name, 0, 64) || $this->store($bytes) !== $name) {
                throw new ContentException("The picture {$name} of the bundle is not the file its name is the checksum of: it was changed after the bundle was made.");
            }
        }
    }

    /** @return array{mime: string, bytes: string, sha256: string}|null the picture of a name, or null when it is not kept */
    public function find(string $name): ?array
    {
        if (preg_match(self::NAME, $name, $found) !== 1) {
            return null;
        }

        $image = ContentImage::query()->where('sha256', $found[1])->where('kind', $found[2])->first(['mime', 'data', 'sha256']);

        return $image === null ? null : ['mime' => $image->mime, 'bytes' => (string) base64_decode($image->data, true), 'sha256' => $image->sha256];
    }

    /**
     * What a picture is: its kind of file and its size, or null when it is not kept (a manifest says the size of an icon).
     *
     * @return array{mime: string, width: int, height: int}|null
     */
    public function describe(string $name): ?array
    {
        if (preg_match(self::NAME, $name, $found) !== 1) {
            return null;
        }

        $image = ContentImage::query()->where('sha256', $found[1])->where('kind', $found[2])->first(['mime', 'width', 'height']);

        return $image === null ? null : ['mime' => $image->mime, 'width' => (int) $image->width, 'height' => (int) $image->height];
    }

    /**
     * Which of the names are not kept. When the table of the pictures is not there yet (the content is checked before the migrations of a deploy), none is judged.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function missing(array $names): array
    {
        $names = array_values(array_unique($names));

        if ($names === [] || ! $this->available()) {
            return [];
        }

        $kept = ContentImage::query()->whereIn('sha256', array_map(static fn (string $name): string => substr($name, 0, 64), $names))->get(['sha256', 'kind'])->map(fn (ContentImage $image): string => $image->name())->all();

        return array_values(array_diff($names, $kept));
    }

    /**
     * The pictures that the cars and the parts of a source name, and the ones of the look of the game (its front page, the preview of a link and its style sheet).
     *
     * @return list<string>
     */
    public function namesIn(ContentSource $source): array
    {
        $names = [];

        foreach (['vehicles', 'parts'] as $folder) {
            foreach ($source->documents($folder) as $document) {
                $name = $document['image'] ?? null;

                if (is_string($name) && preg_match(self::NAME, $name) === 1) {
                    $names[$name] = $name;
                }
            }
        }

        if ($source->has('rules/branding')) {
            foreach (Branding::pictures($source->document('rules/branding')) as $name) {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    /**
     * The bytes of the pictures that are kept, by name (a name that is not kept is left out).
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function bytesOf(array $names): array
    {
        $bytes = [];

        foreach ($names as $name) {
            $image = $this->find($name);

            if ($image !== null) {
                $bytes[$name] = $image['bytes'];
            }
        }

        return $bytes;
    }

    private function available(): bool
    {
        return $this->available ??= Schema::hasTable('content_images');
    }
}
