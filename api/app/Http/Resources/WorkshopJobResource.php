<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
            'part' => PartResource::make($this->resource->part)->resolve(),
            'start_ms' => $this->resource->start_ms,
            'finish_ms' => $this->resource->finish_ms,
        ];
    }
}
