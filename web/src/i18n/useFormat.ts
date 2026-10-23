import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { formatMoney, formatNumber } from './format'
import { DEFAULT_LOCALE, normalizeLocale } from './locales'

/** Money and numbers in the language of the interface, which follows it when the driver switches the language. */
export function useFormat() {
  const { locale } = useI18n()
  const current = computed(() => normalizeLocale(locale.value) ?? DEFAULT_LOCALE)

  return {
    money: (cents: number, fractionDigits = 0): string => formatMoney(cents, current.value, fractionDigits),
    number: (value: number, fractionDigits = 0): string => formatNumber(value, current.value, fractionDigits),
  }
}
