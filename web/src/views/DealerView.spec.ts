import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { NOW } from '@/test/garageFixtures'
import { respond, serveApi } from '@/test/fetchServer'
import { aMarket, anOffer, aVehicle } from '@/test/marketFixtures'
import { mountView } from '@/test/mountView'

import DealerView from './DealerView.vue'

const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)
const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

describe('DealerView', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] })
    vi.setSystemTime(new Date(NOW))
    await setLocale('en')
    storeToken('device-token')
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('lists the new cars as the board does: name, class, price, engine, speed and mass, and that they are new', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Dealer')
    expect(wrapper.get('[data-test="sub"]').text()).toBe('Złomowo · new cars · mileage 0–15 km')

    const offer = wrapper.get('[data-test="offer-rdzak-gt-2"]')
    expect(offer.get('[data-test="name"]').text()).toBe('Rdzak GT-2')
    expect(offer.get('.tag').text()).toBe('Hatchback')
    expect(offer.get('[data-test="price"]').text()).toBe('$15,500')
    expect(offer.get('[data-test="engine"]').text()).toBe('R4 1.3 · 65 kW')
    expect(offer.get('[data-test="speed"]').text()).toBe('150 km/h')
    expect(offer.get('[data-test="mass"]').text()).toBe('880 kg')
    expect(offer.text()).toContain('Mileage 0–15 km · condition 100% · no hidden defects')
    expect(offer.get('[data-test="buy"]').text()).toBe('Buy')
  })

  it('puts each car on a small stage, with the plate of its tier and the picture the pack gives it', async () => {
    const offers = [
      anOffer({ vehicle: aVehicle({ slug: 'rdzak-gt-2', name: 'Rdzak GT-2', tier: 1, image_url: '/api/v1/media/aaaaaaaa.png' }) }),
      anOffer({ vehicle: aVehicle({ slug: 'zubr-v8', name: 'Zubr V8', tier: 5 }) }),
    ]
    serve({ 'GET /api/v1/market': () => respond(200, aMarket({ dealer: offers })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()

    const first = wrapper.get('[data-test="offer-rdzak-gt-2"]')
    expect(first.get('[data-test="stage"] .stage-name').text()).toBe('Rdzak GT-2')
    expect(first.get('[data-test="stage"] [data-test="picture"]').attributes('src')).toBe('/api/v1/media/aaaaaaaa.png')
    expect(first.get('[data-test="tier"]').text()).toBe('T1')

    // A car with no picture of its own stands on the stage as the outline of a body.
    const second = wrapper.get('[data-test="offer-zubr-v8"]')
    expect(second.get('[data-test="tier"]').text()).toBe('T5')
    expect(second.find('[data-test="stage"] [data-test="picture"]').exists()).toBe(false)
    expect(second.find('[data-test="stage"] [data-test="picture-empty"]').exists()).toBe(true)
  })

  it('says what level a car asks for, and does not let the driver buy it', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()

    const locked = wrapper.get('[data-test="offer-kornet-v6"]')

    expect(locked.get('[data-test="locked"]').text()).toBe('Requires player level 9')
    expect(locked.get('[data-test="locked"]').attributes('disabled')).toBeDefined()
    expect(locked.find('[data-test="buy"]').exists()).toBe(false)
  })

  it('buys a new car into the garage of the city, with one request id for the intention', async () => {
    const afterBuying = { ...aMarket({ garage: { owned: true, capacity: 3, used: 2, free: 1 } }, 600_000 - 1_550_000 + 1_550_000 - 500_000), bought: 9, repeated: false }
    const calls = serve({
      'GET /api/v1/market': () => respond(200, aMarket({}, 2_000_000)),
      'POST /api/v1/market/dealer': () => respond(201, afterBuying),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()
    await wrapper.get('[data-test="offer-rdzak-gt-2"] [data-test="buy"]').trigger('click')
    await flushPromises()

    const body = calls.find((call) => call.method === 'POST')?.body as Record<string, string>

    expect(body).toMatchObject({ vehicle: 'rdzak-gt-2' })
    expect(body.request_id).toEqual(expect.any(String))
    expect(wrapper.get('[data-test="done"]').text()).toBe('Rdzak GT-2 is in the garage in Złomowo.')
    expect(wrapper.get('[data-test="garage"]').text()).toBe('Garage in Złomowo: 2 / 3 places')
  })

  it('does not let the driver buy a car that costs more than the cash', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket({}, 1_000_000)), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()

    expect(wrapper.get('[data-test="offer-rdzak-gt-2"] [data-test="buy"]').attributes('disabled')).toBeDefined()
  })

  it('says that the garage is full, and does not let the driver buy anything', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket({ garage: { owned: true, capacity: 3, used: 3, free: 0 } }, 9_000_000)), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()

    expect(wrapper.get('[data-test="no-room"]').text()).toBe('The garage in Złomowo is full. Make room, or buy a garage in the Fleet tab.')
    expect(wrapper.get('[data-test="offer-rdzak-gt-2"] [data-test="buy"]').attributes('disabled')).toBeDefined()
  })

  it('shows what the server says when it refuses, and asks for the market again', async () => {
    let asked = 0
    serve({
      'GET /api/v1/market': () => respond(200, ++asked && aMarket({}, 2_000_000)),
      'POST /api/v1/market/dealer': () => respond(422, { message: 'Requires player level 6 (you are level 3). You get XP by driving.', code: 'vehicle_level_required' }),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()
    await wrapper.get('[data-test="offer-rdzak-gt-2"] [data-test="buy"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('Requires player level 6 (you are level 3). You get XP by driving.')
    expect(asked).toBe(2)
  })

  it('has the tabs of the city, with the dealer lit', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()

    expect(wrapper.get('[data-test="tab-dealer"]').classes()).toContain('on')
    expect(wrapper.get('[data-test="tab-exchange"]').classes()).not.toContain('on')
    expect(wrapper.get('[data-test="nav-shop"]').classes()).toContain('on')
  })

  it('offers a retry when the market cannot be loaded', async () => {
    let attempt = 0
    serve({ 'GET /api/v1/market': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : respond(200, aMarket())), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The market could not be loaded.')

    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="offer-rdzak-gt-2"]').exists()).toBe(true)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(DealerView, '/dealer')
    await flushPromises()

    expect(wrapper.get('[data-test="sub"]').text()).toBe('Złomowo · nowe auta · przebieg 0–15 km')
    expect(wrapper.get('[data-test="offer-kornet-v6"] [data-test="locked"]').text()).toBe('Wymaga poziomu gracza 9')
    expect(wrapper.get('[data-test="offer-rdzak-gt-2"] [data-test="buy"]').text()).toBe('Kup')
    expect(wrapper.get('[data-test="offer-rdzak-gt-2"] .tag').text()).toBe('Hatchback')
  })
})
