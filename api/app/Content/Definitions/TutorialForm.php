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
                'trigger' => $step['trigger'] ?? null,
                'completion' => $step['completion'] ?? null,
                'target' => $step['target'] ?? null,
                'sender' => $step['sender'] ?? null,
                'sms' => array_map(fn (array $text): array => DefinitionForm::texts($text), $step['sms'] ?? []),
                'reply' => DefinitionForm::texts($step['reply'] ?? []),
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
            'steps' => $steps,
        ];
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
                'trigger' => DefinitionForm::text($line['trigger'] ?? null),
                'completion' => DefinitionForm::text($line['completion'] ?? null),
                'target' => DefinitionForm::text($line['target'] ?? null),
                'sender' => DefinitionForm::text($line['sender'] ?? null),
                'sms' => array_values(array_filter(array_map(fn (mixed $text): array => DefinitionForm::i18n((array) $text), (array) ($line['sms'] ?? [])), fn (array $text): bool => $text !== [])),
            ];

            $reply = DefinitionForm::i18n((array) ($line['reply'] ?? []));

            if ($reply !== []) {
                $step['reply'] = $reply;
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
        ];

        return $tutorial;
    }
}
