import { describe, expect, it } from 'vitest'

import { SUPPORTED_LOCALES } from '@/i18n/locales'

import { LEGAL_PLACEHOLDERS, placeholdersOf } from './fill'
import { loadLegalTexts } from './texts'
import type { LegalDocument, LegalDocumentName, LegalTexts } from './types'

const files = import.meta.glob<{ default: LegalTexts }>('./content/*.ts', { eager: true })

const texts: Record<string, LegalTexts> = Object.fromEntries(
  Object.entries(files).map(([path, module]) => [path.replace('./content/', '').replace('.ts', ''), module.default]),
)

const DOCUMENTS: LegalDocumentName[] = ['terms', 'privacy']

function document(locale: string, name: LegalDocumentName): LegalDocument {
  const found = texts[locale]?.[name]

  if (!found) {
    throw new Error(`No ${name} in ${locale}`)
  }

  return found
}

/** Everything a reader sees in a document, one string each. */
function strings(doc: LegalDocument): string[] {
  return [doc.title, doc.intro, ...doc.sections.flatMap((section) => [section.heading, ...section.paragraphs])]
}

describe('the legal texts', () => {
  it('exist for every supported language and nothing else', () => {
    expect(Object.keys(texts).sort()).toEqual([...SUPPORTED_LOCALES].sort())
  })

  describe.each(DOCUMENTS)('the %s', (name) => {
    const english = () => document('en', name)

    it.each([...SUPPORTED_LOCALES])('in %s has the sections of the English text, in the same order', (locale) => {
      expect(document(locale, name).sections.map((section) => section.id)).toEqual(english().sections.map((section) => section.id))
    })

    it.each([...SUPPORTED_LOCALES])('in %s has as many paragraphs in every section as the English text', (locale) => {
      const counts = (doc: LegalDocument) => doc.sections.map((section) => `${section.id}:${section.paragraphs.length}`)

      expect(counts(document(locale, name))).toEqual(counts(english()))
    })

    it.each([...SUPPORTED_LOCALES])('in %s says the same numbers in the same places as the English text', (locale) => {
      const placed = (doc: LegalDocument) => doc.sections.map((section) => section.paragraphs.map((paragraph) => placeholdersOf(paragraph).sort()))

      expect(placed(document(locale, name))).toEqual(placed(english()))
    })

    it.each([...SUPPORTED_LOCALES])('in %s has no empty text and uses only the numbers the page knows', (locale) => {
      for (const text of strings(document(locale, name))) {
        expect(text.trim(), `${locale}: an empty text`).not.toBe('')

        for (const placeholder of placeholdersOf(text)) {
          expect(LEGAL_PLACEHOLDERS.has(placeholder), `${locale}: {${placeholder}} is not known`).toBe(true)
        }
      }
    })

    it.each([...SUPPORTED_LOCALES])('in %s has no number written into the text that the server says', (locale) => {
      // The age and the days are settings: a language that writes 16 or 90 in would go on saying it when they change.
      for (const text of strings(document(locale, name))) {
        expect(text, `${locale}: ${text.slice(0, 50)}`).not.toMatch(/\b(16|90)\b/)
      }
    })

    it('has unique sections whose ids can be an address', () => {
      const ids = english().sections.map((section) => section.id)

      expect(new Set(ids).size).toBe(ids.length)
      expect(ids.every((id) => /^[a-z][a-z0-9-]*$/.test(id))).toBe(true)
    })
  })

  it.each([...SUPPORTED_LOCALES])('in %s name the age and the days where the text depends on them', (locale) => {
    const has = (name: LegalDocumentName, section: string, placeholder: string) =>
      document(locale, name)
        .sections.find((found) => found.id === section)
        ?.paragraphs.some((paragraph) => placeholdersOf(paragraph).includes(placeholder))

    expect(has('terms', 'age', 'minAge')).toBe(true)
    expect(has('privacy', 'age', 'minAge')).toBe(true)
    expect(has('terms', 'account', 'guestDays')).toBe(true)
    expect(has('terms', 'ending', 'guestDays')).toBe(true)
    expect(has('privacy', 'retention', 'guestDays')).toBe(true)
  })

  it('have a no-break space before : ; ? ! in French, so that a line never starts with one of them', () => {
    for (const name of DOCUMENTS) {
      for (const text of strings(document('fr', name))) {
        expect(text, text.slice(0, 60)).not.toMatch(/\S [:;?!](?=\s|$)/)
      }
    }
  })

  it('are told apart: no language repeats the English text by mistake', () => {
    for (const locale of SUPPORTED_LOCALES.filter((code) => code !== 'en')) {
      expect(document(locale, 'terms').title).not.toBe(document('en', 'terms').title)
      expect(document(locale, 'privacy').intro).not.toBe(document('en', 'privacy').intro)
    }
  })

  describe('loading', () => {
    it.each([...SUPPORTED_LOCALES])('gives the texts in %s', async (locale) => {
      expect(await loadLegalTexts(locale)).toBe(texts[locale])
    })

    it('gives the English text for a language it has no file for', async () => {
      // @ts-expect-error: a language the application does not support
      expect(await loadLegalTexts('es')).toBe(texts.en)
    })
  })
})
