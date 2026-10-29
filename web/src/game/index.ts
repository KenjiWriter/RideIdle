import { computed, shallowRef } from 'vue'

import { mediaUrl } from '@/api/client'

/** The game that the app is open on (GET /api/v1/meta/game): what the host of the page says, as the server knows it. */
export interface GameInfo {
  slug: string
  /** The title of the game: the tab, the home screen and the mark in the header. */
  name: string
  /** The language a visitor gets when theirs is not one of the languages of the app. */
  locale: string
  /** The colour of the bar of the browser, and of the page of the app when it opens from the home screen. */
  theme_color: string
  /** The picture of the game that is its icon, a path of the API, or null when the game brings none (the app keeps its own). */
  icon_url: string | null
  icon_maskable_url: string | null
  is_default: boolean
}

/** The game that the app is until the server says another, and when it cannot be asked: the car game as it always was. */
export const DEFAULT_GAME: GameInfo = {
  slug: 'raceidle',
  name: 'RideIdle',
  locale: 'en',
  theme_color: '#0e1013',
  icon_url: null,
  icon_maskable_url: null,
  is_default: true,
}

const COLOR = /^#[0-9a-fA-F]{6}$/

/**
 * A game from what the server answered, or null when the answer is not a game (a client that is older or newer than the server must not break on it): it has a
 * slug and a name; what else is missing or is not what it should be is what the car game has.
 */
export function parseGame(raw: unknown): GameInfo | null {
  const body = raw as Record<string, unknown> | null

  if (!body || typeof body.slug !== 'string' || body.slug === '' || typeof body.name !== 'string' || body.name.trim() === '') {
    return null
  }

  const text = (value: unknown): string | null => (typeof value === 'string' && value !== '' ? value : null)

  return {
    slug: body.slug,
    name: body.name.trim(),
    locale: text(body.locale) ?? DEFAULT_GAME.locale,
    theme_color: typeof body.theme_color === 'string' && COLOR.test(body.theme_color) ? body.theme_color : DEFAULT_GAME.theme_color,
    icon_url: text(body.icon_url),
    icon_maskable_url: text(body.icon_maskable_url),
    is_default: body.is_default === true,
  }
}

/** The game that the screens read. A module and not a store because it is known before the app is made: the boot waits for it, a moment at most. */
const current = shallowRef<GameInfo>(DEFAULT_GAME)

/** Puts a game in force (the one of the host), or the car game again. */
export function setGame(game: GameInfo = DEFAULT_GAME): void {
  current.value = game
}

/** What a screen asks of the game: reactive, so that it shows the game that is in force. */
export function useGame() {
  return {
    game: computed(() => current.value),
    name: computed(() => current.value.name),
  }
}

/**
 * Puts the game on the page that is open: the title of the tab, the colour of the bar of the browser, and, when the game has an icon, the icon of the tab and of the
 * home screen of a phone (a game with none keeps the icons that the app brings). The manifest is the server's (`/api/v1/meta/manifest`), so it needs nothing here.
 */
export function applyGame(game: GameInfo, page: Document = document): void {
  page.title = game.name

  page.querySelector('meta[name="theme-color"]')?.setAttribute('content', game.theme_color)
  page.querySelector('meta[name="apple-mobile-web-app-title"]')?.setAttribute('content', game.name)

  const icon = mediaUrl(game.icon_url)

  if (icon === null) {
    return
  }

  for (const link of page.querySelectorAll('link[rel="icon"], link[rel="apple-touch-icon"]')) {
    link.setAttribute('href', icon)
  }
}
