<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Outcome of validating one content pack. Errors block the deployment of a
 * pack, warnings only point at things worth fixing (for example a missing
 * translation that falls back to English).
 */
final class PackValidationResult
{
    /** @var list<array{file: string, pointer: string, message: string}> */
    private array $errors = [];

    /** @var list<array{file: string, pointer: string, message: string}> */
    private array $warnings = [];

    /** @var array<string, int> definitions per kind */
    private array $counts = [];

    public function addError(string $file, string $pointer, string $message): void
    {
        $this->errors[] = ['file' => $file, 'pointer' => $pointer, 'message' => $message];
    }

    public function addWarning(string $file, string $pointer, string $message): void
    {
        $this->warnings[] = ['file' => $file, 'pointer' => $pointer, 'message' => $message];
    }

    /** Takes in what another result found, errors and warnings alike. */
    public function merge(self $other): void
    {
        $this->errors = [...$this->errors, ...$other->errors];
        $this->warnings = [...$this->warnings, ...$other->warnings];
    }

    public function count(string $kind): void
    {
        $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<array{file: string, pointer: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<array{file: string, pointer: string, message: string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }
}
