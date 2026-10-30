<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartResource;
use App\Models\Part;
use App\Services\Missions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The missions of a driver: the sets of a day, a week and a month, with how far the driver is, and taking what they pay. Looking brings the missions up to date, as
 * looking at a run settles it, so the answer is true only at the moment of the call and is never cached.
 */
final class MissionController extends Controller
{
    public function __construct(private readonly Missions $missions) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->userId($request));
    }

    /** Takes the reward of one mission. Taking it twice takes it once (`repeated`). */
    public function claim(Request $request, int $mission): JsonResponse
    {
        $userId = $this->userId($request);
        $claimed = $this->missions->claim($userId, $mission);

        return $this->respond($userId, ['repeated' => $claimed['repeated'], 'claimed' => $this->told([$claimed['mission']], $claimed['result'])]);
    }

    /** Takes every mission of a kind (`daily`, `weekly`, `monthly`, `main` or `all`) that is done. */
    public function claimAll(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', Rule::in([...Missions::KINDS, 'all'])]]);

        $userId = $this->userId($request);
        $claimed = $this->missions->claimAll($userId, $data['kind']);

        return $this->respond($userId, ['repeated' => $claimed['claimed'] === [], 'claimed' => $this->told($claimed['claimed'], $claimed['result'])]);
    }

    /** Takes the premium coins for having done every mission of the set of the day, the week or the month. */
    public function bonus(Request $request, string $kind): JsonResponse
    {
        $userId = $this->userId($request);
        $claimed = $this->missions->claimBonus($userId, $kind);

        return $this->respond($userId, ['repeated' => $claimed['repeated'], 'bonus_coins' => $claimed['coins']]);
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    /**
     * What taking missions gave, for the screen that tells it: the missions, the cash and XP, and the parts as the shop shows a part that was opened.
     *
     * @param  list<array<string, mixed>>  $missions
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function told(array $missions, array $result): array
    {
        $parts = Part::query()->whereIn('id', $result['parts'] ?? [])->orderBy('id')->get();

        return [
            'missions' => $missions,
            'cash_cents' => (int) ($result['cash_cents'] ?? 0),
            'xp' => (int) ($result['xp'] ?? 0),
            'car_xp' => (int) ($result['car_xp'] ?? 0),
            'crates' => $result['crates'] ?? [],
            'parts' => $parts->map(fn (Part $part): array => PartResource::make($part)->resolve())->all(),
        ];
    }

    /** @param  array<string, mixed>  $extra  more keys of the top level of the answer */
    private function respond(int $userId, array $extra = []): JsonResponse
    {
        return response()->json(['data' => $this->missions->view($userId), ...$extra])->header('Cache-Control', 'no-store');
    }
}
