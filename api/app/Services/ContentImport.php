<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentPack;

/** What an import came to. */
final readonly class ContentImport
{
    /**
     * @param  bool  $created  false when the pack was already there with the same content
     * @param  int  $newVersions  how many definitions got a new version (the rest were the same as the latest one)
     * @param  list<array{file: string, pointer: string, message: string}>  $warnings
     */
    public function __construct(public ContentPack $pack, public bool $created, public int $newVersions, public array $warnings = []) {}
}
