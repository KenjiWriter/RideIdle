import { afterEach, describe, expect, it, vi } from 'vitest'

import { loadLanding, parseLanding, pickText, setLanding, useLanding } from './landing'

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

const line = (en: string, pl = en): Record<string, string> => ({ en, pl })

/** What the server says of the front page of a game about ships: one block of each kind. */
const answer = {
  number: '2026.10.0',
  blocks: [
    { type: 'hero', eyebrow: line('Browser game'), title: line('Sail', 'Płyń'), subtitle: line('Between the stars.'), image_url: '/api/v1/media/aaaa.png', alt: line('A ship') },
    { type: 'features', items: [{ kicker: line('Hull'), title: line('Strong'), text: line('Steel.') }, { title: line('Fast'), text: line('Sails.') }] },
    { type: 'text', title: line('About'), body: line("One.\n\nTwo.") },
    { type: 'image', image_url: '/api/v1/media/bbbb.webp', alt: line('A harbour'), caption: line('The harbour') },
    { type: 'cta', title: line('Ready?'), text: line('Start now.') },
  ],
}

afterEach(() => {
  setLanding()
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('parseLanding', () => {
  it('reads the blocks of every kind, in their order', () => {
    const landing = parseLanding(answer)

    expect(landing?.number).toBe('2026.10.0')
    expect(landing?.blocks.map((block) => block.type)).toEqual(['hero', 'features', 'text', 'image', 'cta'])
    expect(landing?.blocks[0]).toMatchObject({ eyebrow: line('Browser game'), title: line('Sail', 'Płyń'), image_url: '/api/v1/media/aaaa.png', alt: line('A ship') })
    expect(landing?.blocks[1]?.items).toEqual([
      { kicker: line('Hull'), title: line('Strong'), text: line('Steel.') },
      { kicker: null, title: line('Fast'), text: line('Sails.') },
    ])
    expect(landing?.blocks[2]?.body).toEqual(line("One.\n\nTwo."))
    expect(landing?.blocks[3]).toMatchObject({ image_url: '/api/v1/media/bbbb.webp', caption: line('The harbour') })
  })

  it('is null for an answer that is not a page, and for a page with nothing to draw', () => {
    expect(parseLanding(null)).toBeNull()
    expect(parseLanding('page')).toBeNull()
    expect(parseLanding({})).toBeNull()
    expect(parseLanding({ number: '1', blocks: 'none' })).toBeNull()
    expect(parseLanding({ number: '1', blocks: [] })).toBeNull()
  })

  it('draws a page without a block it cannot draw: a kind it does not know, or one without what its kind needs', () => {
    const landing = parseLanding({
      blocks: [
        { type: 'script', title: line('x') },
        { type: 'hero' },
        { type: 'features', items: [{ title: line('No text') }] },
        { type: 'text' },
        { type: 'image', alt: line('No picture') },
        'text',
        null,
        { type: 'cta' },
      ],
    })

    expect(landing?.blocks.map((block) => block.type)).toEqual(['cta'])
    expect(landing?.number).toBe('')
  })

  it('keeps the languages that have a text, and nothing else of a text', () => {
    const landing = parseLanding({ blocks: [{ type: 'hero', title: { en: 'Sail', pl: '', fr: 7, de: '  ', 'pt-BR': 'Navegue' } }] })

    expect(landing?.blocks[0]?.title).toEqual({ en: 'Sail', 'pt-BR': 'Navegue' })
  })
})

describe('pickText', () => {
  it('is the text in the language of the visitor', () => {
    expect(pickText({ en: 'Sail', pl: 'Płyń', 'pt-BR': 'Navegue' }, 'pl')).toBe('Płyń')
    expect(pickText({ en: 'Sail', pl: 'Płyń', 'pt-BR': 'Navegue' }, 'pt-BR')).toBe('Navegue')
  })

  it('is the English text of the pack in a language it has none in', () => {
    expect(pickText({ en: 'Sail', pl: 'Płyń' }, 'fr')).toBe('Sail')
  })

  it('is nothing for no text', () => {
    expect(pickText(null, 'en')).toBe('')
    expect(pickText(undefined, 'en')).toBe('')
    expect(pickText({ pl: 'Płyń' }, 'fr')).toBe('')
  })
})

describe('loadLanding', () => {
  it('asks the API for the front page and puts it in force', async () => {
    const fetchMock = vi.fn().mockResolvedValue(respond(200, answer))
    vi.stubGlobal('fetch', fetchMock)

    await loadLanding()

    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/meta/landing')
    expect(useLanding().landing.value?.blocks).toHaveLength(5)
  })

  it('changes nothing when the API cannot be reached, answers badly, or answers what is not a page', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
    await loadLanding()
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(500, { message: 'No.' })))
    await loadLanding()
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(200, { hello: 'world' })))
    await loadLanding()

    expect(useLanding().landing.value).toBeNull()
  })

  it('does not wait longer than it was told for an API that does not answer', async () => {
    vi.useFakeTimers()
    vi.stubGlobal(
      'fetch',
      vi.fn((_url: string, init: RequestInit) => new Promise((_resolve, reject) => init.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))))),
    )

    const loading = loadLanding(1500)
    await vi.advanceTimersByTimeAsync(1500)

    await expect(loading).resolves.toBeUndefined()
    expect(useLanding().landing.value).toBeNull()
  })
})
