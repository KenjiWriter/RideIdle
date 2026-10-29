import { describe, expect, it } from 'vitest'

import { LOWER_IS_BETTER, SURFACE_PREFIX, compareStats, comparePart } from './compare'

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

describe('comparing a part with the one that is fitted', () => {
  const FITTED = { stats: { grip: 90, handling: 88, braking: 90 }, surfaces: { asphalt_dry: 1, asphalt_wet: 0.85, gravel_dry: 0.6, mud: 0.45, snow: 0.4 } }

  it('is nothing when the two have nothing in common to set against each other', () => {
    expect(comparePart({ stats: { grip: 90 } }, { stats: { power_mult: 1.1 } })).toBeNull()
    expect(comparePart({ stats: {} }, { stats: {} })).toBeNull()
  })

  it('sets the grip on each surface against that of the part that is fitted, apart from the statistics, in percent', () => {
    const result = comparePart({ stats: FITTED.stats, surfaces: { ...FITTED.surfaces, asphalt_wet: 0.95, snow: 0.3 } }, FITTED)!

    expect(result.shared).toBe(8)
    expect(result.changes.map((change) => change.key)).toEqual([`${SURFACE_PREFIX}asphalt_wet`, `${SURFACE_PREFIX}snow`])
    expect(result.changes[0]).toMatchObject({ delta: 10, digits: 0, unit: '%', better: true })
    expect(result.changes[1]).toMatchObject({ delta: -10, better: false })
  })

  it('leaves the surfaces out when either part does not give them (a part that is not a tire has none)', () => {
    expect(comparePart({ stats: { grip: 100 }, surfaces: FITTED.surfaces }, { stats: { grip: 90 } })!.shared).toBe(1)
    expect(comparePart({ stats: { grip: 100 } }, { stats: { grip: 90 }, surfaces: null })!.shared).toBe(1)
  })

  describe('the verdict', () => {
    it('is "better" when something is better and nothing is worse', () => {
      const verdict = comparePart({ stats: { grip: 100, handling: 88, braking: 99 } }, FITTED)!.verdict!

      expect(verdict).toMatchObject({ kind: 'better', improved: 2, worsened: 0 })
      expect(verdict.net).toBeGreaterThan(0)
    })

    it('is "worse" when something is worse and nothing is better', () => {
      expect(comparePart({ stats: { grip: 80, handling: 88, braking: 85 } }, FITTED)!.verdict).toMatchObject({ kind: 'worse', improved: 0, worsened: 2 })
    })

    it('is "the same" for a part that has all the numbers of the fitted one', () => {
      expect(comparePart({ stats: FITTED.stats, surfaces: FITTED.surfaces }, FITTED)!.verdict).toEqual({ kind: 'same', net: 0, improved: 0, worsened: 0 })
    })

    it('is "depends" when it is better in one thing and worse in another by about as much, and the one that gains more when it gains a good deal more', () => {
      // A tenth more grip, a twentieth less braking: the mean change is 1.5 %, which is nothing to choose by.
      expect(comparePart({ stats: { grip: 100, handling: 88, braking: 85 } }, FITTED)!.verdict).toMatchObject({ kind: 'mixed', improved: 1, worsened: 1 })
      // A great deal more grip and a little less braking: better.
      expect(comparePart({ stats: { grip: 120, handling: 110, braking: 88 } }, FITTED)!.verdict).toMatchObject({ kind: 'better', improved: 2, worsened: 1 })
      // And the other way round.
      expect(comparePart({ stats: { grip: 70, handling: 70, braking: 92 } }, FITTED)!.verdict).toMatchObject({ kind: 'worse', improved: 1, worsened: 2 })
    })

    it('is "depends" for a part that gains on some surfaces and loses on others, whatever the mean says: it is the weather and the track that decide', () => {
      // Rain tires: much better on the wet, the mud and the snow, a little worse on the dry. The mean of it is a good deal more than 5 %.
      const rain = { stats: FITTED.stats, surfaces: { asphalt_dry: 0.97, asphalt_wet: 1, gravel_dry: 0.9, mud: 0.8, snow: 0.7 } }

      expect(comparePart(rain, FITTED)!.verdict!.net).toBeGreaterThan(5)
      expect(comparePart(rain, FITTED)!.verdict).toMatchObject({ kind: 'mixed', improved: 4, worsened: 1 })
      expect(comparePart(FITTED, rain)!.verdict).toMatchObject({ kind: 'mixed', improved: 1, worsened: 4 })
      // Better grip does not make it better when the tires lose on a surface, and tires that gain on every surface are better.
      expect(comparePart({ stats: { grip: 120, handling: 110, braking: 120 }, surfaces: { ...FITTED.surfaces, mud: 0.3 } }, FITTED)!.verdict!.kind).toBe('mixed')
      expect(comparePart({ stats: { grip: 120, handling: 110, braking: 120 }, surfaces: { asphalt_dry: 1, asphalt_wet: 0.9, gravel_dry: 0.7, mud: 0.5, snow: 0.45 } }, FITTED)!.verdict!.kind).toBe('better')
    })

    it('counts the statistics where less is better the right way round, and does not let a small number outweigh the rest', () => {
      const fitted = { stats: { power_mult: 1.3, lag: 5, fuel_mult: 1.3, engine_load: 14 } }

      // More power for a little more lag, fuel and load: the lag of 3 against 5 would be a change of 40 %, and counts as at most 50 % of one statistic.
      expect(comparePart({ stats: { power_mult: 1.35, lag: 6, fuel_mult: 1.3, engine_load: 14 } }, fitted)!.verdict!.kind).toBe('mixed')
      expect(comparePart({ stats: { power_mult: 1.3, lag: 3, fuel_mult: 1.2, engine_load: 10 } }, fitted)!.verdict).toMatchObject({ kind: 'better', worsened: 0 })
    })

    it('is told in percent, to a tenth', () => {
      expect(comparePart({ stats: { grip: 99 } }, { stats: { grip: 90 } })!.verdict!.net).toBe(9.1)
    })
  })
})
