<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
                'target' => $step['target'],
                'acknowledged' => $step['acknowledged'],
            ],
            // What the steps completed by this call paid, so the client can say it and refresh the cash.
            'rewarded' => $state->rewarded,
        ];
    }
}
