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
        $this->validator = new Validator;
        $this->validator->resolver()?->registerPrefix(self::SCHEMA_BASE, rtrim($schemaPath, '/\\'));
    }

    public static function fromConfig(): self
    {
        return new self((string) config('ride.content_path').'/schema');
    }

    public function validate(string $packPath): PackValidationResult
    {
        $result = new PackValidationResult;

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

    /**
     * Validates all the content of a source, not only the cars and the parts: the manifest, every car and part, the rules, the steps of the tutorial and the
     * anchors of the economy against their schemas, and what the schemas cannot say: the names of the documents are their slugs, and the things that the
     * rules point to (the stock tires, the parts of the shop, the cars with a wreck price, the regions of the tracks and the roads) are there. The tables that
     * commands make only have to be JSON.
     */
    public function validateSource(ContentSource $source): PackValidationResult
    {
        $result = new PackValidationResult;

        $this->validateDocument($source, 'pack', 'pack.json', 'pack.schema.json', $result);

        foreach (self::KINDS as $folder => $schemaFile) {
            foreach ($source->slugs($folder) as $slug) {
                $relative = "{$folder}/{$slug}.json";
                $data = $this->validateDocument($source, "{$folder}/{$slug}", $relative, $schemaFile, $result);

                if ($data === null) {
                    continue;
                }

                $result->count($folder);

                if (($data['slug'] ?? null) !== $slug) {
                    $result->addError($relative, '/slug', "The document must be named after its slug: {$slug}.");
                }

                $this->warnAboutMissingTranslations($relative, $data, $result);

                if ($folder === 'vehicles') {
                    $this->checkVehicleRules($relative, $data, $result);
                }
            }
        }

        foreach (['starter' => 'rules.schema.json', 'tutorial' => 'tutorial.schema.json', 'economy' => 'economy.schema.json'] as $name => $schemaFile) {
            if (! $source->has("rules/{$name}")) {
                $name === 'economy'
                    ? $result->addWarning("rules/{$name}.json", '', 'The anchors of the economy are missing: the simulators cannot be run on this content.')
                    : $result->addError("rules/{$name}.json", '', 'The document is missing.');

                continue;
            }

            $this->validateDocument($source, "rules/{$name}", "rules/{$name}.json", $schemaFile, $result);
        }

        foreach ($source->slugs('tables') as $name) {
            try {
                $source->document("tables/{$name}");
            } catch (ContentException $problem) {
                $result->addError("tables/{$name}.json", '', $problem->getMessage());
            }
        }

        if ($result->isValid()) {
            $this->checkReferences($source, $result);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function validateDocument(ContentSource $source, string $key, string $relative, string $schemaFile, PackValidationResult $result): ?array
    {
        $json = $source->raw($key);

        if ($json === null) {
            $result->addError($relative, '', 'The document is missing.');

            return null;
        }

        return $this->validateJson($json, $relative, $schemaFile, $result);
    }

    /** What the rules point to has to exist in the pack. */
    private function checkReferences(ContentSource $source, PackValidationResult $result): void
    {
        $rules = $source->document('rules/starter');
        $parts = $source->slugs('parts');
        $vehicles = $source->slugs('vehicles');
        $regions = array_keys($rules['regions'] ?? []);

        if (! in_array($rules['stock_tires'], $parts, true)) {
            $result->addError('rules/starter.json', '/stock_tires', "There is no part {$rules['stock_tires']}.");
        }

        foreach ($rules['shop']['parts'] ?? [] as $index => $slug) {
            if (! in_array($slug, $parts, true)) {
                $result->addError('rules/starter.json', "/shop/parts/{$index}", "The shop sells {$slug}, which is not a part of the pack.");
            }
        }

        foreach (['wreck_price_cents', 'wreck_mileage_km'] as $field) {
            foreach (array_keys($rules['garage'][$field] ?? []) as $slug) {
                if (! in_array($slug, $vehicles, true)) {
                    $result->addError('rules/starter.json', "/garage/{$field}/{$slug}", "There is no car {$slug} in the pack.");
                }
            }
        }

        foreach ($rules['tracks'] ?? [] as $slug => $track) {
            if (! in_array($track['region'] ?? null, $regions, true)) {
                $result->addError('rules/starter.json', "/tracks/{$slug}/region", "The region {$track['region']} does not exist.");
            }
        }

        foreach ($rules['roads'] ?? [] as $index => $road) {
            foreach (['from', 'to'] as $end) {
                if (! in_array($road[$end] ?? null, $regions, true)) {
                    $result->addError('rules/starter.json', "/roads/{$index}/{$end}", "The road leads to a region that does not exist: {$road[$end]}.");
                }
            }
        }
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
        return $this->validateJson((string) file_get_contents($path), $relative, $schemaFile, $result);
    }

    /**
     * Validates the text of one document against a schema. Returns the decoded data when it is JSON that passes the schema, otherwise null.
     *
     * @return array<string, mixed>|null
     */
    private function validateJson(string $json, string $relative, string $schemaFile, PackValidationResult $result): ?array
    {
        try {
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $array = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $result->addError($relative, '', 'Invalid JSON: '.$exception->getMessage());

            return null;
        }

        $outcome = $this->validator->validate($object, self::SCHEMA_BASE.$schemaFile);

        if (! $outcome->isValid()) {
            foreach ((new ErrorFormatter)->formatKeyed($outcome->error()) as $pointer => $messages) {
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
        foreach (['name', 'description'] as $field) {
            $text = $data[$field] ?? null;

            if (! is_array($text)) {
                continue;
            }

            foreach (Locale::supported() as $locale) {
                if (! isset($text[$locale])) {
                    $result->addWarning($relative, "/{$field}/{$locale}", 'Missing translation, English is used instead.');
                }
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
