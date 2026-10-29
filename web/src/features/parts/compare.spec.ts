import { describe, expect, it } from 'vitest'

import { LOWER_IS_BETTER, compareStats } from './compare'

const changeOf = (offer: Record<string, number>, fitted: Record<string, number>, key: string) => compareStats(offer, fitted).changes.find((change) => change.key === key)

describe('LOWER_IS_BETTER', () => {
  it('is the statistics where less is better: what a booster costs (lag, fuel, load) and the kilograms, and none of the others', () => {
    expect([...LOWER_IS_BETTER].sort()).toEqual(['engine_load', 'engine_mass_kg', 'fuel_mult', 'lag', 'mass_kg'])

    for (const key of ['grip', 'handling', 'braking', 'power_mult', 'power_kw', 'boost_gain', 'cooling', 'fade_resistance', 'clearance', 'capacity_l', 'gears', 'top_speed_cap_kmh', 'launch']) {
      expect(LOWER_IS_BETTER.has(key), key).toBe(false)
    }
  })
})

describe('compareStats', () => {
  it('sets the offer against the fitted part statistic by statistic, with the difference the offer makes', () => {
    const { shared, changes } = compareStats({ grip: 100, handling: 98, braking: 100 }, { grip: 90, handling: 88, braking: 95 })

    expect(shared).toBe(3)
    expect(changes.map((change) => [change.key, change.delta, change.better])).toEqual([
      ['grip', 10, true],
      ['handling', 10, true],
      ['braking', 5, true],
    ])
  })

  it('counts a lower number as worse where more is better, and as better where less is better', () => {
    expect(changeOf({ grip: 80 }, { grip: 90 }, 'grip')).toMatchObject({ delta: -10, better: false })
    expect(changeOf({ lag: 3 }, { lag: 4 }, 'lag')).toMatchObject({ delta: -1, better: true })
    expect(changeOf({ lag: 5 }, { lag: 4 }, 'lag')).toMatchObject({ delta: 1, better: false })
    expect(changeOf({ fuel_mult: 1.15 }, { fuel_mult: 1.28 }, 'fuel_mult')).toMatchObject({ delta: -0.13, better: true })
    expect(changeOf({ engine_load: -6 }, { engine_load: 0 }, 'engine_load')).toMatchObject({ delta: -6, better: true })
    expect(changeOf({ mass_kg: 7 }, { mass_kg: 3 }, 'mass_kg')).toMatchObject({ delta: 4, better: false })
    expect(changeOf({ engine_mass_kg: 80 }, { engine_mass_kg: 95 }, 'engine_mass_kg')).toMatchObject({ delta: -15, better: true })
  })

  it('shows the factors of a booster as the percent they add, as the part does', () => {
    // 1.45 against 1.38 is 7 percentage points more power; a gain of 0.03 against 0.02 is 1.
    expect(changeOf({ power_mult: 1.45 }, { power_mult: 1.38 }, 'power_mult')).toMatchObject({ delta: 7, unit: '%', digits: 1, better: true })
    expect(changeOf({ boost_gain: 0.03 }, { boost_gain: 0.02 }, 'boost_gain')).toMatchObject({ delta: 1, unit: '%' })
  })

  it('gives each statistic the unit it is written in', () => {
    expect(changeOf({ power_kw: 110 }, { power_kw: 97 }, 'power_kw')).toMatchObject({ delta: 13, unit: ' kW', digits: 0 })
    expect(changeOf({ capacity_l: 60 }, { capacity_l: 45 }, 'capacity_l')).toMatchObject({ delta: 15, unit: ' l' })
    expect(changeOf({ top_speed_cap_kmh: 300 }, { top_speed_cap_kmh: 250 }, 'top_speed_cap_kmh')).toMatchObject({ delta: 50, unit: ' km/h' })
    expect(changeOf({ launch: 2 }, { launch: -2 }, 'launch')).toMatchObject({ delta: 4, unit: '%' })
    expect(changeOf({ clearance: 110 }, { clearance: 25 }, 'clearance')).toMatchObject({ delta: 85, unit: '' })
  })

  it('compares like for like and nothing else: a statistic that only one of the parts has is left out', () => {
    const { shared, changes } = compareStats({ handling: 100, clearance: 25 }, { handling: 90, grip: 90 })

    expect(shared).toBe(1)
    expect(changes.map((change) => change.key)).toEqual(['handling'])
  })

  it('leaves out a statistic that is the same, which is not a change, and counts it among the ones that both parts have', () => {
    const { shared, changes } = compareStats({ grip: 90, handling: 99 }, { grip: 90, handling: 88 })

    expect(shared).toBe(2)
    expect(changes.map((change) => change.key)).toEqual(['handling'])
  })

  it('leaves out a difference that is too small to be shown (it rounds to nothing in the digits of the statistic)', () => {
    expect(compareStats({ grip: 90.04 }, { grip: 90 }).changes).toEqual([])
    expect(compareStats({ fuel_mult: 1.2804 }, { fuel_mult: 1.28 }).changes).toEqual([])
    expect(compareStats({ grip: 90.06 }, { grip: 90 }).changes.map((change) => change.delta)).toEqual([0.1])
  })

  it('has nothing to say when the parts have no statistics in common, or none at all', () => {
    expect(compareStats({ grip: 1 }, { cooling: 1 })).toEqual({ shared: 0, changes: [] })
    expect(compareStats({}, {})).toEqual({ shared: 0, changes: [] })
  })
})
