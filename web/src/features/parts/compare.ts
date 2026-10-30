import { KMH, type ShownSpeedUnit } from '@/game/units'

/**
 * The statistics where LESS is better, when a part is set against the part that is fitted:
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

/** The statistic that is a speed, which the server keeps in km/h and the game shows in its own unit of speed. */
const SPEED_KEY = 'top_speed_cap_kmh'

const DISPLAY: Record<string, Display> = {
  power_mult: { scale: 100, digits: 1, unit: '%' },
  boost_gain: { scale: 100, digits: 1, unit: '%' },
  launch: { scale: 1, digits: 1, unit: '%' },
  fuel_mult: { scale: 1, digits: 2, unit: '' },
  power_kw: { scale: 1, digits: 0, unit: ' kW' },
  mass_kg: { scale: 1, digits: 1, unit: ' kg' },
  engine_mass_kg: { scale: 1, digits: 1, unit: ' kg' },
  capacity_l: { scale: 1, digits: 1, unit: ' l' },
}

const PLAIN: Display = { scale: 1, digits: 1, unit: '' }

/** How a statistic is shown: the speed in the unit of the game (a knot is 1.852 km/h), the rest as the table has it. */
function displayOf(key: string, speed: ShownSpeedUnit): Display {
  return key === SPEED_KEY ? { scale: 1 / speed.kmh, digits: speed.decimals, unit: ` ${speed.short}` } : (DISPLAY[key] ?? PLAIN)
}

/** The grip of tires on a surface is a factor (1 is the full grip, 0.85 loses 15 % of it): its change is shown in percent. */
const SURFACE: Display = { scale: 100, digits: 0, unit: '%' }

/** The prefix of the key of a change of the grip on a surface, which is set apart from the statistics: `surface:asphalt_wet`. */
export const SURFACE_PREFIX = 'surface:'

/** One statistic that is not the same on the two parts: by how much (in the units it is shown in), and whether the part set against the fitted one is the better for it. */
export interface StatChange {
  key: string
  /** The part less the fitted part, rounded to what is shown; never 0. */
  delta: number
  digits: number
  unit: string
  better: boolean
}

export interface Comparison {
  /** How many statistics both parts have: nothing to compare when there are none. */
  shared: number
  /** The ones that differ, in the order of the part. */
  changes: StatChange[]
}

/** What a part has to be set against another: its statistics, and the grip of its tires on the surfaces when it has them. */
export interface PartLike {
  stats: Record<string, number>
  surfaces?: Record<string, number> | null
}

/** Where a comparison leaves the part: better than the fitted one, worse, better in some things and worse in others, or no different. */
export type VerdictKind = 'better' | 'worse' | 'mixed' | 'same'

export interface Verdict {
  kind: VerdictKind
  /** The mean of the changes of everything that both parts have (each one's gain as a share of the larger of the two numbers, at most half), in percent: it is what the kind is made of. */
  net: number
  improved: number
  worsened: number
}

/** A change of a statistic counts as at most this share of it, so that a number that is small (a lag of 3 against 5) does not outweigh the rest. */
const GAIN_LIMIT = 0.5

/**
 * Both better and worse: a part that does one thing much better and another a little worse is better, and one that is better and worse by about as much is neither. Below this
 * mean change (5 %) of the statistics the two kinds of change are taken to cancel out, and the verdict is "depends". A part that gains on some surfaces and loses on others
 * (rain tires, winter tires, off-road tires) is "depends" whatever the mean says: which of them is worth fitting is a matter of the weather and of the track that is driven,
 * and the dry asphalt, where most laps are driven, may be the one it loses on.
 */
const MIXED_BELOW = 5

/** The gain of a change as a share of the larger of the two numbers: positive when the part is the better for it, whichever way the number goes. */
function gain(part: number, fitted: number, lowerIsBetter: boolean): number {
  const base = Math.max(Math.abs(part), Math.abs(fitted))

  if (base === 0) {
    return 0
  }

  const share = (part - fitted) / base

  return Math.max(-GAIN_LIMIT, Math.min(GAIN_LIMIT, lowerIsBetter ? -share : share))
}

interface Walked {
  shared: number
  changes: StatChange[]
  gains: number[]
}

/** Walks the numbers that both parts have, in the order of the part: the change of each, and the gain of each (nought for the ones that are the same). */
function walk(part: Record<string, number>, fitted: Record<string, number>, keyOf: (key: string) => string, displayOf: (key: string) => Display, lowerOf: (key: string) => boolean): Walked {
  const changes: StatChange[] = []
  const gains: number[] = []
  let shared = 0

  for (const key of Object.keys(part)) {
    if (!(key in fitted)) {
      continue
    }

    shared += 1

    const { scale, digits, unit } = displayOf(key)
    const factor = 10 ** digits
    const delta = Math.round((part[key]! - fitted[key]!) * scale * factor) / factor

    gains.push(gain(part[key]!, fitted[key]!, lowerOf(key)))

    if (delta !== 0) {
      changes.push({ key: keyOf(key), delta, digits, unit, better: lowerOf(key) ? delta < 0 : delta > 0 })
    }
  }

  return { shared, changes, gains }
}

/**
 * Sets the statistics of a part against those of the part on the same slot of the car: like for like, never across two different statistics. The change of the limit of a
 * gearbox is shown in the unit of speed that is given (km/h where there is none).
 */
export function compareStats(part: Record<string, number>, fitted: Record<string, number>, speed: ShownSpeedUnit = KMH): Comparison {
  const { shared, changes } = walk(part, fitted, (key) => key, (key) => displayOf(key, speed), (key) => LOWER_IS_BETTER.has(key))

  return { shared, changes }
}

/**
 * Sets a part against the one that is fitted on its slot: its statistics, and the grip of its tires on each surface when both have it (tires are told apart as much by what
 * they do in the wet and on the mud as by their grip), and says where it leaves the part: worth fitting, worse, better in some things and worse in others, or the same.
 * Null when the two have nothing in common to set against each other.
 */
export function comparePart(part: PartLike, fitted: PartLike, speed: ShownSpeedUnit = KMH): (Comparison & { verdict: Verdict | null }) | null {
  const stats = walk(part.stats, fitted.stats, (key) => key, (key) => displayOf(key, speed), (key) => LOWER_IS_BETTER.has(key))
  const surfaces = part.surfaces && fitted.surfaces ? walk(part.surfaces, fitted.surfaces, (key) => `${SURFACE_PREFIX}${key}`, () => SURFACE, () => false) : { shared: 0, changes: [], gains: [] }
  const shared = stats.shared + surfaces.shared

  if (shared === 0) {
    return null
  }

  const changes = [...stats.changes, ...surfaces.changes]
  const gains = [...stats.gains, ...surfaces.gains]
  const improved = changes.filter((change) => change.better).length
  const worsened = changes.length - improved
  const surfacesTradeOff = surfaces.changes.some((change) => change.better) && surfaces.changes.some((change) => !change.better)
  const net = Math.round((gains.reduce((total, one) => total + one, 0) / gains.length) * 1000) / 10

  let kind: VerdictKind

  if (changes.length === 0) {
    kind = 'same'
  } else if (worsened === 0) {
    kind = 'better'
  } else if (improved === 0) {
    kind = 'worse'
  } else if (surfacesTradeOff || Math.abs(net) < MIXED_BELOW) {
    kind = 'mixed'
  } else {
    kind = net > 0 ? 'better' : 'worse'
  }

  return { shared, changes, verdict: { kind, net, improved, worsened } }
}
