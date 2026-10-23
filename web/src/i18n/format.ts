import type { AppLocale } from './locales'

/**
 * Gotówka w grze jest zapisana w centach (liczba całkowita), a wyświetlana
 * w dolarach, w formacie języka gracza. Domyślnie w pełnych dolarach; nagroda
 * za okrążenie ($15,50) potrzebuje centów, więc można podać `fractionDigits`.
 */
export function formatMoney(cents: number, locale: AppLocale, fractionDigits = 0): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: 'USD',
    currencyDisplay: 'narrowSymbol',
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(fractionDigits === 0 ? Math.round(cents / 100) : cents / 100)
}

/**
 * A day as the language writes it ("23 października 2026"). The version of the legal texts is a day such as
 * 2026-10-23; a text that is not one is returned as it is.
 */
export function formatDay(value: string, locale: AppLocale): string {
  const day = new Date(`${value}T00:00:00Z`)

  if (Number.isNaN(day.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat(locale, { dateStyle: 'long', timeZone: 'UTC' }).format(day)
}

export function formatNumber(value: number, locale: AppLocale, maximumFractionDigits = 0): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits }).format(value)
}

/** Czas okrążenia w ms jako „1:07.0”: minuty, sekundy i dziesiąte, w zapisie niezależnym od języka. */
export function formatLapTime(ms: number): string {
  const tenths = Math.max(0, Math.round(ms / 100))
  const minutes = Math.floor(tenths / 600)
  const seconds = Math.floor((tenths % 600) / 10)

  return `${minutes}:${String(seconds).padStart(2, '0')}.${tenths % 10}`
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
