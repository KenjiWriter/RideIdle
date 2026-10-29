import { describe, expect, it } from 'vitest'

import { LOWER_IS_BETTER, compareStats } from './compare'

describe('the statistics where less is better', () => {
  it('are the lag, the fuel, the load on the engine and the mass, and no other', () => {
    expect([...LOWER_IS_BETTER].sort()).toEqual(['engine_load', 'engine_mass_kg', 'fuel_mult', 'lag', 'mass_kg'])
  })
})

describe('compareStats', () => {
  it('sets only the statistics that both parts have, like for like', () => {
    // Tires and a part of the suspension share the handling, and nothing else.
    const { shared, changes } = compareStats({ grip: 110, handling: 98, braking: 100 }, { handling: 90, clearance: 25 })

    expect(shared).toBe(1)
    expect(changes.map((change) => change.key)).toEqual(['handling'])
  })

  it('says by how much a statistic is higher or lower, with the offer less the fitted part', () => {
    const { changes } = compareStats({ grip: 120, braking: 90 }, { grip: 100, braking: 100 })

    expect(changes).toEqual([
      { key: 'grip', delta: 20, digits: 1, unit: '', better: true },
      { key: 'braking', delta: -10, digits: 1, unit: '', better: false },
    ])
  })

  it('counts a statistic that is the same on both as shared, and not as a change', () => {
    const { shared, changes } = compareStats({ grip: 100, handling: 98 }, { grip: 100, handling: 98 })

    expect(shared).toBe(2)
    expect(changes).toEqual([])
  })

  it('has nothing to say when there is nothing in common', () => {
    expect(compareStats({ grip: 100 }, { clearance: 25 })).toEqual({ shared: 0, changes: [] })
    expect(compareStats({}, { grip: 100 })).toEqual({ shared: 0, changes: [] })
  })

  it.each([
    ['lag', 3, 5],
    ['fuel_mult', 1.15, 1.28],
    ['engine_load', 4, 10],
    ['mass_kg', 2, 6],
    ['engine_mass_kg', 150, 210],
  ])('takes less %s for the better: %s against %s', (key, offer, fitted) => {
    const [change] = compareStats({ [key]: offer }, { [key]: fitted }).changes

    expect(change!.delta).toBeLessThan(0)
    expect(change!.better).toBe(true)
  })

  it.each([
    ['lag', 6, 4],
    ['fuel_mult', 1.3, 1.2],
    ['engine_load', 12, 8],
    ['mass_kg', 8, 2],
    ['engine_mass_kg', 300, 250],
  ])('takes more %s for the worse: %s against %s', (key, offer, fitted) => {
    const [change] = compareStats({ [key]: offer }, { [key]: fitted }).changes

    expect(change!.delta).toBeGreaterThan(0)
    expect(change!.better).toBe(false)
  })

  it.each([
    ['grip', 1],
    ['handling', 1],
    ['braking', 1],
    ['power_kw', 1],
    ['power_mult', 1],
    ['boost_gain', 1],
    ['cooling', 1],
    ['fade_resistance', 1],
    ['clearance', 1],
    ['capacity_l', 1],
    ['gears', 1],
    ['top_speed_cap_kmh', 1],
    ['launch', 1],
  ])('takes more %s for the better, and less for the worse', (key) => {
    const up = compareStats({ [key]: 2 }, { [key]: 1 }).changes[0]!
    const down = compareStats({ [key]: 1 }, { [key]: 2 }).changes[0]!

    expect(up.better).toBe(true)
    expect(down.better).toBe(false)
  })

  it('treats the boosters of the shop right: a booster that answers sooner and burns less is better, one with more power too', () => {
    const duo = { power_mult: 1.3, lag: 4, fuel_mult: 1.17, engine_load: 10 }
    const mini = { power_mult: 1.15, lag: 2, fuel_mult: 1.1, engine_load: 6 }

    const verdicts = Object.fromEntries(compareStats(duo, mini).changes.map((change) => [change.key, change.better]))

    // Against the smaller one the Duo has more power, and answers later, burns more and loads the engine more.
    expect(verdicts).toEqual({ power_mult: true, lag: false, fuel_mult: false, engine_load: false })
  })

  it('shows a factor as the percent it adds, a booster gain too, and the mass and the power with their unit, rounded to what is shown', () => {
    const { changes } = compareStats(
      { power_mult: 1.3, boost_gain: 0.05, fuel_mult: 1.17, mass_kg: 5, power_kw: 150, launch: 2 },
      { power_mult: 1.15, boost_gain: 0.02, fuel_mult: 1.28, mass_kg: -5, power_kw: 120, launch: 0 },
    )
    const by = Object.fromEntries(changes.map((change) => [change.key, change]))

    expect(by.power_mult).toMatchObject({ delta: 15, digits: 1, unit: '%' })
    expect(by.boost_gain).toMatchObject({ delta: 3, digits: 1, unit: '%' })
    expect(by.fuel_mult).toMatchObject({ delta: -0.11, digits: 2, unit: '' })
    expect(by.mass_kg).toMatchObject({ delta: 10, unit: ' kg' })
    expect(by.power_kw).toMatchObject({ delta: 30, digits: 0, unit: ' kW' })
    expect(by.launch).toMatchObject({ delta: 2, unit: '%' })
  })

  it('leaves out a change that is too small to be seen in what is shown', () => {
    expect(compareStats({ grip: 100.04 }, { grip: 100 }).changes).toEqual([])
    expect(compareStats({ power_mult: 1.0004 }, { power_mult: 1 }).changes).toEqual([])
  })
})
