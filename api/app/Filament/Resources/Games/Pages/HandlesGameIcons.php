<?php

declare(strict_types=1);

namespace App\Filament\Resources\Games\Pages;

use App\Content\ImageRefused;
use App\Services\ContentImages;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * What the pages that make and change a game do with its icons: a file that was chosen goes to the store of images and its name is the icon of the game, an icon that
 * is taken off is none, and the boxes of the form that are not columns are gone. An icon is a square picture of at least 192 px (the game takes PNG, WebP and JPEG, up
 * to a megabyte, as for the pictures of the cars); what is not is said and nothing is kept.
 */
trait HandlesGameIcons
{
    /** The smallest side of an icon that a home screen can use. */
    private const ICON_MIN_SIDE = 192;

    /**
     * @param  array<string, mixed>  $data  the state of the form
     * @return array<string, mixed>
     */
    protected function withIcons(array $data): array
    {
        foreach (['icon', 'icon_maskable'] as $field) {
            $upload = $data["{$field}_upload"] ?? null;
            $upload = is_array($upload) ? Arr::first($upload) : $upload;

            if (! empty($data["{$field}_remove"])) {
                $data[$field] = null;
            }

            unset($data["{$field}_upload"], $data["{$field}_remove"]);

            $bytes = match (true) {
                $upload instanceof TemporaryUploadedFile => $upload->get(),
                $upload instanceof UploadedFile => (string) file_get_contents($upload->getRealPath()),
                default => null,
            };

            if ($bytes !== null) {
                $data[$field] = $this->keepIcon((string) $bytes);
            }
        }

        return $data;
    }

    /** Keeps the picture of an icon and gives its name; a picture that cannot be an icon is said, and nothing is kept. */
    private function keepIcon(string $bytes): string
    {
        $images = app(ContentImages::class);

        try {
            $name = $images->store($bytes);
            $size = $images->describe($name) ?? ['width' => 0, 'height' => 0];

            if ($size['width'] !== $size['height'] || $size['width'] < self::ICON_MIN_SIDE) {
                throw new ImageRefused('square', $size);
            }

            return $name;
        } catch (ImageRefused $refusal) {
            $key = $refusal->reason === 'square' ? 'admin.games.icon_square' : "admin.definitions.image_refused.{$refusal->reason}";

            Notification::make()->danger()->title(__($key, $refusal->params))->persistent()->send();

            $this->halt();
        }
    }
}
