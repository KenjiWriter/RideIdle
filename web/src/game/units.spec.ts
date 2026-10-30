import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'

import { i18n, setLocale } from '@/i18n'

import { DEFAULT_GAME, setGame, type GameInfo } from './index'
import {
  DEFAULT_UNITS,
  KMH,
  distanceValue,
  formatDistance,
  formatFuelEconomy,
  formatSpeed,
  parseUnits,
  pickText,
  speedValue,
  type GameUnits,
} from './units'
import { useUnits } from './useUnits'

/** The units of a game of ships, as the server sends them: cables for a lap, nautical miles for the sea, knots for the speed. */
const NAUTICAL = {
  lap: { name: { en: 'cable', pl: 'kabel' }, short: { en: 'cb' }, metres: 185.2, decimals: 1 },
  travel: { name: { en: 'nautical mile', pl: 'mila morska' }, short: { en: 'nm', fr: 'nd' }, metres: 1852, decimals: 1 },
  speed: { name: { en: 'knot', pl: 'wezel' }, short: { en: 'kn', pl: 'w' }, kmh: 1.852, decimals: 0 },
  fuel_economy_per: 1,
}

const nautical: GameUnits = parseUnits(NAUTICAL)

describe('the default units', () => {
  it('are the car game’s: metres for a lap, kilometres for the roads, km/h for the speed, and fuel per 100 km', () => {
    expect(DEFAULT_UNITS.lap).toMatchObject({ short: { en: 'm' }, metres: 1, decimals: 0 })
    expect(DEFAULT_UNITS.travel).toMatchObject({ short: { en: 'km' }, metres: 1000, decimals: 0 })
    expect(DEFAULT_UNITS.speed).toMatchObject({ short: { en: 'km/h' }, kmh: 1, decimals: 0 })
    expect(DEFAULT_UNITS.fuel_economy_per).toBe(100)
    expect(DEFAULT_UNITS.travel.name).toMatchObject({ en: 'kilometre', pl: 'kilometr', fr: 'kilomètre', de: 'Kilometer', 'pt-BR': 'quilômetro' })
  })

  it('have the same short forms in every language', () => {
    for (const locale of ['pl', 'en', 'fr', 'de', 'pt-BR'] as const) {
      expect([pickText(DEFAULT_UNITS.lap.short, locale), pickText(DEFAULT_UNITS.travel.short, locale), pickText(DEFAULT_UNITS.speed.short, locale)]).toEqual(['m', 'km', 'km/h'])
    }
  })
})

describe('parseUnits', () => {
  it('reads the units of a game as they are', () => {
    expect(nautical).toEqual(NAUTICAL)
  })

  it('reads the units of the car game as the server sends them', () => {
    const sent = {
      lap: { name: { en: 'metre', pl: 'metr' }, short: { en: 'm', pl: 'm' }, metres: 1, decimals: 0 },
      travel: { name: { en: 'kilometre', pl: 'kilometr' }, short: { en: 'km', pl: 'km' }, metres: 1000, decimals: 0 },
      speed: { name: { en: 'kilometre per hour' }, short: { en: 'km/h' }, kmh: 1, decimals: 0 },
      fuel_economy_per: 100,
    }

    expect(parseUnits(sent)).toEqual(sent)
    expect(formatDistance(parseUnits(sent), 'lap', 2600, 'pl')).toBe('2600 m')
    expect(formatSpeed(parseUnits(sent), 125, 'en')).toBe('125 km/h')
  })

  it('is the car game’s units for an answer that has none (an older server) or is not an object', () => {
    for (const raw of [undefined, null, 'ships', 7, true, [], [NAUTICAL]]) {
      expect(parseUnits(raw)).toEqual(DEFAULT_UNITS)
    }

    expect(parseUnits({})).toEqual(DEFAULT_UNITS)
  })

  it('mends a unit that is not what it should be with the unit of the car game, and leaves the rest alone', () => {
    const broken = parseUnits({ ...NAUTICAL, travel: 'nm', speed: { short: {}, kmh: 1.852 } })

    expect(broken.travel).toEqual(DEFAULT_UNITS.travel)
    expect(broken.speed).toEqual(DEFAULT_UNITS.speed)
    expect(broken.lap).toEqual(NAUTICAL.lap)
    expect(broken.fuel_economy_per).toBe(1)
  })

  it('does not take a factor that is not a positive finite number: a label and a factor that do not belong together would show a wrong number', () => {
    for (const bad of [0, -1852, NaN, Infinity, '1852', null, undefined, {}]) {
      expect(parseUnits({ ...NAUTICAL, travel: { ...NAUTICAL.travel, metres: bad } }).travel).toEqual(DEFAULT_UNITS.travel)
      expect(parseUnits({ ...NAUTICAL, speed: { ...NAUTICAL.speed, kmh: bad } }).speed).toEqual(DEFAULT_UNITS.speed)
    }
  })

  it('does not take a unit without a short form', () => {
    for (const short of [undefined, null, '', 'nm', [], {}, { en: '' }, { en: '  ' }, { en: 4 }]) {
      expect(parseUnits({ ...NAUTICAL, travel: { ...NAUTICAL.travel, short } }).travel).toEqual(DEFAULT_UNITS.travel)
    }
  })

  it('keeps the decimals when they are a whole number from 0 to 4, else takes those of the car game’s unit', () => {
    for (const decimals of [0, 1, 2, 3, 4]) {
      expect(parseUnits({ ...NAUTICAL, lap: { ...NAUTICAL.lap, decimals } }).lap.decimals).toBe(decimals)
    }

    for (const decimals of [-1, 5, 1.5, NaN, '1', null, undefined]) {
      expect(parseUnits({ ...NAUTICAL, lap: { ...NAUTICAL.lap, decimals } }).lap).toMatchObject({ metres: 185.2, decimals: 0 })
    }
  })

  it('takes the short form for the name where the name is missing, and drops the texts that are not texts', () => {
    const unit = parseUnits({ ...NAUTICAL, travel: { short: { en: ' nm ', pl: 5, fr: '' }, metres: 1852 } }).travel

    expect(unit.short).toEqual({ en: 'nm' })
    expect(unit.name).toEqual({ en: 'nm' })
    expect(parseUnits({ ...NAUTICAL, travel: { ...NAUTICAL.travel, name: { en: 'mile', de: 3 } } }).travel.name).toEqual({ en: 'mile' })
  })

  it('takes a fuel economy per 1, 10 or 100 units of travel and no other', () => {
    for (const per of [1, 10, 100]) {
      expect(parseUnits({ fuel_economy_per: per }).fuel_economy_per).toBe(per)
    }

    for (const per of [0, 5, 1000, -1, '100', null, undefined, 100.5]) {
      expect(parseUnits({ fuel_economy_per: per }).fuel_economy_per).toBe(100)
    }
  })
})

describe('pickText', () => {
  it('is the text in the language, else in English, else in whichever language there is, else nothing', () => {
    expect(pickText({ en: 'metre', pl: 'metr' }, 'pl')).toBe('metr')
    expect(pickText({ en: 'metre', pl: 'metr' }, 'de')).toBe('metre')
    expect(pickText({ pl: 'metr' }, 'de')).toBe('metr')
    expect(pickText({}, 'de')).toBe('')
  })
})

describe('the numbers', () => {
  it('are the distance and the speed in the unit of the game, not rounded', () => {
    expect(distanceValue(DEFAULT_UNITS, 'lap', 2600)).toBe(2600)
    expect(distanceValue(DEFAULT_UNITS, 'travel', 38_000)).toBe(38)
    expect(distanceValue(nautical, 'travel', 18_520)).toBeCloseTo(10)
    expect(distanceValue(nautical, 'lap', 1852)).toBeCloseTo(10)
    expect(speedValue(DEFAULT_UNITS, 125)).toBe(125)
    expect(speedValue(nautical, 18.52)).toBeCloseTo(10)
  })
})

describe('the formatters', () => {
  it('write a distance with the decimals and the short form of the unit', () => {
    expect(formatDistance(DEFAULT_UNITS, 'lap', 2600, 'en')).toBe('2,600 m')
    expect(formatDistance(DEFAULT_UNITS, 'travel', 38_000, 'en')).toBe('38 km')
    expect(formatDistance(DEFAULT_UNITS, 'travel', 187_420_000, 'en')).toBe('187,420 km')
    expect(formatDistance(nautical, 'travel', 38_000, 'en')).toBe('20.5 nm')
    expect(formatDistance(nautical, 'lap', 2600, 'en')).toBe('14.0 cb')
  })

  it('write a speed in km/h or in the unit of the game', () => {
    expect(formatSpeed(DEFAULT_UNITS, 125, 'en')).toBe('125 km/h')
    expect(formatSpeed(DEFAULT_UNITS, 124.6, 'en')).toBe('125 km/h')
    expect(formatSpeed(nautical, 124.084, 'en')).toBe('67 kn')
    expect(formatSpeed(nautical, 0, 'en')).toBe('0 kn')
  })

  it('write the fuel per 100 kilometres to a tenth: 1500 ml over 2600 m is 57.7 l/100 km', () => {
    expect(formatFuelEconomy(DEFAULT_UNITS, 1500, 2600, 'en')).toBe('57.7 l/100 km')
    expect(formatFuelEconomy(DEFAULT_UNITS, 1000, 1000, 'en')).toBe('100.0 l/100 km')
    expect(formatFuelEconomy({ ...DEFAULT_UNITS, fuel_economy_per: 10 }, 1500, 2600, 'en')).toBe('5.8 l/10 km')
  })

  it('write the fuel per unit of travel when that is 1: 1500 ml over 2600 m is 1.1 l/nm', () => {
    expect(formatFuelEconomy(nautical, 1500, 2600, 'en')).toBe('1.1 l/nm')
    expect(formatFuelEconomy({ ...DEFAULT_UNITS, fuel_economy_per: 1 }, 1500, 2600, 'en')).toBe('0.6 l/km')
  })

  it('write a dash where there is no length to count the fuel by', () => {
    expect(formatFuelEconomy(DEFAULT_UNITS, 1500, 0, 'en')).toBe('—')
    expect(formatFuelEconomy(DEFAULT_UNITS, 1500, -5, 'en')).toBe('—')
    expect(formatFuelEconomy(DEFAULT_UNITS, 1500, NaN, 'en')).toBe('—')
    expect(formatFuelEconomy(DEFAULT_UNITS, NaN, 2600, 'en')).toBe('—')
  })

  it('write the numbers the way the language does: a comma and a space in Polish, French and German, a no-break space before the unit in French', () => {
    expect(formatDistance(DEFAULT_UNITS, 'lap', 2600, 'pl')).toBe('2600 m')
    expect(formatDistance(DEFAULT_UNITS, 'travel', 187_420_000, 'pl')).toBe('187\u00A0420 km')
    expect(formatDistance(nautical, 'travel', 38_000, 'pl')).toBe('20,5 nm')
    expect(formatDistance(DEFAULT_UNITS, 'lap', 2600, 'fr')).toBe('2\u202F600\u00A0m')
    expect(formatDistance(nautical, 'travel', 38_000, 'fr')).toBe('20,5\u00A0nd')
    expect(formatDistance(DEFAULT_UNITS, 'lap', 2600, 'de')).toBe('2.600 m')
    expect(formatDistance(nautical, 'travel', 38_000, 'pt-BR')).toBe('20,5 nm')
    expect(formatSpeed(DEFAULT_UNITS, 125, 'fr')).toBe('125\u00A0km/h')
    expect(formatFuelEconomy(DEFAULT_UNITS, 1500, 2600, 'pl')).toBe('57,7 l/100 km')
    expect(formatFuelEconomy(DEFAULT_UNITS, 1500, 2600, 'fr')).toBe('57,7\u00A0l/100\u00A0km')
    expect(formatFuelEconomy(nautical, 1500, 2600, 'de')).toBe('1,1 l/nm')
  })

  it('take the short form in the language of the driver, else the English one', () => {
    expect(formatSpeed(nautical, 124.084, 'pl')).toBe('67 w')
    expect(formatSpeed(nautical, 124.084, 'de')).toBe('67 kn')
    expect(formatFuelEconomy(nautical, 1500, 2600, 'fr')).toBe('1,1\u00A0l/nd')
  })
})

describe('useUnits', () => {
  const ships: GameInfo = { ...DEFAULT_GAME, slug: 'ships', name: 'Star Ships', is_default: false, units: nautical }

  /** The formatters of a component that is set up, as a screen has them. */
  function inScreen() {
    let units!: ReturnType<typeof useUnits>
    const Probe = defineComponent({
      setup() {
        units = useUnits()

        return () => h('div')
      },
    })

    mount(Probe, { global: { plugins: [i18n] } })

    return units
  }

  beforeEach(async () => {
    await setLocale('en')
  })

  afterEach(() => {
    setGame()
  })

  it('is the car game’s units until a game says others', () => {
    const { distance, distanceKm, speed, fuelEconomy, distanceValue: value, speedValue: speedNumber, speedUnit } = inScreen()

    expect(distance('lap', 2600)).toBe('2,600 m')
    expect(distance('travel', 38_000)).toBe('38 km')
    expect(distanceKm(38)).toBe('38 km')
    expect(speed(125)).toBe('125 km/h')
    expect(fuelEconomy(1500, 2600)).toBe('57.7 l/100 km')
    expect(value('travel', 38_000)).toBe(38)
    expect(speedNumber(125)).toBe(125)
    expect(speedUnit.value).toEqual(KMH)
  })

  it('follows the game that is in force', () => {
    const { distance, distanceKm, speed, fuelEconomy, speedUnit } = inScreen()

    setGame(ships)

    expect(distance('lap', 2600)).toBe('14.0 cb')
    expect(distance('travel', 38_000)).toBe('20.5 nm')
    expect(distanceKm(38)).toBe('20.5 nm')
    expect(speed(124.084)).toBe('67 kn')
    expect(fuelEconomy(1500, 2600)).toBe('1.1 l/nm')
    expect(speedUnit.value).toEqual({ short: 'kn', kmh: 1.852, decimals: 0 })

    setGame()

    expect(distance('travel', 38_000)).toBe('38 km')
  })

  it('follows the language of the interface', async () => {
    const { distance, speed, fuelEconomy, speedUnit } = inScreen()

    setGame(ships)
    await setLocale('pl')

    expect(distance('travel', 38_000)).toBe('20,5 nm')
    expect(speed(124.084)).toBe('67 w')
    expect(speedUnit.value.short).toBe('w')

    await setLocale('fr')

    expect(distance('travel', 38_000)).toBe('20,5\u00A0nd')
    expect(fuelEconomy(1500, 2600)).toBe('1,1\u00A0l/nd')
  })
})
