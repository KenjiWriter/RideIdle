import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { DEFAULT_LOCALE, normalizeLocale } from '@/i18n/locales'

import { useGame } from './index'
import { distanceValue, formatDistance, formatFuelEconomy, formatSpeed, pickText, speedValue, type GameUnits, type ShownSpeedUnit } from './units'

/**
 * Distances, speeds and fuel use in the units of the game that is in force, in the language of the interface: both follow when they change. The numbers the server
 * sends stay in metres, kilometres and km/h; the screens convert them here and nowhere else.
 */
export function useUnits() {
  const { locale } = useI18n()
  const { game } = useGame()
  const current = computed(() => normalizeLocale(locale.value) ?? DEFAULT_LOCALE)
  const units = computed<GameUnits>(() => game.value.units)

  return {
    units,
    /** A length in metres, in the unit of a lap (`lap`) or of the roads, the odometer and the service (`travel`): "2 600 m", "38 km", "20,5 nm". */
    distance: (kind: 'lap' | 'travel', metres: number): string => formatDistance(units.value, kind, metres, current.value),
    /** A distance of travel that the server gives in kilometres (the mileage, the interval of the service, a road), in the unit of travel. */
    distanceKm: (km: number): string => formatDistance(units.value, 'travel', km * 1000, current.value),
    /** A speed in km/h: "125 km/h", "67 kn". */
    speed: (kmh: number): string => formatSpeed(units.value, kmh, current.value),
    /** The fuel of a length (millilitres, metres) in litres per `fuel_economy_per` units of travel: "57,7 l/100 km". */
    fuelEconomy: (fuelMl: number, lengthM: number): string => formatFuelEconomy(units.value, fuelMl, lengthM, current.value),
    /** The numbers, not rounded and without the unit: for what is compared or drawn and not written. */
    distanceValue: (kind: 'lap' | 'travel', metres: number): number => distanceValue(units.value, kind, metres),
    speedValue: (kmh: number): number => speedValue(units.value, kmh),
    /** The unit of speed in the language of the driver. */
    speedUnit: computed<ShownSpeedUnit>(() => ({ short: pickText(units.value.speed.short, current.value), kmh: units.value.speed.kmh, decimals: units.value.speed.decimals })),
  }
}
