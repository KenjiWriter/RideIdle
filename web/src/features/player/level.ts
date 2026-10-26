/**
 * The level of a driver from their experience: the table of the first levels, a straight line to the catch-up level, and then the curve of
 * the design (Systemy VI). It is the curve of the server (`PlayerXpCurve`, with the numbers of `player` in the rules of the game), drawn
 * here for the bar of the HUD; the numbers are provisional like the rest of the economy, so they are in one place.
 */
const BASE = 450
const EXPONENT = 2.3
const MAX_LEVEL = 30
/** The experience that reaches level 2, 3, ... 10: quick, so that the first hour is. */
const EARLY = [60, 180, 400, 800, 1_600, 2_800, 4_800, 8_000, 12_800]
/** From this level on the curve is the plain one; the levels between the table and it are on a straight line. */
const CATCH_UP_LEVEL = 20

const plain = (level: number): number => BASE * level ** EXPONENT

export function xpToReach(level: number): number {
  if (level <= 1) {
    return 0
  }

  if (level - 2 < EARLY.length) {
    return EARLY[level - 2]!
  }

  if (level >= CATCH_UP_LEVEL) {
    return Math.round(plain(level))
  }

  const from = EARLY.length + 1
  const start = EARLY[EARLY.length - 1]!

  return Math.round(start + ((plain(CATCH_UP_LEVEL) - start) * (level - from)) / (CATCH_UP_LEVEL - from))
}

export interface LevelProgress {
  level: number
  /** The XP earned in this level. */
  into: number
  /** The XP this level takes in all, from its start to the start of the next one. */
  needed: number
}

/** A new driver is level 1, and level 2 starts at the XP of the curve for level 2. */
export function levelOf(xp: number): LevelProgress {
  const total = Math.max(0, Math.floor(xp))
  let level = 1

  while (level < MAX_LEVEL && total >= xpToReach(level + 1)) {
    level += 1
  }

  const from = xpToReach(level)

  return { level, into: total - from, needed: xpToReach(Math.min(MAX_LEVEL, level + 1)) - from || 1 }
}
