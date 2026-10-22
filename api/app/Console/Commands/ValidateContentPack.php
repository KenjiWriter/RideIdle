<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\PackValidator;
use Illuminate\Console\Command;

final class ValidateContentPack extends Command
{
    protected $signature = 'content:validate
        {path? : Pack directory (default: packs/core inside the content folder)}
        {--strict : Treat warnings (such as missing translations) as errors}';

    protected $description = 'Validate a content pack against the content schema';

    public function handle(): int
    {
        $path = $this->argument('path') ?: config('ride.content_path').'/packs/core';

        $result = PackValidator::fromConfig()->validate((string) $path);

        foreach ($result->errors() as $issue) {
            $this->error("{$issue['file']} {$issue['pointer']}: {$issue['message']}");
        }

        foreach ($result->warnings() as $issue) {
            $this->warn("{$issue['file']} {$issue['pointer']}: {$issue['message']}");
        }

        $counts = collect($result->counts())->map(fn (int $count, string $kind) => "{$count} {$kind}")->implode(', ');

        $failed = ! $result->isValid() || ($this->option('strict') && $result->warnings() !== []);

        if ($failed) {
            $this->error(sprintf('Pack is not valid: %d error(s), %d warning(s).', count($result->errors()), count($result->warnings())));

            return self::FAILURE;
        }

        $this->info('Pack is valid'.($counts !== '' ? " ({$counts})" : '').sprintf(', %d warning(s).', count($result->warnings())));

        return self::SUCCESS;
    }
}
