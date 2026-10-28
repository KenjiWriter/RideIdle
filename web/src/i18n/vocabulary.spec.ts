import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import en from './locales/en.json'
import pl from './locales/pl.json'
import { i18n, setLocale, setVocabulary } from './index'
import { loadVocabulary } from './vocabulary'

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

const t = (key: string): string => i18n.global.t(key)

describe('the vocabulary of a pack', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  afterEach(async () => {
    // The texts of the app, as they were: the vocabulary is merged into the messages that the module keeps.
    setVocabulary({})
    i18n.global.setLocaleMessage('en', en)
    i18n.global.setLocaleMessage('pl', pl)
    await setLocale('en')
    vi.unstubAllGlobals()
  })

  it('puts the words of the pack over the texts of the app and leaves the rest as it was', () => {
    expect(t('slot.tires')).toBe('Tires')

    setVocabulary({ en: { 'slot.tires': 'Hull' } })

    expect(t('slot.tires')).toBe('Hull')
    expect(t('slot.engine')).toBe(en.slot.engine)
  })

  it('speaks in the language that is open, with the words of that language', async () => {
    setVocabulary({ en: { 'slot.tires': 'Hull' }, pl: { 'slot.tires': 'Kadłub' } })

    await setLocale('pl')

    expect(t('slot.tires')).toBe('Kadłub')
    expect(t('slot.engine')).toBe(pl.slot.engine)
  })

  it('reaches a message that is deeper than one level', () => {
    setVocabulary({ en: { 'race.board.fieldNote': 'Times are voyages in calm water.' } })

    expect(t('race.board.fieldNote')).toBe('Times are voyages in calm water.')
    expect(t('race.board.driver')).toBe(en.race.board.driver)
  })

  it('is fetched from the API and applied', async () => {
    const fetchMock = vi.fn().mockResolvedValue(respond(200, { number: '2026.10.4', messages: { en: { 'nav.garage': 'Port' } } }))
    vi.stubGlobal('fetch', fetchMock)

    await loadVocabulary()

    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/meta/vocabulary')
    expect(t('nav.garage')).toBe('Port')
  })

  it('changes nothing when the API cannot be reached or answers badly', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
    await expect(loadVocabulary()).resolves.toBeUndefined()

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(500, { message: 'boom' })))
    await expect(loadVocabulary()).resolves.toBeUndefined()

    expect(t('nav.garage')).toBe(en.nav.garage)
  })
})
