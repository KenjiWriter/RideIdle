import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { setGame } from '@/game'
import { i18n, setLocale } from '@/i18n'
import { aShipsGame } from '@/test/unitsFixtures'

import PartStats from './PartStats.vue'

const show = (part: InstanceType<typeof PartStats>['$props']['part']) => mount(PartStats, { props: { part }, global: { plugins: [i18n] } })

const rainTires = {
  stats: { grip: 96, handling: 95, braking: 97 },
  surfaces: { asphalt_dry: 0.9, asphalt_wet: 1.05, gravel_dry: 0.55, mud: 0.5, snow: 0.55 },
}

describe('PartStats', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  afterEach(() => {
    setGame()
  })

  it('says what the part does in words, with the power as a percent', () => {
    const wrapper = show({ stats: { power_mult: 1.02, clearance: 25 } })

    expect(wrapper.findAll('[data-test="part-stats"] li').map((line) => line.text())).toEqual(['Power +2%', 'Off-road 25'])
  })

  it('shows how a tire grips on every surface, and brightens the ones it is at least as good as dry asphalt on', () => {
    const wrapper = show(rainTires)

    expect(wrapper.findAll('[data-test="part-surfaces"] li').map((line) => line.text())).toEqual(['Dry ×0.90', 'Wet ×1.05', 'Gravel ×0.55', 'Mud ×0.50', 'Snow ×0.55'])
    expect(wrapper.get('[data-test="surface-asphalt_wet"]').classes()).toContain('text-cyan')
    expect(wrapper.get('[data-test="surface-asphalt_dry"]').classes()).not.toContain('text-cyan')
  })

  it('shows no surfaces for a part that is not a tire', () => {
    expect(show({ stats: { power_mult: 1.02 }, surfaces: null }).find('[data-test="part-surfaces"]').exists()).toBe(false)
    expect(show({ stats: { power_mult: 1.02 } }).find('[data-test="part-surfaces"]').exists()).toBe(false)
  })

  it('speaks the language of the driver, with the number as the language writes it', async () => {
    await setLocale('pl')

    expect(
      show(rainTires)
        .findAll('[data-test="part-surfaces"] li')
        .map((line) => line.text()),
    ).toEqual(['Sucho ×0,90', 'Mokro ×1,05', 'Żwir ×0,55', 'Błoto ×0,50', 'Śnieg ×0,55'])
  })

  it('shows the statistics of a rarer part to a tenth, as a rarity makes them fractions', () => {
    const wrapper = show({ stats: { grip: 138, handling: 135.24, braking: 138, power_mult: 1.0444 } })

    expect(wrapper.findAll('[data-test="part-stats"] li').map((line) => line.text())).toEqual(['Grip 138', 'Handling 135.2', 'Braking 138', 'Power +4.4%'])
  })

  describe('the engine group', () => {
    const lines = (part: InstanceType<typeof PartStats>['$props']['part']) => show(part).findAll('[data-test="part-stats"] li').map((line) => line.text())

    it('says what a booster does: the power it gains, its lag, its fuel and the load it puts on the engine', () => {
      expect(lines({ stats: { power_mult: 1.25, lag: 3, fuel_mult: 1.15, engine_load: 8 } })).toEqual(['Power +25%', 'Lag 3/10', 'Fuel ×1.15', 'Engine load +8'])
    })

    it('says what an intercooler and a radiator do, with the sign of the load and of the mass', () => {
      expect(lines({ stats: { boost_gain: 0.02, engine_load: -4, mass_kg: 5 } })).toEqual(['Boost gain +2%', 'Engine load −4', 'Mass +5 kg'])
      expect(lines({ stats: { engine_load: -3, mass_kg: -3 } })).toEqual(['Engine load −3', 'Mass −3 kg'])
    })

    it('says what a gearbox does: the gears, the limit of speed and the launch, and says nothing of a launch that is none', () => {
      expect(lines({ stats: { gears: 6, top_speed_cap_kmh: 240, launch: 2 } })).toEqual(['6 gears', 'Limit 240 km/h', 'Launch +2%'])
      expect(lines({ stats: { gears: 5, top_speed_cap_kmh: 190, launch: 0 } })).toEqual(['5 gears', 'Limit 190 km/h'])
      expect(lines({ stats: { gears: 6, launch: -2 } })).toEqual(['6 gears', 'Launch −2%'])
    })

    it('says the limit of a gearbox in the unit of speed of the game: 190 km/h are 103 knots', () => {
      setGame(aShipsGame())

      expect(lines({ stats: { gears: 5, top_speed_cap_kmh: 190, launch: 0 } })).toEqual(['5 gears', 'Limit 103 kn'])
    })

    it('says nothing of the lag of a supercharger that answers at once', () => {
      expect(lines({ stats: { power_mult: 1.12, lag: 0, fuel_mult: 1.1, engine_load: 4 } })).toEqual(['Power +12%', 'Fuel ×1.10', 'Engine load +4'])
    })

    it('writes the numbers the way the language does', async () => {
      await setLocale('pl')

      expect(lines({ stats: { power_mult: 1.25, lag: 3, fuel_mult: 1.15, engine_load: 8 } })).toEqual(['Moc +25%', 'Opóźnienie 3/10', 'Spalanie ×1,15', 'Obciążenie silnika +8'])
    })

    it('says what an engine is first, with the litres to a decimal, then its power and its mass', () => {
      expect(lines({ engine: { layout: 'R6', displacement_l: 3 }, stats: { power_kw: 255.75, engine_mass_kg: 204 } })).toEqual(['R6 3.0', 'Power 256 kW', 'Engine mass 204 kg'])
      expect(lines({ engine: { layout: 'R4', displacement_l: 2.5 }, stats: { power_kw: 162.5, engine_mass_kg: 117.5 } })).toEqual(['R4 2.5', 'Power 163 kW', 'Engine mass 117.5 kg'])
    })

    it('writes an engine in the language of the interface', async () => {
      await setLocale('pl')

      expect(lines({ engine: { layout: 'V8', displacement_l: 4.5 }, stats: { power_kw: 352.69, engine_mass_kg: 299 } })).toEqual(['V8 4.5', 'Moc 353 kW', 'Masa silnika 299 kg'])
    })

    it('says nothing of an engine for a part that is none', () => {
      expect(lines({ engine: null, stats: { power_mult: 1.12 } })).toEqual(['Power +12%'])
    })

    it('says what the car must have for the part, and nothing for a part that needs nothing', () => {
      expect(show({ stats: { power_mult: 1.4 }, requires: { min_cylinders: 6 } }).get('[data-test="part-requires"]').text()).toBe('Needs an engine with at least 6 cylinders')
      expect(show({ stats: { boost_gain: 0.02 }, requires: { booster: true } }).get('[data-test="part-requires"]').text()).toBe('Works only with a booster')
      expect(show({ stats: { power_mult: 1.12 }, requires: null }).find('[data-test="part-requires"]').exists()).toBe(false)
      expect(show({ stats: { power_mult: 1.12 } }).find('[data-test="part-requires"]').exists()).toBe(false)
    })
  })
})
