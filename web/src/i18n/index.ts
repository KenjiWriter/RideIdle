import { createI18n } from 'vue-i18n'

import en from './locales/en.json'
import { DEFAULT_LOCALE, detectLocale, normalizeLocale, type AppLocale } from './locales'
import { pluralRules } from './plural'

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
  pluralRules,
})

/** Słownik paczki: komunikaty, które paczka mówi po swojemu, po językach (język → klucz z kropkami → tekst). */
export type Vocabulary = Record<string, Record<string, string>>

let vocabulary: Vocabulary = {}

/** Klucz z kropkami (slot.tires) jako drzewo komunikatów ({ slot: { tires } }), jakie ma plik języka. */
function nested(flat: Record<string, string>): Record<string, unknown> {
  const tree: Record<string, unknown> = {}

  for (const [key, text] of Object.entries(flat)) {
    const path = key.split('.')
    let node = tree

    for (const part of path.slice(0, -1)) {
      if (typeof node[part] !== 'object' || node[part] === null) {
        node[part] = {}
      }

      node = node[part] as Record<string, unknown>
    }

    node[path[path.length - 1]] = text
  }

  return tree
}

/** Nakłada słownik na teksty języka, o ile język jest już załadowany. */
function mergeVocabulary(locale: AppLocale): void {
  const flat = vocabulary[locale]

  if (flat && Object.keys(flat).length > 0) {
    i18n.global.mergeLocaleMessage(locale, nested(flat))
  }
}

/**
 * Ustawia słownik paczki: słowa, które paczka mówi inaczej niż aplikacja, nakładają się na jej teksty w załadowanych językach i w tych, które załadują się potem.
 * Gdzie paczka nic nie mówi, zostaje tekst aplikacji.
 */
export function setVocabulary(next: Vocabulary): void {
  vocabulary = next

  for (const locale of i18n.global.availableLocales) {
    mergeVocabulary(locale as AppLocale)
  }
}

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
  mergeVocabulary(locale)
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

/** Język na starcie aplikacji: zapisany wybór albo język przeglądarki, a gdy żaden nie pasuje, język domyślny gry (`fallback`). */
export async function initLocale(fallback?: string | null): Promise<AppLocale> {
  let stored: string | null = null

  try {
    stored = localStorage.getItem(STORAGE_KEY)
  } catch {
    stored = null
  }

  const locale = detectLocale(stored, navigator.languages ?? [navigator.language], fallback)
  await setLocale(locale)

  return locale
}
