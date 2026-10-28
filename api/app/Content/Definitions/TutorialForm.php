<?php

declare(strict_types=1);

namespace App\Content\Definitions;

use stdClass;

/**
 * What the page of the tutorial holds and what it does to the document: the version, the arrangements of the first run, and the steps, each with its messages in
 * the languages of the game and its reward as fields (the cash in dollars). A step that gives nothing has the empty object that the file has, not an empty list.
 */
final class TutorialForm
{
    /**
     * @param  array<string, mixed>  $tutorial
     * @return array<string, mixed>
     */
    public static function toState(array $tutorial): array
    {
        $steps = [];

        foreach ($tutorial['steps'] ?? [] as $step) {
            $reward = (array) ($step['reward'] ?? []);

            $steps[] = [
                'key' => $step['key'] ?? null,
                'phase' => $step['phase'] ?? null,
                'trigger' => self::conditionsText($step['trigger'] ?? null),
                'completion' => self::conditionsText($step['completion'] ?? null),
                'target' => $step['target'] ?? null,
                'optional' => (bool) ($step['optional'] ?? false),
                'arranges' => $step['arranges'] ?? [],
                'gift_part' => $step['gift']['part'] ?? null,
                'gift_rarity' => $step['gift']['rarity'] ?? null,
                'gift_count' => $step['gift']['count'] ?? null,
                'sender' => $step['sender'] ?? null,
                'sms' => array_map(fn (array $text): array => DefinitionForm::texts($text), $step['sms'] ?? []),
                'reply' => DefinitionForm::texts($step['reply'] ?? []),
                'decline' => DefinitionForm::texts($step['decline'] ?? []),
                'reward_cash' => isset($reward['cash_cents']) ? $reward['cash_cents'] / 100 : null,
                'reward_car_xp' => $reward['car_xp'] ?? null,
                'reward_xp' => $reward['xp'] ?? null,
                'reward_part' => $reward['part'] ?? null,
                'reward_garage' => $reward['garage'] ?? null,
            ];
        }

        return [
            'version' => $tutorial['version'] ?? null,
            'first_run_fuel_ml' => $tutorial['script']['first_run_fuel_ml'] ?? null,
            'breakdown_lap' => $tutorial['script']['breakdown_lap'] ?? null,
            'first_mount_ms' => $tutorial['script']['first_mount_ms'] ?? null,
            'covers_up_to' => isset($tutorial['script']['covers_up_to_cents']) ? $tutorial['script']['covers_up_to_cents'] / 100 : null,
            'listing_vehicle' => $tutorial['script']['exchange_listing']['vehicle'] ?? null,
            'listing_age_years' => $tutorial['script']['exchange_listing']['age_years'] ?? null,
            'listing_mileage_km' => $tutorial['script']['exchange_listing']['mileage_km'] ?? null,
            'listing_condition' => isset($tutorial['script']['exchange_listing']['condition_ppm']) ? $tutorial['script']['exchange_listing']['condition_ppm'] / 10_000 : null,
            'listing_price' => isset($tutorial['script']['exchange_listing']['price_cents']) ? $tutorial['script']['exchange_listing']['price_cents'] / 100 : null,
            'steps' => $steps,
        ];
    }

    /** A trigger or a completion as a box holds it: the facts, one after another, with a plus between them. */
    private static function conditionsText(mixed $condition): ?string
    {
        return $condition === null ? null : implode(' + ', (array) $condition);
    }

    /** The fact, or the facts that must all hold, that a box holds: one fact is a text, more are a list. */
    private static function conditions(mixed $text): string|array|null
    {
        $facts = array_values(array_filter(array_map('trim', explode('+', (string) DefinitionForm::text($text))), fn (string $fact): bool => $fact !== ''));

        return match (count($facts)) {
            0 => null,
            1 => $facts[0],
            default => $facts,
        };
    }

    /**
     * The tutorial with the version, the script and the steps of a form.
     *
     * @param  array<string, mixed>  $tutorial
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function apply(array $tutorial, array $state): array
    {
        $steps = [];

        foreach ((array) ($state['steps'] ?? []) as $line) {
            $step = [
                'key' => DefinitionForm::text($line['key'] ?? null),
                'phase' => DefinitionForm::number($line['phase'] ?? null),
                'trigger' => self::conditions($line['trigger'] ?? null),
                'completion' => self::conditions($line['completion'] ?? null),
                'target' => DefinitionForm::text($line['target'] ?? null),
                'sender' => DefinitionForm::text($line['sender'] ?? null),
                'sms' => array_values(array_filter(array_map(fn (mixed $text): array => DefinitionForm::i18n((array) $text), (array) ($line['sms'] ?? [])), fn (array $text): bool => $text !== [])),
            ];

            $reply = DefinitionForm::i18n((array) ($line['reply'] ?? []));

            if ($reply !== []) {
                $step['reply'] = $reply;
            }

            $decline = DefinitionForm::i18n((array) ($line['decline'] ?? []));

            if ($decline !== []) {
                $step['decline'] = $decline;
            }

            if (! empty($line['optional'])) {
                $step['optional'] = true;
            }

            if (! empty($line['arranges'])) {
                $step['arranges'] = array_values((array) $line['arranges']);
            }

            $giftPart = DefinitionForm::text($line['gift_part'] ?? null);

            if ($giftPart !== null) {
                $step['gift'] = array_filter(['part' => $giftPart, 'rarity' => DefinitionForm::text($line['gift_rarity'] ?? null), 'count' => DefinitionForm::number($line['gift_count'] ?? null)], fn (mixed $value): bool => $value !== null);
            }

            $reward = [];

            foreach ([['cash_cents', DefinitionForm::cents($line['reward_cash'] ?? null)], ['car_xp', DefinitionForm::number($line['reward_car_xp'] ?? null)], ['xp', DefinitionForm::number($line['reward_xp'] ?? null)], ['part', DefinitionForm::text($line['reward_part'] ?? null)], ['garage', DefinitionForm::text($line['reward_garage'] ?? null)]] as [$field, $value]) {
                if ($value !== null) {
                    $reward[$field] = $value;
                }
            }

            $step['reward'] = $reward === [] ? new stdClass : $reward;
            $steps[] = $step;
        }

        $tutorial['version'] = DefinitionForm::text($state['version'] ?? null);
        $tutorial['steps'] = $steps;
        $tutorial['script'] = [
            'first_run_fuel_ml' => DefinitionForm::number($state['first_run_fuel_ml'] ?? null),
            'breakdown_lap' => DefinitionForm::number($state['breakdown_lap'] ?? null),
            'first_mount_ms' => DefinitionForm::number($state['first_mount_ms'] ?? null),
            'covers_up_to_cents' => DefinitionForm::cents($state['covers_up_to'] ?? null),
        ];

        $vehicle = DefinitionForm::text($state['listing_vehicle'] ?? null);

        if ($vehicle !== null) {
            $condition = DefinitionForm::number($state['listing_condition'] ?? null);

            $tutorial['script']['exchange_listing'] = [
                'vehicle' => $vehicle,
                'age_years' => DefinitionForm::number($state['listing_age_years'] ?? null),
                'mileage_km' => DefinitionForm::number($state['listing_mileage_km'] ?? null),
                'condition_ppm' => is_int($condition) || is_float($condition) ? (int) round($condition * 10_000) : $condition,
                'price_cents' => DefinitionForm::cents($state['listing_price'] ?? null),
            ];
        }

        return $tutorial;
    }
}
