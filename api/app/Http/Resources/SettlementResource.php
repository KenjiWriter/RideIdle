<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One settlement of a run: what the car did between two moments, and the log of it.
 * `version` is the state version of the run when it was made; a client that holds the version of the run
 * it last saw asks for `since_version` to get everything made since.
 *
 * @mixin Settlement
 */
final class SettlementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->state_version,
            'from_ms' => $this->cursor_from_ms,
            'to_ms' => $this->cursor_to_ms,
            'at_ms' => $this->now_ms,
            'laps' => $this->laps,
            'cash_cents' => $this->cash_cents,
            'xp' => $this->xp,
            'stop_reason' => $this->stop_reason,
            'events' => $this->events->map(static fn ($event): array => [
                'type' => $event->type,
                'at_ms' => $event->at_ms,
                'payload' => (object) $event->payload, // {} and not [] when the event has no details
            ])->all(),
        ];
    }
}
