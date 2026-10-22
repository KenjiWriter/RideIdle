import { createI18n } from 'vue-i18n'

import en from './locales/en.json'
import { DEFAULT_LOCALE, detectLocale, normalizeLocale, type AppLocale } from './locales'

type MessageSchema = typeof en

const STORAGE_KEY = 'ride.locale'

// Angielski jest zapasowy, więc ładuje się razem z aplikacją. Pozostałe języki
// ładują się dopiero po wyborze, jako osobne pliki.
const lazyMessages = import.meta.glob<{ default: MessageSchema }>(['./locales/*.json', '!./locales/en.json'])

export const i18n = createI18n({
  legacy: false,
  locale: DEFAULT_LOCALE,
  fallbackLocale: DEFAULT_LOCALE,
  messages: { en } as Partial<Record<AppLocale, MessageSchema>>,
})

export function currentLocale(): AppLocale {
  return normalizeLocale(i18n.global.locale.value) ?? DEFAULT_LOCALE
}

async function ensureLoaded(locale: AppLocale): Promise<void> {
  if (i18n.global.availableLocales.includes(locale)) {
    return
  }

  const loader = lazyMessages[`./locales/${locale}.json`]
  if (!loader) {
    return
  }

  i18n.global.setLocaleMessage(locale, (await loader()).default)
}

/** Przełącza język interfejsu, zapamiętuje wybór i ustawia atrybut lang na stronie. */
export async function setLocale(locale: AppLocale): Promise<void> {
  await ensureLoaded(locale)

  i18n.global.locale.value = locale
  document.documentElement.lang = locale

  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // Tryb prywatny lub zablokowany magazyn: język działa, tylko się nie zapamięta.
  }
}

/** Język na starcie aplikacji: zapisany wybór albo język przeglądarki. */
export async function initLocale(): Promise<AppLocale> {
  let stored: string | null = null

  try {
    stored = localStorage.getItem(STORAGE_KEY)
  } catch {
    stored = null
  }

  const locale = detectLocale(stored, navigator.languages ?? [navigator.language])
  await setLocale(locale)

  return locale
}
