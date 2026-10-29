import { computed, shallowRef } from 'vue'

import { api } from '@/api/client'

/** A text of the front page of a game by the language it is in (`en` is always there; a language the pack has no text in says the English text). */
export type LocalizedText = Record<string, string>

export interface LandingItem {
  kicker: LocalizedText | null
  title: LocalizedText
  text: LocalizedText
}

export type LandingBlockType = 'hero' | 'features' | 'text' | 'image' | 'cta'

/** One block of the front page: the fields that its kind does not use are null (or empty, for the cards). */
export interface LandingBlock {
  type: LandingBlockType
  eyebrow: LocalizedText | null
  title: LocalizedText | null
  subtitle: LocalizedText | null
  text: LocalizedText | null
  body: LocalizedText | null
  alt: LocalizedText | null
  caption: LocalizedText | null
  /** The picture of the block, a path of the API. */
  image_url: string | null
  items: LandingItem[]
}

/** The front page of a game (GET /api/v1/meta/landing). */
export interface Landing {
  number: string
  blocks: LandingBlock[]
}

const TYPES: readonly LandingBlockType[] = ['hero', 'features', 'text', 'image', 'cta']

/** A text from what the server answered: the languages that have a text, or null when none does. */
function parseText(raw: unknown): LocalizedText | null {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    return null
  }

  const text: LocalizedText = {}

  for (const [language, value] of Object.entries(raw)) {
    if (typeof value === 'string' && value.trim() !== '') {
      text[language] = value
    }
  }

  return Object.keys(text).length > 0 ? text : null
}

function parseItems(raw: unknown): LandingItem[] {
  const items: LandingItem[] = []

  for (const item of Array.isArray(raw) ? raw : []) {
    const body = (item ?? {}) as Record<string, unknown>
    const title = parseText(body.title)
    const text = parseText(body.text)

    if (title !== null && text !== null) {
      items.push({ kicker: parseText(body.kicker), title, text })
    }
  }

  return items
}

/** A block, or null when it is not one the app can draw (a kind it does not know, or one without what the kind needs): the page is drawn without it. */
function parseBlock(raw: unknown): LandingBlock | null {
  const body = raw as Record<string, unknown> | null
  const type = TYPES.find((kind) => kind === body?.type)

  if (!body || type === undefined) {
    return null
  }

  const block: LandingBlock = {
    type,
    eyebrow: parseText(body.eyebrow),
    title: parseText(body.title),
    subtitle: parseText(body.subtitle),
    text: parseText(body.text),
    body: parseText(body.body),
    alt: parseText(body.alt),
    caption: parseText(body.caption),
    image_url: typeof body.image_url === 'string' && body.image_url !== '' ? body.image_url : null,
    items: parseItems(body.items),
  }

  const complete = {
    hero: block.title !== null,
    features: block.items.length > 0,
    text: block.body !== null,
    image: block.image_url !== null,
    cta: true,
  }[type]

  return complete ? block : null
}

/**
 * The front page from what the server answered, or null when there is nothing to draw (no blocks, or none that the app can draw, or an answer that is not a page):
 * the page at the address of the game is then the app's own.
 */
export function parseLanding(raw: unknown): Landing | null {
  const body = raw as Record<string, unknown> | null

  if (!body || !Array.isArray(body.blocks)) {
    return null
  }

  const blocks = body.blocks.map(parseBlock).filter((block): block is LandingBlock => block !== null)

  return blocks.length > 0 ? { number: typeof body.number === 'string' ? body.number : '', blocks } : null
}

/**
 * The text in the language, or the English text of the pack where it has none in that language (a French visitor of a game whose pack is written in English and
 * Polish reads the English), or nothing.
 */
export function pickText(text: LocalizedText | null | undefined, locale: string): string {
  return text?.[locale] ?? text?.en ?? ''
}

/** The front page that the screen draws. A module and not a store for the reason the game is: it is known before the app is made. */
const current = shallowRef<Landing | null>(null)

/** Puts a front page in force, or takes it out (the page is then the app's). */
export function setLanding(landing: Landing | null = null): void {
  current.value = landing
}

export function useLanding() {
  return { landing: computed(() => current.value) }
}

/**
 * Asks for the front page of the game and puts it in force. It waits a moment at most, and when it cannot be had (the network, the server, an answer that is not a
 * page) nothing changes: the page is the app's own.
 */
export async function loadLanding(timeoutMs = 1500): Promise<void> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)

  try {
    setLanding(parseLanding(await api<unknown>('/v1/meta/landing', { signal: controller.signal })))
  } catch {
    // Without it the page is the one of the app.
  } finally {
    clearTimeout(timer)
  }
}
