import { describe, expect, it } from 'vitest'

import { formatCountdown, lapProgress } from './lapProgress'

const lap = { start_ms: 1_000_000, finish_ms: 1_075_000, ms: 75_000, weather: 'dry' }

describe('lapProgress', () => {
  it('says how much of the lap is done and how long it still lasts', () => {
    expect(lapProgress(lap, 1_000_000)).toEqual({ remainingMs: 75_000, fraction: 0 })
    expect(lapProgress(lap, 1_030_000)).toEqual({ remainingMs: 45_000, fraction: 0.4 })
  })

  it('goes round again when the lap is over, until the server says what happened', () => {
    // A lap and a half later: halfway round the next one.
    expect(lapProgress(lap, 1_000_000 + 75_000 + 37_500)).toEqual({ remainingMs: 37_500, fraction: 0.5 })
    // Exactly at the end of the lap the next one begins.
    expect(lapProgress(lap, 1_075_000)).toEqual({ remainingMs: 75_000, fraction: 0 })
  })

  it('does not run backwards when the clock of the device is a little behind', () => {
    expect(lapProgress(lap, 999_000)).toEqual({ remainingMs: 75_000, fraction: 0 })
  })
})

describe('formatCountdown', () => {
  it.each([
    [75_000, '1:15'],
    [41_000, '0:41'],
    [40_100, '0:41'],
    [1, '0:01'],
    [0, '0:00'],
    [3_599_000, '59:59'],
  ])('writes %i ms as %s', (ms, text) => {
    expect(formatCountdown(ms)).toBe(text)
  })
})
