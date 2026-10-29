import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

import { DEFAULT_LOCALE, LOCALE_NAMES, SUPPORTED_LOCALES, detectLocale, normalizeLocale } from './locales'

describe('normalizeLocale', () => {
  it.each([
    ['pl', 'pl'],
    ['EN', 'en'],
    ['en-GB', 'en'],
    ['fr-CH', 'fr'],
    ['de_AT', 'de'],
    ['PT_br', 'pt-BR'],
    ['pt-BR', 'pt-BR'],
    ['pt', 'pt-BR'],
    ['br', 'pt-BR'],
    ['es', null],
    ['*', null],
    ['', null],
    [null, null],
    [undefined, null],
  ])('maps %s to %s', (input, expected) => {
    expect(normalizeLocale(input)).toBe(expected)
  })
})

describe('detectLocale', () => {
  it('prefers the stored choice over the browser languages', () => {
    expect(detectLocale('de', ['fr-FR', 'en'])).toBe('de')
  })

  it('uses the first supported browser language', () => {
    expect(detectLocale(null, ['es-ES', 'pt-BR', 'en'])).toBe('pt-BR')
  })

  it('ignores an unsupported stored value', () => {
    expect(detectLocale('xx', ['fr'])).toBe('fr')
  })

  it('falls back to the default language', () => {
    expect(detectLocale(null, ['es', 'it'])).toBe(DEFAULT_LOCALE)
  })

  it('falls back to the language of the game when no language of the visitor is one of the app, and only then', () => {
    expect(detectLocale(null, ['es', 'it'], 'pl')).toBe('pl')
    expect(detectLocale(null, ['es', 'pt-BR'], 'pl')).toBe('pt-BR')
    expect(detectLocale('de', ['es'], 'pl')).toBe('de')
    expect(detectLocale('xx', ['es'], 'fr')).toBe('fr')
  })

  it('takes the language of the game in any spelling, and the default when it is not one of the app or there is none', () => {
    expect(detectLocale(null, [], 'PT_br')).toBe('pt-BR')
    expect(detectLocale(null, [], 'xx')).toBe(DEFAULT_LOCALE)
    expect(detectLocale(null, [], '')).toBe(DEFAULT_LOCALE)
    expect(detectLocale(null, [], null)).toBe(DEFAULT_LOCALE)
  })
})

describe('supported languages', () => {
  it('are exactly the five product languages with native names', () => {
    expect([...SUPPORTED_LOCALES]).toEqual(['pl', 'en', 'fr', 'de', 'pt-BR'])
    expect(Object.keys(LOCALE_NAMES).sort()).toEqual([...SUPPORTED_LOCALES].sort())
  })

  it('match the API configuration (api/config/ride.php)', () => {
    const config = readFileSync(resolve(__dirname, '../../../api/config/ride.php'), 'utf-8')
    const match = /'locales'\s*=>\s*\[([^\]]*)\]/.exec(config)
    const apiLocales = [...(match?.[1] ?? '').matchAll(/'([^']+)'/g)].map((m) => m[1])

    expect(apiLocales).toEqual([...SUPPORTED_LOCALES])
  })
})
