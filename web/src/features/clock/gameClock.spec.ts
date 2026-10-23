import { describe, expect, it } from 'vitest'

import { DEFAULT_CLOCK, gameTime, untilNight } from './gameClock'

const HOUR_MS = 450_000
const DAY_MS = 10_800_000

/** A moment of a day of the game that is far from the epoch. */
const at = (hour: number, minute = 0, day = 5_000): number => day * DAY_MS + hour * HOUR_MS + (minute * HOUR_MS) / 60

describe('gameTime', () => {
  it('makes three real hours a day: an hour of the game is seven and a half minutes', () => {
    expect(DEFAULT_CLOCK.day_ms).toBe(3 * 3_600_000)
    expect(gameTime(0).label).toBe('00:00')
    expect(gameTime(HOUR_MS).label).toBe('01:00')
    expect(gameTime(at(11, 20)).label).toBe('11:20')
    expect(gameTime(at(23, 59)).label).toBe('23:59')
  })

  it('is the same on every day, as the server counts it', () => {
    expect(gameTime(at(11, 20, 1)).label).toBe(gameTime(at(11, 20, 987_654)).label)
  })

  it.each([
    [0, 0, 'night'],
    [4, 59, 'night'],
    [5, 0, 'dawn'],
    [7, 59, 'dawn'],
    [8, 0, 'day'],
    [17, 59, 'day'],
    [18, 0, 'dusk'],
    [21, 59, 'dusk'],
    [22, 0, 'night'],
    [23, 59, 'night'],
  ])('is %i:%i in the phase %s', (hour, minute, phase) => {
    expect(gameTime(at(hour, minute)).phase).toBe(phase)
  })

  it('takes the phases the server told, and not its own', () => {
    const shifted = { day_ms: DAY_MS, phases: [{ key: 'day', from_hour: 6 }, { key: 'night', from_hour: 20 }] }

    expect(gameTime(at(7), shifted).phase).toBe('day')
    expect(gameTime(at(21), shifted).phase).toBe('night')
    expect(gameTime(at(2), shifted).phase).toBe('night')
  })
})

describe('untilNight', () => {
  it('tells how long until the night begins later today', () => {
    expect(untilNight(at(21))).toBe(HOUR_MS)
    expect(untilNight(at(12))).toBe(10 * HOUR_MS)
  })

  it('has nothing to tell when it is night already, before or after midnight', () => {
    expect(untilNight(at(22))).toBeNull()
    expect(untilNight(at(23, 30))).toBeNull()
    expect(untilNight(at(3))).toBeNull()
  })
})
