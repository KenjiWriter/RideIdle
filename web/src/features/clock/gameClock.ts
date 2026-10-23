import type { Run } from '@/api/types'

/** The clock of the game as the server tells it with a run: how long a day is, and the hour at which each phase of it begins. */
export type GameClock = Run['clock']

export type Phase = 'dawn' | 'day' | 'dusk' | 'night'

/** The clock of the design (Systemy II): three real hours are a day, with a dawn, a day, a dusk and a night. Used until a run has told its own. */
export const DEFAULT_CLOCK: GameClock = {
  day_ms: 10_800_000,
  phases: [
    { key: 'dawn', from_hour: 5 },
    { key: 'day', from_hour: 8 },
    { key: 'dusk', from_hour: 18 },
    { key: 'night', from_hour: 22 },
  ],
}

export interface GameTime {
  /** The minute of the day of the game, from 0 (midnight) to 1439. */
  minute: number
  /** "11:20". */
  label: string
  phase: string
}

const pad = (value: number): string => String(value).padStart(2, '0')

/** The time of the game at a moment of the clock of the server: it needs nothing but that moment, like the server. */
export function gameTime(nowMs: number, clock: GameClock = DEFAULT_CLOCK): GameTime {
  const offset = ((nowMs % clock.day_ms) + clock.day_ms) % clock.day_ms
  const minute = Math.floor((offset * 1440) / clock.day_ms)
  const hour = Math.floor(minute / 60)

  // Before the first phase of the day the one of the night before is still on.
  const phase = [...clock.phases].reverse().find((candidate) => hour >= candidate.from_hour) ?? clock.phases[clock.phases.length - 1]

  return { minute, label: `${pad(hour)}:${pad(minute % 60)}`, phase: phase?.key ?? 'day' }
}

/**
 * How long until the night begins, when it begins later today and the phase is not night already: in ms, or null. A night that has
 * begun is not "soon", and the one after midnight is the one that is on.
 */
export function untilNight(nowMs: number, clock: GameClock = DEFAULT_CLOCK): number | null {
  const night = clock.phases.find((phase) => phase.key === 'night')

  if (!night || gameTime(nowMs, clock).phase === 'night') {
    return null
  }

  const offset = ((nowMs % clock.day_ms) + clock.day_ms) % clock.day_ms

  return (night.from_hour * clock.day_ms) / 24 - offset
}
