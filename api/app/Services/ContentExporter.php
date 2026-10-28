<?php

declare(strict_types=1);

namespace App\Services;

use App\Content\ContentException;
use App\Content\ContentSource;

/**
 * Writes the content of a source as the `content/` tree: the format that is imported, validated and kept in the repository. The documents are written as they
 * are, and the manifest gets the number of the pack and the checksum of its content, so that a pack that travels to another machine can be told from one that was
 * changed on the way.
 */
final class ContentExporter
{
    /**
     * @return int how many files were written
     *
     * @throws ContentException when the folder is not empty and `$overwrite` is not given
     */
    public function write(ContentSource $source, string $directory, string $number, bool $overwrite = false): int
    {
        $directory = rtrim($directory, '/\\');

        if (is_dir($directory) && (glob("{$directory}/*") ?: []) !== [] && ! $overwrite) {
            throw new ContentException("The folder {$directory} is not empty. Give another one, or --force to write over what is in it.");
        }

        $manifest = json_decode((string) $source->raw('pack'), false, 512, JSON_THROW_ON_ERROR);
        $manifest->version = $number;
        $manifest->checksum = $source->checksum();

        $pack = "{$directory}/packs/{$manifest->name}";
        $files = [
            "{$pack}/pack.json" => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        ];

        foreach ($source->keys() as $key) {
            [$folder, $slug] = explode('/', $key, 2);
            $files[(in_array($folder, ['vehicles', 'parts'], true) ? "{$pack}/{$folder}" : "{$directory}/{$folder}")."/{$slug}.json"] = (string) $source->raw($key);
        }

        foreach ($files as $path => $text) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $text);
        }

        return count($files);
    }
}
