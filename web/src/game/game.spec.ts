import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { DEFAULT_GAME, applyGame, parseGame, setGame, useGame, type GameInfo } from './index'
import { loadGame } from './load'

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

/** A game about ships, on a domain of its own, with a picture for an icon. */
const ships: GameInfo = {
  slug: 'ships',
  name: 'Star Ships',
  locale: 'pl',
  theme_color: '#0b3d5c',
  icon_url: '/api/v1/media/aaaaaaaa.png',
  icon_maskable_url: null,
  is_default: false,
}

/** A page like the one of the app: the title, the colour of the bar, the icons of the tab and of an iPhone. */
function page(): Document {
  const doc = document.implementation.createHTMLDocument('RideIdle')

  doc.head.innerHTML = `
    <link rel="icon" type="image/png" sizes="48x48" href="/icons/favicon-48.png" />
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png" />
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" />
    <meta name="theme-color" content="#0e1013" />
    <meta name="apple-mobile-web-app-title" content="RideIdle" />
  `

  return doc
}

afterEach(() => {
  setGame()
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('the game of the app', () => {
  it('is the car game until the server says another', () => {
    expect(DEFAULT_GAME).toMatchObject({ slug: 'raceidle', name: 'RideIdle', locale: 'en', theme_color: '#0e1013', icon_url: null, icon_maskable_url: null, is_default: true })
    expect(useGame().game.value).toBe(DEFAULT_GAME)
    expect(useGame().name.value).toBe('RideIdle')
  })

  it('is what the screens read once it is in force, and the car game again when it is taken out', () => {
    const { game, name } = useGame()

    setGame(ships)
    expect(game.value).toBe(ships)
    expect(name.value).toBe('Star Ships')

    setGame()
    expect(game.value).toBe(DEFAULT_GAME)
  })
})

describe('parseGame', () => {
  it('reads a game', () => {
    expect(parseGame({ ...ships })).toEqual(ships)
  })

  it('is null for an answer that is not a game', () => {
    expect(parseGame(null)).toBeNull()
    expect(parseGame('ships')).toBeNull()
    expect(parseGame({})).toBeNull()
    expect(parseGame({ slug: 'ships' })).toBeNull()
    expect(parseGame({ slug: '', name: 'Ships' })).toBeNull()
    expect(parseGame({ slug: 'ships', name: '   ' })).toBeNull()
    expect(parseGame({ slug: 7, name: 'Ships' })).toBeNull()
  })

  it('has the car game’s language and colour for what is missing or is not what it should be, and no icon', () => {
    expect(parseGame({ slug: 'ships', name: ' Ships ', theme_color: 'blue', locale: '', icon_url: 12 })).toEqual({
      slug: 'ships',
      name: 'Ships',
      locale: 'en',
      theme_color: '#0e1013',
      icon_url: null,
      icon_maskable_url: null,
      is_default: false,
    })
  })
})

describe('applyGame', () => {
  it('puts the name on the tab and the colour on the bar of the browser', () => {
    const doc = page()

    applyGame(ships, doc)

    expect(doc.title).toBe('Star Ships')
    expect(doc.querySelector('meta[name="theme-color"]')?.getAttribute('content')).toBe('#0b3d5c')
    expect(doc.querySelector('meta[name="apple-mobile-web-app-title"]')?.getAttribute('content')).toBe('Star Ships')
  })

  it('puts the icon of a game that has one on the tab and on the home screen of a phone', () => {
    const doc = page()

    applyGame(ships, doc)

    expect([...doc.querySelectorAll('link[rel="icon"], link[rel="apple-touch-icon"]')].map((link) => link.getAttribute('href'))).toEqual([
      '/api/v1/media/aaaaaaaa.png',
      '/api/v1/media/aaaaaaaa.png',
      '/api/v1/media/aaaaaaaa.png',
    ])
  })

  it('leaves the icons that the app brings alone when the game has none', () => {
    const doc = page()

    applyGame({ ...ships, icon_url: null }, doc)

    expect([...doc.querySelectorAll('link')].map((link) => link.getAttribute('href'))).toEqual(['/icons/favicon-48.png', '/icons/icon-192.png', '/icons/apple-touch-icon.png'])
    expect(doc.title).toBe('Star Ships')
  })

  it('leaves the car game as the page was made', () => {
    const doc = page()

    applyGame(DEFAULT_GAME, doc)

    expect(doc.title).toBe('RideIdle')
    expect(doc.querySelector('meta[name="theme-color"]')?.getAttribute('content')).toBe('#0e1013')
    expect(doc.querySelector('link[rel="icon"]')?.getAttribute('href')).toBe('/icons/favicon-48.png')
  })
})

describe('loadGame', () => {
  beforeEach(() => {
    document.title = 'RideIdle'
  })

  it('asks the API which game the host is, puts it in force and on the page, and gives it', async () => {
    const fetchMock = vi.fn().mockResolvedValue(respond(200, ships))
    vi.stubGlobal('fetch', fetchMock)

    const game = await loadGame()

    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/meta/game')
    expect(game).toEqual(ships)
    expect(useGame().game.value).toEqual(ships)
    expect(document.title).toBe('Star Ships')
  })

  it('changes nothing when the API cannot be reached, answers badly, or answers what is not a game', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
    await expect(loadGame()).resolves.toBe(DEFAULT_GAME)

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(404, { message: 'There is no game here.', code: 'unknown_game' })))
    await expect(loadGame()).resolves.toBe(DEFAULT_GAME)

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(respond(200, { hello: 'world' })))
    await expect(loadGame()).resolves.toBe(DEFAULT_GAME)

    expect(useGame().game.value).toBe(DEFAULT_GAME)
    expect(document.title).toBe('RideIdle')
  })

  it('does not wait longer than it was told for an API that does not answer', async () => {
    vi.useFakeTimers()
    vi.stubGlobal(
      'fetch',
      vi.fn((_url: string, init: RequestInit) => new Promise((_resolve, reject) => init.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))))),
    )

    const loading = loadGame(1500)
    await vi.advanceTimersByTimeAsync(1500)

    await expect(loading).resolves.toBe(DEFAULT_GAME)
    expect(useGame().game.value).toBe(DEFAULT_GAME)
  })
})
