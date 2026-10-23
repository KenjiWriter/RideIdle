import type { Run } from '@/api/types'

export interface LapProgress {
  /** How long the lap in progress still lasts, in ms. */
  remainingMs: number
  /** How much of it is done, from 0 up to but not including 1. */
  fraction: number
}

/**
 * How far the lap in progress has got at `nowMs`, in the clock of the server. The server settles a run when it is asked,
 * so a lap that has just ended is followed here by another of the same length until the next answer says what really
 * happened (a new weather, an empty tank): the ring goes round on its own and is put right by the answer.
 */
export function lapProgress(lap: NonNullable<Run['current_lap']>, nowMs: number): LapProgress {
  const elapsed = Math.max(0, nowMs - lap.start_ms) % lap.ms

  return { remainingMs: lap.ms - elapsed, fraction: elapsed / lap.ms }
}

/** A time that counts down as "0:41": the seconds are rounded up, so the ring says 0:01 until the lap is done. */
export function formatCountdown(ms: number): string {
  const seconds = Math.max(0, Math.ceil(ms / 1000))

  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}
