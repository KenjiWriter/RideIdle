import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { aPart, anOffer } from '@/test/garageFixtures'
import { mountView } from '@/test/mountView'

import ShopView from './ShopView.vue'

const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)

const shop = (cashCents = 90_000, level = 3) =>
  respond(200, {
    data: {
      parts: [
        anOffer(),
        anOffer({ slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', price_cents: 57_000, stats: { grip: 100, handling: 98, braking: 100 }, required_car_level: 3, mountable: level >= 3 }),
        anOffer({ slug: 'sport-suspension-t1', name: 'Sport suspension', slot: 'suspension', tier: 1, price_cents: 75_000, stats: { handling: 100, clearance: 25 }, required_car_level: 0, mountable: true }),
      ],
      car: { id: 5, level },
    },
    wallet: { cash_cents: cashCents, xp: 0 },
  })

describe('ShopView', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('lists the parts with the slot, the tier, what they do and the price', async () => {
    serve({ 'GET /api/v1/shop': () => shop() })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Shop')
    expect(wrapper.get('[data-test="cash-chip"]').text()).toBe('$900.00')

    const exhaust = wrapper.get('[data-test="offer-sport-exhaust-t2"]')
    expect(exhaust.get('h2').text()).toBe('Sport exhaust')
    expect(exhaust.text()).toContain('Exhaust')
    expect(exhaust.text()).toContain('T2')
    expect(exhaust.get('[data-test="part-stats"]').text()).toBe('Power +2%')
    expect(exhaust.get('[data-test="buy-sport-exhaust-t2"]').text()).toBe('Buy for $475.00')

    expect(wrapper.findAll('[data-test="offer-street-tires-t2"] [data-test="part-stats"] li').map((line) => line.text())).toEqual(['Grip 100', 'Handling 98', 'Braking 100'])
  })

  it('buys a part, says where it is and offers the way to the workshop', async () => {
    const calls = serve({
      'GET /api/v1/shop': () => shop(),
      'POST /api/v1/shop/purchases': () => respond(201, { data: aPart(), purchase: { cost_cents: 47_500, repeated: false }, wallet: { cash_cents: 42_500, xp: 0 } }),
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })

    const { wrapper, router } = await mountView(ShopView, '/shop')
    await flushPromises()

    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ part: 'sport-exhaust-t2', request_id: expect.any(String) })
    expect(wrapper.get('[data-test="done"]').text()).toContain('Added to your inventory: Sport exhaust. Put it on in the workshop.')
    expect(wrapper.get('[data-test="cash-chip"]').text()).toBe('$425.00')

    await wrapper.get('[data-test="to-workshop"]').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('workshop'))
  })

  it('does not let a part be bought without the cash for it, and says so', async () => {
    serve({ 'GET /api/v1/shop': () => shop(50_000) })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('[data-test="buy-sport-exhaust-t2"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="buy-street-tires-t2"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="offer-street-tires-t2"] [data-test="no-cash"]').text()).toBe('Not enough cash.')
  })

  it('says that the car is too low a level to mount a part, but still sells it', async () => {
    serve({ 'GET /api/v1/shop': () => shop(90_000, 2) })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('[data-test="offer-street-tires-t2"] [data-test="needs-level"]').text()).toBe('Needs car level 3 Your car is level 2. You get XP by driving this car.')
    expect(wrapper.find('[data-test="offer-sport-suspension-t1"] [data-test="needs-level"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="buy-street-tires-t2"]').attributes('disabled')).toBeUndefined()
  })

  it('shows what the server says when it refuses, in the language of the driver', async () => {
    serve({
      'GET /api/v1/shop': () => shop(),
      'POST /api/v1/shop/purchases': () => respond(422, { message: 'Not enough cash.', code: 'insufficient_funds' }),
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()
    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('Not enough cash.')
    expect(wrapper.find('[data-test="done"]').exists()).toBe(false)
  })

  it('says it when the same purchase was already made', async () => {
    serve({
      'GET /api/v1/shop': () => shop(),
      'POST /api/v1/shop/purchases': () => respond(200, { data: null, purchase: { cost_cents: 0, repeated: true }, wallet: { cash_cents: 42_500, xp: 0 } }),
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()
    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="done"]').text()).toContain('That purchase was already made.')
  })

  it('buys one part at a time', async () => {
    let finish: (response: Response) => void = () => undefined
    const calls = serve({
      'GET /api/v1/shop': () => shop(),
      'POST /api/v1/shop/purchases': () => new Promise<Response>((resolve) => (finish = resolve)) as unknown as Response,
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await wrapper.get('[data-test="buy-sport-suspension-t1"]').trigger('click')

    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(1)
    expect(wrapper.get('[data-test="buy-sport-exhaust-t2"]').text()).toBe('Buying…')

    finish(respond(201, { data: aPart(), purchase: { cost_cents: 47_500, repeated: false }, wallet: { cash_cents: 42_500, xp: 0 } }))
    await flushPromises()
  })

  it('offers a retry when the shop cannot be loaded', async () => {
    let attempt = 0
    serve({ 'GET /api/v1/shop': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : shop()) })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The shop could not be loaded.')

    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test^="offer-"]')).toHaveLength(3)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    serve({ 'GET /api/v1/shop': () => shop() })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Sklep')
    expect(wrapper.get('[data-test="offer-sport-exhaust-t2"] [data-test="part-stats"]').text()).toBe('Moc +2%')
    expect(wrapper.get('[data-test="offer-sport-exhaust-t2"]').text()).toContain('Wydech')
  })

  it('has the navigation of the game, with the shop marked', async () => {
    serve({ 'GET /api/v1/shop': () => shop() })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('[data-test="game-nav"]').attributes('aria-label')).toBe('Game navigation')
    expect(wrapper.get('[data-test="nav-shop"]').classes()).toContain('on')
    expect(wrapper.get('[data-test="nav-workshop"]').classes()).not.toContain('on')
  })
})
