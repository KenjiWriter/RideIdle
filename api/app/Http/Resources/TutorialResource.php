<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Content\TutorialSteps;
use App\Services\TutorialState;
use App\Support\I18nText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where the driver is in the tutorial, with the messages of the step that waits in the language of the driver.
 *
 * @property-read TutorialState $resource
 */
final class TutorialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $state = $this->resource;
        $step = $state->current;

        return [
            'status' => $state->status,
            'total' => $state->total,
            'done' => $state->done,
            'current' => $step === null ? null : [
                'key' => $step['key'],
                'number' => $step['number'],
                'sender' => $step['sender'],
                'sms' => array_map(static fn (array $text): string => I18nText::from($text)->for(), $step['sms']),
                'reply' => I18nText::from($step['reply'])->for(),
                'optional' => (bool) ($step['optional'] ?? false),
                'decline' => isset($step['decline']) ? I18nText::from($step['decline'])->for() : null,
                'target' => $step['target'],
                'tire_kind' => self::tireKind($step),
                'acknowledged' => $step['acknowledged'],
            ],
            // What the steps completed by this call paid, so the client can say it and refresh the cash.
            'rewarded' => $state->rewarded,
            'goal' => $state->goal === null ? null : $this->goal($state->goal),
        ];
    }

    /**
     * The kind of tires that a step waits to see on the car (`tires_mounted:offroad` makes `offroad`), so that the workshop can point at those tires and not at
     * the first ones in the inventory; null for a step that names no kind.
     *
     * @param  array<string, mixed>  $step
     */
    private static function tireKind(array $step): ?string
    {
        foreach (TutorialSteps::conditions($step['completion'] ?? []) as $fact) {
            if (str_starts_with($fact, 'tires_mounted:')) {
                return substr($fact, strlen('tires_mounted:'));
            }
        }

        return null;
    }

    /**
     * The goal at every screen of the game: what to do, what the step waits for before it starts, where to go, and what it pays.
     *
     * @param  array<string, mixed>  $goal
     * @return array<string, mixed>
     */
    private function goal(array $goal): array
    {
        $reward = $goal['reward'];

        return [
            'key' => $goal['key'],
            'number' => $goal['number'],
            'phase' => $goal['phase'],
            'title' => I18nText::from($goal['goal'])->for(),
            'waiting' => $goal['waits'] && isset($goal['waiting']) ? I18nText::from($goal['waiting'])->for() : null,
            'target' => $goal['target'],
            'tire_kind' => self::tireKind($goal),
            'optional' => (bool) ($goal['optional'] ?? false),
            'reward' => [
                'cash_cents' => (int) ($reward['cash_cents'] ?? 0),
                'car_xp' => (int) ($reward['car_xp'] ?? 0),
                'xp' => (int) ($reward['xp'] ?? 0),
                'part' => $goal['reward_part_name'] === null ? null : I18nText::from($goal['reward_part_name'])->for(),
                'garage' => $goal['reward_garage_name'] === null ? null : I18nText::from($goal['reward_garage_name'])->for(),
            ],
        ];
    }
}
