import { describe, expect, it } from 'vitest'

import type { CarStatKey } from '@/api/types'

import { MAX_SCORE, overallScore } from './score'

const STATS: CarStatKey[] = ['accel', 'top_speed', 'grip', 'handling', 'braking', 'clearance']
const CEILINGS = { accel: 100, top_speed: 200, grip: 50, handling: 100, braking: 80, clearance: 40 }

const car = (stats: Partial<Record<CarStatKey, number>>) => ({
  stats: { accel: 0, top_speed: 0, grip: 0, handling: 0, braking: 0, clearance: 0, ...stats },
  ceilings: CEILINGS,
})

describe('overallScore', () => {
  it('is the mean fill of the bars, out of a thousand', () => {
    // Half of every bar is half of the scale.
    expect(overallScore(car({ accel: 50, top_speed: 100, grip: 25, handling: 50, braking: 40, clearance: 20 }), STATS)).toBe(500)
    // A third of the bars full is a sixth of it.
    expect(overallScore(car({ accel: 100, top_speed: 200 }), STATS)).toBe(333)
  })

  it('is empty for a car with nothing and full for a car at the ceilings', () => {
    expect(overallScore(car({}), STATS)).toBe(0)
    expect(overallScore(car(CEILINGS), STATS)).toBe(MAX_SCORE)
  })

  it('counts what is above a ceiling as full, and what is below nothing as nothing', () => {
    expect(overallScore(car({ ...CEILINGS, accel: 900, grip: -5 }), STATS)).toBe(Math.round((5 / 6) * MAX_SCORE))
  })

  it('counts only the statistics the model lists', () => {
    expect(overallScore(car({ accel: 100, top_speed: 200 }), ['accel', 'top_speed'])).toBe(MAX_SCORE)
    expect(overallScore(car({ accel: 100 }), ['accel'])).toBe(MAX_SCORE)
  })

  it('is nothing without a car, without statistics, and against a ceiling that is not there', () => {
    expect(overallScore(null, STATS)).toBe(0)
    expect(overallScore(car(CEILINGS), [])).toBe(0)
    expect(overallScore({ stats: car({ accel: 10 }).stats, ceilings: { ...CEILINGS, accel: 0 } }, ['accel'])).toBe(0)
  })
})
