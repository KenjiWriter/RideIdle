import { DEFAULT_LOCALE, type AppLocale } from '@/i18n/locales'

import type { LegalTexts } from './types'

// The documents are long and read rarely, so each language is its own file, loaded when it is needed.
const files = import.meta.glob<{ default: LegalTexts }>('./content/*.ts')

/** The terms and the privacy policy in a language, or in English when there is no file for it. */
export async function loadLegalTexts(locale: AppLocale): Promise<LegalTexts> {
  const load = files[`./content/${locale}.ts`] ?? files[`./content/${DEFAULT_LOCALE}.ts`]

  if (!load) {
    throw new Error(`There are no legal texts, not even in ${DEFAULT_LOCALE}.`)
  }

  return (await load()).default
}
