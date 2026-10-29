import type { CarStatKey, GarageCar } from '@/api/types'

/** The highest overall score: every statistic of the car at the ceiling of the rules. */
export const MAX_SCORE = 1000

/**
 * The overall score of a car: how full its bars are on the average, from 0 to 1000. A number to compare cars by, shown on the card of the specification and nowhere
 * else: no formula of the game reads it, so it changes nothing about a race. It counts the statistics the model of the game lists, each against its ceiling, as the
 * bars do (a car whose statistics are above the ceiling counts as full).
 */
export function overallScore(car: Pick<GarageCar, 'stats' | 'ceilings'> | null, stats: readonly CarStatKey[]): number {
  if (!car || stats.length === 0) {
    return 0
  }

  const share = stats.reduce((total, stat) => {
    const ceiling = car.ceilings[stat] ?? 0

    return total + (ceiling > 0 ? Math.min(1, Math.max(0, car.stats[stat] / ceiling)) : 0)
  }, 0)

  return Math.round((share / stats.length) * MAX_SCORE)
}
