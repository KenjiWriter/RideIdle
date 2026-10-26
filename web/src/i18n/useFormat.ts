import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { formatDay, formatFixed, formatMoney, formatNumber } from './format'
import { DEFAULT_LOCALE, normalizeLocale } from './locales'

/** Money, numbers and days in the language of the interface, which follows it when the driver switches the language. */
export function useFormat() {
  const { locale } = useI18n()
  const current = computed(() => normalizeLocale(locale.value) ?? DEFAULT_LOCALE)

  return {
    money: (cents: number, fractionDigits = 0): string => formatMoney(cents, current.value, fractionDigits),
    number: (value: number, fractionDigits = 0): string => formatNumber(value, current.value, fractionDigits),
    fixed: (value: number, fractionDigits: number): string => formatFixed(value, current.value, fractionDigits),
    /** A chance in percent: whole when it is, with one decimal when it is not (4.5). */
    percent: (value: number): string => (Number.isInteger(value) ? formatNumber(value, current.value, 0) : formatFixed(value, current.value, 1)),
    day: (value: string): string => formatDay(value, current.value),
  }
}
