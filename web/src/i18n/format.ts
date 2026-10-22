import type { AppLocale } from './locales'

/**
 * Gotówka w grze jest zapisana w centach (liczba całkowita), a wyświetlana
 * w dolarach, w formacie języka gracza.
 */
export function formatMoney(cents: number, locale: AppLocale): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: 'USD',
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: 0,
  }).format(Math.round(cents / 100))
}

export function formatNumber(value: number, locale: AppLocale, maximumFractionDigits = 0): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits }).format(value)
}

/** Czas trwania w ms jako „1 h 05 min” lub „12 min 30 s”, bez zależności od języka. */
export function formatDuration(ms: number): string {
  const totalSeconds = Math.max(0, Math.floor(ms / 1000))
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60

  if (hours > 0) {
    return `${hours} h ${String(minutes).padStart(2, '0')} min`
  }

  return `${minutes} min ${String(seconds).padStart(2, '0')} s`
}
