import { describe, expect, it } from 'vitest'

import { aCar } from '@/test/garageFixtures'

import { statFill } from './statFill'

describe('statFill', () => {
  it('puts a car that has just left the wreck yard well under the middle of every bar', () => {
    const car = aCar()

    // The factory Rdzak GT-4 against the ceilings of the rules.
    expect(statFill(car, 'accel')).toBe(24)
    expect(statFill(car, 'top_speed')).toBe(39)
    expect(statFill(car, 'grip')).toBe(36)
    expect(statFill(car, 'handling')).toBe(35)
    expect(statFill(car, 'braking')).toBe(36)
  })

  it('fills the bar at the ceiling and no further', () => {
    const car = aCar()
    car.stats.grip = car.ceilings.grip
    car.stats.accel = car.ceilings.accel * 3

    expect(statFill(car, 'grip')).toBe(100)
    expect(statFill(car, 'accel')).toBe(100)
  })

  it('leaves the bar empty when there is no car or no ceiling', () => {
    const car = aCar()
    car.ceilings.grip = 0

    expect(statFill(null, 'grip')).toBe(0)
    expect(statFill(car, 'grip')).toBe(0)
  })
})
