/**
 * The level of a driver from their experience, by the curve of the design (Systemy VI): the XP it takes in all to reach
 * level n is 45 * n ^ 2.3. It is only shown for now (the server does not gate anything by it yet), and the numbers are
 * provisional like the rest of the economy, so they are in one place.
 */
export function xpToReach(level: number): number {
  return Math.round(45 * level ** 2.3)
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

  while (total >= xpToReach(level + 1)) {
    level += 1
  }

  const from = level === 1 ? 0 : xpToReach(level)

  return { level, into: total - from, needed: xpToReach(level + 1) - from }
}
