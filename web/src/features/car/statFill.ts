import type { CarStatKey, GarageCar } from '@/api/types'

/**
 * How full the bar of a statistic is, in percent: the value against the ceiling of the rules, which is what the best car
 * of the whole game reaches. The same on every track, so a wreck that has just left the yard stands low on it and parts
 * move it a little at a time.
 */
export function statFill(car: Pick<GarageCar, 'stats' | 'ceilings'> | null, stat: CarStatKey): number {
  const ceiling = car?.ceilings[stat] ?? 0

  return car && ceiling > 0 ? Math.min(100, Math.max(0, Math.round((car.stats[stat] / ceiling) * 100))) : 0
}
