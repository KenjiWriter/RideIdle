/**
 * Języki obsługiwane przez aplikację. Kody są takie same jak w API (config/ride.php):
 * pl, en, fr, de, pt-BR. Skrót "br" to alias brazylijskiego portugalskiego.
 */
export const SUPPORTED_LOCALES = ['pl', 'en', 'fr', 'de', 'pt-BR'] as const

export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: AppLocale = 'en'

export const LOCALE_NAMES: Record<AppLocale, string> = {
  pl: 'Polski',
  en: 'English',
  fr: 'Français',
  de: 'Deutsch',
  'pt-BR': 'Português (Brasil)',
}

const ALIASES: Record<string, AppLocale> = {
  br: 'pt-BR',
  pt: 'pt-BR',
  'pt-br': 'pt-BR',
  'en-us': 'en',
  'en-gb': 'en',
}

/** Zamienia dowolny zapis (fr-CH, PT_br, br) na obsługiwany kod albo null. */
export function normalizeLocale(input: string | null | undefined): AppLocale | null {
  if (!input) {
    return null
  }

  const key = input.trim().replace('_', '-').toLowerCase()

  if (key === '' || key === '*') {
    return null
  }

  const alias = ALIASES[key]
  if (alias) {
    return alias
  }

  const exact = SUPPORTED_LOCALES.find((locale) => locale.toLowerCase() === key)
  if (exact) {
    return exact
  }

  const primary = key.split('-')[0] ?? ''

  return ALIASES[primary] ?? SUPPORTED_LOCALES.find((locale) => locale.toLowerCase() === primary) ?? null
}

/**
 * Wybór języka na starcie: zapisany wybór gracza, potem języki przeglądarki
 * w kolejności preferencji, na końcu język domyślny.
 */
export function detectLocale(stored: string | null, browserLanguages: readonly string[]): AppLocale {
  const fromStorage = normalizeLocale(stored)
  if (fromStorage) {
    return fromStorage
  }

  for (const language of browserLanguages) {
    const locale = normalizeLocale(language)
    if (locale) {
      return locale
    }
  }

  return DEFAULT_LOCALE
}
