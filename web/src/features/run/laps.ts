import type { Run } from '@/api/types'

/** The weather the numbers on screen are for when nothing says otherwise. */
const BASE_WEATHER = 'dry'

/** Lap time in dry weather in ms, the reference for estimates; the first weather the server sent when there is no dry one. */
export function baseLapMs(run: Run): number {
  return run.lap.ms[BASE_WEATHER] ?? Object.values(run.lap.ms)[0] ?? 0
}

/** Reward of a lap in dry weather, in cents. */
export function baseRewardCents(run: Run): number {
  return run.lap.cash_cents[BASE_WEATHER] ?? Object.values(run.lap.cash_cents)[0] ?? 0
}

/** How many whole laps the fuel in the tank lasts. */
export function lapsOfFuel(run: Run): number {
  return run.lap.fuel_ml > 0 ? Math.floor(run.fuel_ml / run.lap.fuel_ml) : 0
}
