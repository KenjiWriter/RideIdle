import { formatFixed } from '@/i18n/format'
import type { AppLocale } from '@/i18n/locales'

/**
 * The units a game counts distances and speeds in. The server keeps ONE scale in every number it sends (metres, kilometres, km/h); the units of the game are only
 * what the app shows: the car game has metres, kilometres and km/h, a game of ships may have cables, nautical miles and knots.
 */

/** A text in the languages of the app: `{ en: 'metre', pl: 'metr' }`. */
export type LocalizedText = Readonly<Record<string, string>>

/** A unit of length: how many metres it is, and how many digits after the decimal separator it is shown with. */
export interface DistanceUnit {
  name: LocalizedText
  short: LocalizedText
  metres: number
  decimals: number
}

/** A unit of speed: how many km/h it is, and how many digits after the decimal separator it is shown with. */
export interface SpeedUnit {
  name: LocalizedText
  short: LocalizedText
  kmh: number
  decimals: number
}

/** Fuel use is shown per this many units of travel: 100 gives "l/100 km", 1 gives "l/nm". */
export type FuelEconomyPer = 1 | 10 | 100

export interface GameUnits {
  /** The length of a lap of a track. */
  lap: DistanceUnit
  /** The roads between the cities, the odometer, the service interval, the mileage of a car for sale. */
  travel: DistanceUnit
  speed: SpeedUnit
  fuel_economy_per: FuelEconomyPer
}

/** The units that are in force until the server says others, and where it says nothing (an older server): the car game's own. */
export const DEFAULT_UNITS: GameUnits = {
  lap: {
    name: { en: 'metre', pl: 'metr', fr: 'mètre', de: 'Meter', 'pt-BR': 'metro' },
    short: { en: 'm' },
    metres: 1,
    decimals: 0,
  },
  travel: {
    name: { en: 'kilometre', pl: 'kilometr', fr: 'kilomètre', de: 'Kilometer', 'pt-BR': 'quilômetro' },
    short: { en: 'km' },
    metres: 1000,
    decimals: 0,
  },
  speed: {
    name: { en: 'kilometre per hour', pl: 'kilometr na godzinę', fr: 'kilomètre par heure', de: 'Kilometer pro Stunde', 'pt-BR': 'quilômetro por hora' },
    short: { en: 'km/h' },
    kmh: 1,
    decimals: 0,
  },
  fuel_economy_per: 100,
}

/** The unit of speed as it is shown in the language of the driver: the short form, and what a km/h is in it (a game of ships counts knots). */
export interface ShownSpeedUnit {
  short: string
  kmh: number
  decimals: number
}

/** The speed of the car game, for what is not in a screen and has no game to ask. */
export const KMH: ShownSpeedUnit = { short: 'km/h', kmh: 1, decimals: 0 }

const ECONOMY_PER: readonly number[] = [1, 10, 100]

const MAX_DECIMALS = 4

function record(raw: unknown): Record<string, unknown> | null {
  return typeof raw === 'object' && raw !== null && !Array.isArray(raw) ? (raw as Record<string, unknown>) : null
}

/** The texts of a map language -> text that are texts (the rest is dropped), or null when there is none. */
function parseText(raw: unknown): LocalizedText | null {
  const map = record(raw)

  if (map === null) {
    return null
  }

  const texts: Record<string, string> = {}

  for (const [language, text] of Object.entries(map)) {
    if (typeof text === 'string' && text.trim() !== '') {
      texts[language] = text.trim()
    }
  }

  return Object.keys(texts).length > 0 ? texts : null
}

const positive = (raw: unknown): number | null => (typeof raw === 'number' && Number.isFinite(raw) && raw > 0 ? raw : null)

const decimalsOf = (raw: unknown, fallback: number): number => (typeof raw === 'number' && Number.isInteger(raw) && raw >= 0 && raw <= MAX_DECIMALS ? raw : fallback)

/** What a unit of the answer has in common, or null when it is not a unit (see `parseDistance`). */
function parseUnit(raw: unknown, factor: 'metres' | 'kmh', fallbackDecimals: number): { name: LocalizedText; short: LocalizedText; size: number; decimals: number } | null {
  const unit = record(raw)
  const short = parseText(unit?.short)
  const size = positive(unit?.[factor])

  if (unit === null || short === null || size === null) {
    return null
  }

  return { name: parseText(unit.name) ?? short, short, size, decimals: decimalsOf(unit.decimals, fallbackDecimals) }
}

/**
 * A unit is taken whole or not at all: a unit with no short form or with a factor that is not a positive number is the unit of the car game, because a label and a
 * factor that do not belong together would show a wrong number. Only the decimals (a matter of looks) and the name (the short form stands in for it) are mended apart.
 */
function parseDistance(raw: unknown, fallback: DistanceUnit): DistanceUnit {
  const unit = parseUnit(raw, 'metres', fallback.decimals)

  return unit === null ? fallback : { name: unit.name, short: unit.short, metres: unit.size, decimals: unit.decimals }
}

function parseSpeed(raw: unknown, fallback: SpeedUnit): SpeedUnit {
  const unit = parseUnit(raw, 'kmh', fallback.decimals)

  return unit === null ? fallback : { name: unit.name, short: unit.short, kmh: unit.size, decimals: unit.decimals }
}

/**
 * The units of a game from what the server answered (`units` of GET /api/v1/meta/game). It never breaks on a client that is older or newer than the server: what is
 * missing or is not what it should be is what the car game has.
 */
export function parseUnits(raw: unknown): GameUnits {
  const body = record(raw)

  if (body === null) {
    return DEFAULT_UNITS
  }

  return {
    lap: parseDistance(body.lap, DEFAULT_UNITS.lap),
    travel: parseDistance(body.travel, DEFAULT_UNITS.travel),
    speed: parseSpeed(body.speed, DEFAULT_UNITS.speed),
    fuel_economy_per: ECONOMY_PER.includes(body.fuel_economy_per as number) ? (body.fuel_economy_per as FuelEconomyPer) : DEFAULT_UNITS.fuel_economy_per,
  }
}

/** A text in the language of the driver, else in English, else in whichever language there is. */
export function pickText(text: LocalizedText, locale: AppLocale): string {
  return text[locale] ?? text.en ?? Object.values(text)[0] ?? ''
}

/** What stands between a number and its unit: a space that does not break the line in French, as the French messages have it, and a plain one elsewhere. */
function gap(locale: AppLocale): string {
  return locale === 'fr' ? ' ' : ' '
}

/** A distance given in metres, in the unit of the game, as a number (not rounded). */
export function distanceValue(units: GameUnits, kind: 'lap' | 'travel', metres: number): number {
  return metres / units[kind].metres
}

/** A speed given in km/h, in the unit of the game, as a number (not rounded). */
export function speedValue(units: GameUnits, kmh: number): number {
  return kmh / units.speed.kmh
}

/** A distance given in metres: the number in the unit of the game with its decimals, and the unit ("2 600 m", "20,5 nm"). */
export function formatDistance(units: GameUnits, kind: 'lap' | 'travel', metres: number, locale: AppLocale): string {
  const unit = units[kind]

  return `${formatFixed(distanceValue(units, kind, metres), locale, unit.decimals)}${gap(locale)}${pickText(unit.short, locale)}`
}

/** A speed given in km/h ("125 km/h", "67 kn"). */
export function formatSpeed(units: GameUnits, kmh: number, locale: AppLocale): string {
  return `${formatFixed(speedValue(units, kmh), locale, units.speed.decimals)}${gap(locale)}${pickText(units.speed.short, locale)}`
}

/**
 * The fuel a car burns on a length, in litres per `fuel_economy_per` units of travel, to a tenth ("57,7 l/100 km", "1,1 l/nm"). The fuel is in millilitres and the length
 * in metres. The litre sign stays `l` in every language, as it is in the other messages; "—" when there is no length to count by.
 */
export function formatFuelEconomy(units: GameUnits, fuelMl: number, lengthM: number, locale: AppLocale): string {
  const travelled = lengthM / units.travel.metres

  if (!(travelled > 0) || !Number.isFinite(fuelMl)) {
    return '—'
  }

  const per = units.fuel_economy_per
  const space = gap(locale)
  const perUnit = per === 1 ? pickText(units.travel.short, locale) : `${per}${space}${pickText(units.travel.short, locale)}`

  return `${formatFixed((fuelMl / 1000 / travelled) * per, locale, 1)}${space}l/${perUnit}`
}
