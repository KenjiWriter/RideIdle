<?php

declare(strict_types=1);

namespace App\Content;

use App\Support\Locale;
use InvalidArgumentException;
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

    /** The documents of the rules that a person edits, by name => schema file. */
    public const RULES = [
        'starter' => 'rules.schema.json',
        'tutorial' => 'tutorial.schema.json',
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

            $data = $this->validateDocument($source, "rules/{$name}", "rules/{$name}.json", $schemaFile, $result);

            if ($data !== null) {
                $this->warnAboutMissingRulesTranslations("rules/{$name}.json", $name, $data, $result);
            }
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

    /**
     * Validates one car or part on its own, as the panel does with a draft: its schema, that its slug is the one it is meant to have, the dealer level of a car,
     * and a warning for every language that is missing. What the rest of the content has to say about it (a shop that sells it, a tire that the rules call
     * the stock ones) is for a pack to check.
     *
     * @param  string  $folder  vehicles or parts
     * @param  string|null  $slug  what the slug of the document has to be, when it is already known
     */
    public function validateDefinition(string $folder, string $json, ?string $slug = null): PackValidationResult
    {
        $result = new PackValidationResult;
        $relative = rtrim($folder, 's').($slug === null ? '' : " {$slug}");
        $schema = $folder === 'rules' ? (self::RULES[$slug ?? ''] ?? null) : (self::KINDS[$folder] ?? null);
        $data = $this->validateJson($json, $relative, $schema ?? throw new InvalidArgumentException("There are no definitions in {$folder}."), $result);

        if ($data === null) {
            return $result;
        }

        $result->count($folder);

        if ($folder === 'rules') {
            $this->warnAboutMissingRulesTranslations($relative, (string) $slug, $data, $result);

            return $result;
        }

        if ($slug !== null && ($data['slug'] ?? null) !== $slug) {
            $result->addError($relative, '/slug', "The slug of a definition is never changed: it is {$slug}.");
        }

        $this->warnAboutMissingTranslations($relative, $data, $result);

        if ($folder === 'vehicles') {
            $this->checkVehicleRules($relative, $data, $result);
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

    /**
     * What the rules and the tutorial point to, and what the world they describe needs to work, checked on content that is whole enough to be asked: a part
     * that the shop sells is there, every city has a track and is reached by a road, and so on. A source that has no rules gives nothing to check.
     */
    public function validateReferences(ContentSource $source): PackValidationResult
    {
        $result = new PackValidationResult;

        if ($source->has('rules/starter')) {
            $this->checkReferences($source, $result);
        }

        return $result;
    }

    /** What the rules point to has to exist in the pack, and the world has to work. */
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

        $this->checkWorld($rules, $result);
        $this->checkTutorial($source, $parts, $regions, $result);
        $this->checkWithdrawn($source, $parts, $vehicles, $result);
    }

    /**
     * What a pack withdraws is in the pack, and a new driver still has a car to start with.
     *
     * @param  list<string>  $parts
     * @param  list<string>  $vehicles
     */
    private function checkWithdrawn(ContentSource $source, array $parts, array $vehicles, PackValidationResult $result): void
    {
        $withdrawn = $source->withdrawn();

        foreach ($withdrawn as $index => $slug) {
            if (! in_array($slug, [...$parts, ...$vehicles], true)) {
                $result->addError('pack.json', "/withdrawn/{$index}", "The pack withdraws {$slug}, which is neither a car nor a part of it.");
            }
        }

        if ($withdrawn === []) {
            return;
        }

        $starting = array_filter($source->documents('vehicles'), static fn (array $vehicle, string $slug): bool => in_array('start', $vehicle['sources'] ?? [], true) && ! in_array($slug, $withdrawn, true), ARRAY_FILTER_USE_BOTH);

        if ($starting === []) {
            $result->addError('pack.json', '/withdrawn', 'Every car that a new driver can start with is withdrawn.');
        }
    }

    /**
     * What the world of the rules needs in order to be played: the demands of a track add up to 100 and have a reference each, the chances of the weather of a
     * city add up to 1 000 000 and name weather that the rules know, every city has a track and a road to it, no road leads from a city to itself, and the first
     * track, where a new driver starts, is open from the first level.
     *
     * @param  array<string, mixed>  $rules
     */
    private function checkWorld(array $rules, PackValidationResult $result): void
    {
        $file = 'rules/starter.json';

        foreach ($rules['tracks'] ?? [] as $slug => $track) {
            $demand = $track['demand'] ?? [];

            if (array_sum($demand) !== 100) {
                $result->addError($file, "/tracks/{$slug}/demand", 'The demands add up to '.array_sum($demand).', not 100.');
            }

            foreach (array_diff(array_keys($demand), array_keys($track['reference'] ?? [])) as $stat) {
                $result->addError($file, "/tracks/{$slug}/reference", "The track asks for {$stat} and has no reference for it.");
            }
        }

        foreach ($rules['regions'] ?? [] as $slug => $region) {
            $chances = $region['weather_ppm'] ?? [];

            if (array_sum($chances) !== 1_000_000) {
                $result->addError($file, "/regions/{$slug}/weather_ppm", 'The chances add up to '.array_sum($chances).', not 1 000 000.');
            }

            foreach (array_diff(array_keys($chances), array_keys($rules['weather'] ?? [])) as $weather) {
                $result->addError($file, "/regions/{$slug}/weather_ppm/{$weather}", "The rules have no weather {$weather}.");
            }

            if (array_filter($rules['tracks'] ?? [], fn (array $track): bool => ($track['region'] ?? null) === $slug) === []) {
                $result->addError($file, "/regions/{$slug}", 'There is no track in this region, so there is nothing to drive there.');
            }
        }

        $reached = array_slice(array_keys($rules['regions'] ?? []), 0, 1);

        for ($index = 0; $index < count($reached); $index++) {
            foreach ($rules['roads'] ?? [] as $road) {
                foreach ([[$road['from'] ?? null, $road['to'] ?? null], [$road['to'] ?? null, $road['from'] ?? null]] as [$near, $far]) {
                    if ($near === $reached[$index] && is_string($far) && ! in_array($far, $reached, true)) {
                        $reached[] = $far;
                    }
                }
            }
        }

        foreach (array_diff(array_keys($rules['regions'] ?? []), $reached) as $slug) {
            $result->addError($file, "/regions/{$slug}", 'No road leads there from the first city.');
        }

        foreach ($rules['roads'] ?? [] as $index => $road) {
            if (($road['from'] ?? null) === ($road['to'] ?? 0)) {
                $result->addError($file, "/roads/{$index}", 'A road leads from a city to itself.');
            }
        }

        $first = array_key_first($rules['tracks'] ?? []);

        if ($first !== null && ($rules['tracks'][$first]['min_player_level'] ?? 1) !== 1) {
            $result->addError($file, "/tracks/{$first}/min_player_level", 'The first track is where a new driver starts: it is open from level 1.');
        }
    }

    /**
     * The steps of the tutorial have keys of their own, and what they give away is in the pack.
     *
     * @param  list<string>  $parts
     * @param  list<string>  $regions
     */
    private function checkTutorial(ContentSource $source, array $parts, array $regions, PackValidationResult $result): void
    {
        if (! $source->has('rules/tutorial')) {
            return;
        }

        $file = 'rules/tutorial.json';
        $keys = [];

        foreach ($source->document('rules/tutorial')['steps'] ?? [] as $index => $step) {
            $key = (string) ($step['key'] ?? '');

            if (isset($keys[$key])) {
                $result->addError($file, "/steps/{$index}/key", "The key {$key} is used by two steps.");
            }

            $keys[$key] = true;

            if (isset($step['reward']['part']) && ! in_array($step['reward']['part'], $parts, true)) {
                $result->addError($file, "/steps/{$index}/reward/part", "The step gives away {$step['reward']['part']}, which is not a part of the pack.");
            }

            if (isset($step['reward']['garage']) && ! in_array($step['reward']['garage'], $regions, true)) {
                $result->addError($file, "/steps/{$index}/reward/garage", "The step gives a garage in {$step['reward']['garage']}, which is not a city.");
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

    /**
     * The texts of the world (the names of the cities and of the tracks) and of the tutorial (the messages and the button that answers them) that a language of the
     * game has none of.
     *
     * @param  array<string, mixed>  $data
     */
    private function warnAboutMissingRulesTranslations(string $relative, string $name, array $data, PackValidationResult $result): void
    {
        $texts = [];

        if ($name === 'starter') {
            foreach (['regions', 'tracks'] as $section) {
                foreach ($data[$section] ?? [] as $slug => $item) {
                    $texts["/{$section}/{$slug}/name"] = $item['name'] ?? null;
                }
            }
        }

        if ($name === 'tutorial') {
            foreach ($data['steps'] ?? [] as $index => $step) {
                foreach ($step['sms'] ?? [] as $position => $sms) {
                    $texts["/steps/{$index}/sms/{$position}"] = $sms;
                }

                $texts["/steps/{$index}/reply"] = $step['reply'] ?? null;
            }
        }

        foreach ($texts as $pointer => $text) {
            if (! is_array($text)) {
                continue;
            }

            foreach (Locale::supported() as $locale) {
                if (! isset($text[$locale])) {
                    $result->addWarning($relative, "{$pointer}/{$locale}", 'Missing translation, English is used instead.');
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
