<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Run\Rarity;
use App\Models\WorkshopJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A job of the workshop. The times are milliseconds of the clock of the server (`now_ms` of the same answer says what time
 * it is there), so the client can count down without trusting its own clock.
 *
 * @property-read WorkshopJob $resource
 */
final class WorkshopJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'kind' => $this->resource->kind,
            'status' => $this->resource->status,
            // An upgrade says what it cost and the level it takes the part to (0 and null for the other jobs).
            'cost_cents' => $this->resource->cost_cents,
            'upgrade_to' => $this->resource->upgrade_to,
            // A merge says which part is used up with the one that stays (`part`), and the rarity it aims at.
            'merge_with' => $this->resource->merge_part_id === null ? null : PartResource::make($this->resource->mergePart)->resolve(),
            'merge_to' => $this->resource->kind === WorkshopJob::KIND_MERGE ? Rarity::from($this->resource->part->rarity + 1)->key() : null,
            'part' => PartResource::make($this->resource->part)->resolve(),
            'start_ms' => $this->resource->start_ms,
            'finish_ms' => $this->resource->finish_ms,
        ];
    }
}
