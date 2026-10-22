<?php

declare(strict_types=1);

namespace App\Content;

use App\Support\Locale;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates a content pack directory against the schemas in content/schema.
 *
 * Layout of a pack:
 *   pack.json          manifest
 *   vehicles/<slug>.json
 *   parts/<slug>.json
 *
 * Beyond the schema it checks what a schema cannot: a file is named after the
 * slug inside it, and derived values agree with the rules of the game.
 */
final class PackValidator
{
    private const SCHEMA_BASE = 'https://rideidle.local/schema/';

    /** Folder name => schema file. */
    private const KINDS = [
        'vehicles' => 'vehicle.schema.json',
        'parts' => 'part.schema.json',
    ];

    private readonly Validator $validator;

    public function __construct(string $schemaPath)
    {
        $this->validator = new Validator();
        $this->validator->resolver()?->registerPrefix(self::SCHEMA_BASE, rtrim($schemaPath, '/\\'));
    }

    public static function fromConfig(): self
    {
        return new self((string) config('ride.content_path').'/schema');
    }

    public function validate(string $packPath): PackValidationResult
    {
        $result = new PackValidationResult();

        if (! is_dir($packPath)) {
            $result->addError($packPath, '', 'The pack directory does not exist.');

            return $result;
        }

        $this->validateManifest($packPath, $result);

        foreach (scandir($packPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_dir("{$packPath}/{$entry}")) {
                continue;
            }

            if (! isset(self::KINDS[$entry])) {
                $result->addError($entry, '', 'Unknown content folder. Known folders: '.implode(', ', array_keys(self::KINDS)).'.');
            }
        }

        foreach (self::KINDS as $folder => $schemaFile) {
            $this->validateFolder($packPath, $folder, $schemaFile, $result);
        }

        return $result;
    }

    private function validateManifest(string $packPath, PackValidationResult $result): void
    {
        $file = "{$packPath}/pack.json";

        if (! is_file($file)) {
            $result->addError('pack.json', '', 'The manifest pack.json is missing.');

            return;
        }

        $this->validateFile($file, 'pack.json', 'pack.schema.json', $result);
    }

    private function validateFolder(string $packPath, string $folder, string $schemaFile, PackValidationResult $result): void
    {
        $directory = "{$packPath}/{$folder}";

        if (! is_dir($directory)) {
            return;
        }

        $files = glob("{$directory}/*.json") ?: [];
        sort($files);

        foreach ($files as $path) {
            $relative = "{$folder}/".basename($path);
            $data = $this->validateFile($path, $relative, $schemaFile, $result);

            if ($data === null) {
                continue;
            }

            $result->count($folder);

            $slug = $data['slug'] ?? null;

            if (is_string($slug) && $slug !== basename($path, '.json')) {
                $result->addError($relative, '/slug', "The file must be named after its slug: {$slug}.json.");
            }

            $this->warnAboutMissingTranslations($relative, $data, $result);

            if ($folder === 'vehicles') {
                $this->checkVehicleRules($relative, $data, $result);
            }
        }
    }

    /**
     * Validates one file against a schema. Returns the decoded data when the
     * file is readable JSON that passes the schema, otherwise null.
     *
     * @return array<string, mixed>|null
     */
    private function validateFile(string $path, string $relative, string $schemaFile, PackValidationResult $result): ?array
    {
        $json = (string) file_get_contents($path);

        try {
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $array = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $result->addError($relative, '', 'Invalid JSON: '.$exception->getMessage());

            return null;
        }

        $outcome = $this->validator->validate($object, self::SCHEMA_BASE.$schemaFile);

        if (! $outcome->isValid()) {
            foreach ((new ErrorFormatter())->formatKeyed($outcome->error()) as $pointer => $messages) {
                foreach ($messages as $message) {
                    $result->addError($relative, (string) $pointer, $message);
                }
            }

            return null;
        }

        return is_array($array) ? $array : null;
    }

    /** @param array<string, mixed> $data */
    private function warnAboutMissingTranslations(string $relative, array $data, PackValidationResult $result): void
    {
        $name = $data['name'] ?? null;

        if (! is_array($name)) {
            return;
        }

        foreach (Locale::supported() as $locale) {
            if (! isset($name[$locale])) {
                $result->addWarning($relative, "/name/{$locale}", "Missing translation, English is used instead.");
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function checkVehicleRules(string $relative, array $data, PackValidationResult $result): void
    {
        // The dealer level follows the tier: max(1, 3 * tier - 3) (Katalog aut).
        $expected = max(1, 3 * (int) $data['tier'] - 3);

        if ((int) $data['min_player_level'] !== $expected) {
            $result->addError($relative, '/min_player_level', "For tier {$data['tier']} the level must be {$expected}.");
        }
    }
}
