<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DomainJob;
use App\Services\DomainJobs;
use Illuminate\Console\Command;

/** What the worker on the server says of a request it claimed: how it ended, the end of its log, and the end date of the certificate when there is one. */
final class ReportDomainJob extends Command
{
    /** How much of a log file is read: the end of it is what the panel keeps, so nothing is gained by reading more. */
    private const READ_BYTES = 200_000;

    protected $signature = 'domains:report
        {id : The number of the job, as domains:claim printed it}
        {--exit-code= : How the work ended: 0 is done, anything else failed}
        {--log-file= : A file with what the worker printed}
        {--expires= : The end date of the certificate, YYYY-MM-DD}';

    protected $description = 'Report how a request about the domain of a game ended (done with 0, failed otherwise)';

    public function handle(DomainJobs $jobs): int
    {
        $id = (string) $this->argument('id');
        $code = $this->option('exit-code');

        if (preg_match('/^\d{1,18}$/', $id) !== 1 || ! is_string($code) || preg_match('/^\d{1,5}$/', $code) !== 1) {
            $this->error('The number of the job and --exit-code are whole numbers.');

            return self::FAILURE;
        }

        $job = DomainJob::query()->find((int) $id);

        if ($job === null) {
            $this->error("There is no job {$id}.");

            return self::FAILURE;
        }

        $expires = $this->option('expires');

        if (is_string($expires) && $expires !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
            $this->error('--expires is a date as YYYY-MM-DD.');

            return self::FAILURE;
        }

        if (! $jobs->report($job, (int) $code, $this->log(), is_string($expires) && $expires !== '' ? $expires : null)) {
            $this->warn("Job {$id} is not running (it was reported already, or it was given up on): left as it is.");
        }

        return self::SUCCESS;
    }

    private function log(): ?string
    {
        $file = $this->option('log-file');

        if (! is_string($file) || $file === '' || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $size = (int) filesize($file);
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        if ($size > self::READ_BYTES) {
            fseek($handle, -self::READ_BYTES, SEEK_END);
        }

        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }
}
