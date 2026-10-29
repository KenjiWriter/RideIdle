/**
 * The statistics where LESS is better, when a part for sale is set against the part that is fitted:
 * - `lag`: how late a booster answers (3/10 is better than 5/10);
 * - `fuel_mult`: how much more fuel a booster burns (a factor: 1.15 burns less than 1.28);
 * - `engine_load`: the wear a booster puts on the engine (the boosters add to it, the radiators and the intercoolers take away from it);
 * - `mass_kg`, `engine_mass_kg`: the kilograms the car has to carry.
 * Every other statistic is better when it is higher: the grips and the braking, the power of the engine and of a booster, the cooling and the resistance to fading, the
 * clearance, the capacity of a tank, the number of gears, the limit of a gearbox and its launch.
 */
export const LOWER_IS_BETTER: ReadonlySet<string> = new Set(['lag', 'fuel_mult', 'engine_load', 'mass_kg', 'engine_mass_kg'])

/** How the change of a statistic is shown: the factors of a booster as percent (as the part shows them), the kilograms and the like with their unit. */
interface Display {
  /** What the change is multiplied by (the power of a booster is a factor, 1.38, and is shown as the 38 % it adds). */
  scale: number
  /** Decimals of the number shown. */
  digits: number
  unit: string
}

const DISPLAY: Record<string, Display> = {
  power_mult: { scale: 100, digits: 1, unit: '%' },
  boost_gain: { scale: 100, digits: 1, unit: '%' },
  launch: { scale: 1, digits: 1, unit: '%' },
  fuel_mult: { scale: 1, digits: 2, unit: '' },
  power_kw: { scale: 1, digits: 0, unit: ' kW' },
  mass_kg: { scale: 1, digits: 1, unit: ' kg' },
  engine_mass_kg: { scale: 1, digits: 1, unit: ' kg' },
  capacity_l: { scale: 1, digits: 1, unit: ' l' },
  top_speed_cap_kmh: { scale: 1, digits: 0, unit: ' km/h' },
}

const PLAIN: Display = { scale: 1, digits: 1, unit: '' }

/** One statistic that is not the same on the two parts: by how much (in the units it is shown in), and whether the part for sale is the better for it. */
export interface StatChange {
  key: string
  /** The offer less the fitted part, rounded to what is shown; never 0. */
  delta: number
  digits: number
  unit: string
  better: boolean
}

export interface Comparison {
  /** How many statistics both parts have: nothing to compare when there are none. */
  shared: number
  /** The ones that differ, in the order of the part for sale. */
  changes: StatChange[]
}

/** Sets the statistics of a part for sale against those of the part on the same slot of the car: like for like, never across two different statistics. */
export function compareStats(offer: Record<string, number>, fitted: Record<string, number>): Comparison {
  const changes: StatChange[] = []
  let shared = 0

  for (const key of Object.keys(offer)) {
    if (!(key in fitted)) {
      continue
    }

    shared += 1

    const { scale, digits, unit } = DISPLAY[key] ?? PLAIN
    const factor = 10 ** digits
    const delta = Math.round((offer[key]! - fitted[key]!) * scale * factor) / factor

    if (delta !== 0) {
      changes.push({ key, delta, digits, unit, better: LOWER_IS_BETTER.has(key) ? delta < 0 : delta > 0 })
    }
  }

  return { shared, changes }
}
