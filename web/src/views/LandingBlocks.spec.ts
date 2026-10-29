import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { parseLanding, setLanding } from '@/game/landing'
import { storeToken } from '@/auth/token'
import { i18n, setLocale } from '@/i18n'
import { mountView } from '@/test/mountView'

import LandingView from './LandingView.vue'

// The front page of a game that has one of its own: the blocks that its pack says, in place of the page of the app, with the same way into the game.

const line = (en: string, pl = en): Record<string, string> => ({ en, pl })

/** One block of each kind, as the server answers them. */
function ships(extra: unknown[] = []) {
  return parseLanding({
    number: '2026.10.0',
    blocks: [
      { type: 'hero', eyebrow: line('Browser game'), title: line('Sail the stars', 'Płyń do gwiazd'), subtitle: line('Between the planets.', 'Między planetami.'), image_url: '/api/v1/media/aaaa.png', alt: line('A ship at dusk') },
      { type: 'features', items: [{ kicker: line('Hull'), title: line('A strong hull'), text: line('Steel all round.') }, { title: line('Fast sails'), text: line('Wind and light.') }] },
      { type: 'text', title: line('About the game'), body: line("First paragraph.\n\nSecond paragraph.") },
      { type: 'image', image_url: '/api/v1/media/bbbb.webp', alt: line('A harbour'), caption: line('The harbour at night') },
      { type: 'cta', title: line('Ready to sail?'), text: line('Your ship waits.') },
      ...extra,
    ],
  })
}

describe('the front page of a game that has one', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    i18n.global.locale.value = 'en'
    setLanding(ships())
  })

  afterEach(() => {
    setLanding()
    vi.unstubAllGlobals()
  })

  it('draws the blocks and not the page of the app, and keeps the header of the page', async () => {
    const { wrapper } = await mountView(LandingView)

    expect(wrapper.find('[data-test="landing-blocks"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="hero"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="features"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="brand"]').text()).toBe('RideIdle')
    expect(wrapper.get('[data-test="have-driver"]').attributes('href')).toBe('/recover')
  })

  it('draws the hero: the eyebrow, the title as the one heading of the page, the subtitle and the picture with its description', async () => {
    const { wrapper } = await mountView(LandingView)
    const hero = wrapper.get('[data-test="block-hero"]')

    expect(hero.get('h1').text()).toBe('Sail the stars')
    expect(hero.text()).toContain('Browser game')
    expect(hero.text()).toContain('Between the planets.')
    expect(hero.get('img').attributes('src')).toBe('/api/v1/media/aaaa.png')
    expect(hero.get('img').attributes('alt')).toBe('A ship at dusk')
    expect(wrapper.findAll('h1')).toHaveLength(1)
  })

  it('draws the cards, with the kicker where there is one', async () => {
    const { wrapper } = await mountView(LandingView)
    const cards = wrapper.findAll('[data-test="block-feature"]')

    expect(cards.map((card) => card.get('h2').text())).toEqual(['A strong hull', 'Fast sails'])
    expect(cards[0]?.text()).toContain('Hull')
    expect(cards[0]?.find('.kick').exists()).toBe(true)
    expect(cards[1]?.find('.kick').exists()).toBe(false)
  })

  it('draws a text as its paragraphs, under its title', async () => {
    const { wrapper } = await mountView(LandingView)
    const text = wrapper.get('[data-test="block-text"]')

    expect(text.get('h2').text()).toBe('About the game')
    expect(text.findAll('p').map((paragraph) => paragraph.text())).toEqual(['First paragraph.', 'Second paragraph.'])
  })

  it('draws a picture with its description and its caption', async () => {
    const { wrapper } = await mountView(LandingView)
    const figure = wrapper.get('[data-test="block-image"]')

    expect(figure.get('img').attributes('src')).toBe('/api/v1/media/bbbb.webp')
    expect(figure.get('img').attributes('alt')).toBe('A harbour')
    expect(figure.get('figcaption').text()).toBe('The harbour at night')
  })

  it('ends with the way into the game again, and it is the same one as at the top', async () => {
    const { wrapper } = await mountView(LandingView)
    const cta = wrapper.get('[data-test="block-cta"]')

    expect(cta.get('h2').text()).toBe('Ready to sail?')
    expect(cta.text()).toContain('Your ship waits.')
    expect(wrapper.findAll('[data-test="create"]')).toHaveLength(2)
    expect(cta.get('[data-test="create"]').attributes('href')).toBe('/create')
    expect(wrapper.findAll('[data-test="landing-actions"]')).toHaveLength(2)
  })

  it('lets a returning driver go on from either place', async () => {
    storeToken('t')
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: { id: 1, name: 'Kapitan', locale: 'en', is_guest: true, has_recovery_code: false } }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
    )

    const { wrapper } = await mountView(LandingView)
    await flushPromises()

    expect(wrapper.findAll('[data-test="continue"]').map((link) => link.text())).toEqual(['Continue as Kapitan', 'Continue as Kapitan'])
    expect(wrapper.find('[data-test="create"]').exists()).toBe(false)
  })

  it('says it in the language of the visitor, and in the English of the pack where the pack has no text in that language', async () => {
    await setLocale('pl')
    const polish = await mountView(LandingView)

    expect(polish.wrapper.get('h1').text()).toBe('Płyń do gwiazd')
    expect(polish.wrapper.get('[data-test="block-hero"]').text()).toContain('Między planetami.')
    // A text that the pack gives in English only is said in English to a Polish visitor, too.
    expect(polish.wrapper.get('[data-test="block-image"] figcaption').text()).toBe('The harbour at night')

    i18n.global.locale.value = 'fr'
    const french = await mountView(LandingView)

    expect(french.wrapper.get('h1').text()).toBe('Sail the stars')

    await setLocale('en')
  })

  it('shows a text as the text it is: nothing that a person typed in the panel becomes markup or runs', async () => {
    const hostile = "<script>window.__pwned = true</script> <b>bold</b> <img src=x onerror=alert(1)> & \"quotes\""

    setLanding(parseLanding({ blocks: [{ type: 'hero', title: line(hostile), subtitle: line(hostile) }, { type: 'text', body: line(hostile) }] }))
    const { wrapper } = await mountView(LandingView)
    const html = wrapper.get('[data-test="landing-blocks"]').html()

    expect(wrapper.get('h1').text()).toBe(hostile)
    expect(wrapper.find('[data-test="landing-blocks"] script').exists()).toBe(false)
    expect(wrapper.find('[data-test="landing-blocks"] b').exists()).toBe(false)
    expect(wrapper.find('[data-test="landing-blocks"] img').exists()).toBe(false)
    expect(html).toContain('&lt;script&gt;')
    expect((window as unknown as { __pwned?: boolean }).__pwned).toBeUndefined()
  })

  it('has one heading of the first level: a second hero is a heading of the second', async () => {
    setLanding(ships([{ type: 'hero', title: line('Another start') }]))
    const { wrapper } = await mountView(LandingView)

    expect(wrapper.findAll('h1').map((title) => title.text())).toEqual(['Sail the stars'])
    expect(wrapper.findAll('[data-test="block-hero"] h2').map((title) => title.text())).toEqual(['Another start'])
  })

  it('draws a hero without a picture and a picture that is not there as nothing', async () => {
    setLanding(parseLanding({ blocks: [{ type: 'hero', title: line('Sail') }] }))
    const { wrapper } = await mountView(LandingView)

    expect(wrapper.get('h1').text()).toBe('Sail')
    expect(wrapper.find('[data-test="block-hero"] img').exists()).toBe(false)
  })
})

describe('the front page of a game that has none', () => {
  it('is the page of the app, as it was', async () => {
    setLanding(null)
    const { wrapper } = await mountView(LandingView)

    expect(wrapper.find('[data-test="landing-blocks"]').exists()).toBe(false)
    expect(wrapper.get('h1').text()).toBe('A workshop that races while you’re away')
    expect(wrapper.find('[data-test="hero"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="feature-match"], [data-test="feature-grow"], [data-test="feature-idle"]')).toHaveLength(3)
    expect(wrapper.findAll('[data-test="create"]')).toHaveLength(1)
  })
})
