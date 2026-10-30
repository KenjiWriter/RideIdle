import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setGame } from '@/game'
import { setLocale } from '@/i18n'
import { aFleet } from '@/test/fleetFixtures'
import { NOW } from '@/test/garageFixtures'
import { respond, serveApi } from '@/test/fetchServer'
import { aListing, aMarket } from '@/test/marketFixtures'
import { mountView } from '@/test/mountView'
import { aShipsGame } from '@/test/unitsFixtures'

import ExchangeView from './ExchangeView.vue'

const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)
const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

describe('ExchangeView', () => {
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

  it('lists the cars of the exchange as the board does: name, class, price, mileage, engine, year and what the listing says', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Car exchange')
    expect(wrapper.get('[data-test="sub"]').text()).toBe('Złomowo · used · refreshes in 18 min 20 s')
    expect(wrapper.findAll('[data-listing]')).toHaveLength(3)

    const first = wrapper.get('[data-test="listing-0"]')
    expect(first.get('[data-test="name"]').text()).toBe('Rdzak GT-3')
    expect(first.get('.tag').text()).toBe('Hatchback')
    expect(first.get('[data-test="price"]').text()).toBe('$3,620')
    expect(first.get('[data-test="km"]').text()).toBe('142,000 km')
    expect(first.get('[data-test="engine"]').text()).toBe('R4 1.6')
    // 14 years old in 2026.
    expect(first.get('[data-test="year"]').text()).toBe('Model year 2012')
    expect(first.get('[data-test="word"]').text()).toBe('listing: good condition')
    expect(first.find('[data-test="inspection"]').exists()).toBe(false)
    expect(first.get('[data-test="inspect"]').text()).toBe('Inspection $109')
    expect(first.get('[data-test="buy"]').text()).toBe('Buy')
  })

  it('puts each car on a small stage, with the plate of its tier', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    const first = wrapper.get('[data-test="listing-0"]')
    expect(first.get('[data-test="stage"] .stage-name').text()).toBe('Rdzak GT-3')
    expect(first.get('[data-test="tier"]').text()).toBe('T2')
  })

  it('does not show the condition of a car that was not inspected: its bar is dashed', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    const cells = wrapper.findAll('[data-test="listing-0"] [data-test="cells"] i')

    expect(cells).toHaveLength(8)
    expect(cells.every((cell) => cell.classes().includes('border-dashed'))).toBe(true)
  })

  it('shows what an inspection found: the condition, and a red cell for every defect', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    const third = wrapper.get('[data-test="listing-2"]')
    const cells = third.findAll('[data-test="cells"] i')

    expect(third.get('[data-test="inspection"]').text()).toBe('Inspection: 40% fit')
    expect(third.get('[data-test="defects"]').text()).toBe('Defects: 3')
    expect(third.find('[data-test="word"]').exists()).toBe(false)
    expect(cells.filter((cell) => cell.classes().includes('lo'))).toHaveLength(3)
    expect(cells.filter((cell) => cell.classes().includes('on'))).toHaveLength(5)
    // The inspection is done, and it is not paid for twice.
    expect(third.get('[data-test="inspect"]').text()).toBe('Inspection done')
    expect(third.get('[data-test="inspect"]').attributes('disabled')).toBeDefined()
  })

  it('pays for an inspection, and then shows the condition', async () => {
    const inspected = aMarket()
    inspected.data.exchange.listings[0] = aListing({ inspected: true, inspection_cost_cents: null, condition: { percent: 100, defects: 0 } })
    inspected.wallet.cash_cents = 600_000 - 10_900

    const calls = serve({
      'GET /api/v1/market': () => respond(200, aMarket()),
      'POST /api/v1/market/exchange/zlomowo.995940.0.aaaaaaaa/inspect': () => respond(200, inspected),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()
    await wrapper.get('[data-test="listing-0"] [data-test="inspect"]').trigger('click')
    await flushPromises()

    expect(calls.some((call) => call.method === 'POST' && call.url.endsWith('/zlomowo.995940.0.aaaaaaaa/inspect'))).toBe(true)
    expect(wrapper.get('[data-test="listing-0"] [data-test="inspection"]').text()).toBe('Inspection: 100% fit')
    expect(wrapper.find('[data-test="listing-0"] [data-test="defects"]').exists()).toBe(false)
  })

  it('buys a car into the garage of the city, and says so; the car is not listed any more', async () => {
    const afterBuying = aMarket({ garage: { owned: true, capacity: 3, used: 2, free: 1 } }, 600_000 - 362_000)
    afterBuying.data.exchange.listings = afterBuying.data.exchange.listings.slice(1)
    afterBuying.bought = 9
    afterBuying.repeated = false

    const calls = serve({
      'GET /api/v1/market': () => respond(200, aMarket()),
      'POST /api/v1/market/exchange/zlomowo.995940.0.aaaaaaaa/buy': () => respond(201, afterBuying),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()
    await wrapper.get('[data-test="listing-0"] [data-test="buy"]').trigger('click')
    await flushPromises()

    expect(calls.some((call) => call.method === 'POST' && call.url.endsWith('/zlomowo.995940.0.aaaaaaaa/buy'))).toBe(true)
    expect(wrapper.get('[data-test="done"]').text()).toBe('Rdzak GT-3 is in the garage in Złomowo.')
    expect(wrapper.findAll('[data-listing]')).toHaveLength(2)
    expect(wrapper.get('[data-test="garage"]').text()).toBe('Garage in Złomowo: 2 / 3 places')
    // The cash went down, and the next car (Kornet V6, $8 140) is too dear now.
    expect(wrapper.get('[data-test="listing-0"] [data-test="buy"]').attributes('disabled')).toBeDefined()
  })

  it('does not let the driver buy a car that costs more than the cash, or inspect one that does', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket({}, 5_000)), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    expect(wrapper.get('[data-test="listing-0"] [data-test="buy"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="listing-0"] [data-test="inspect"]').attributes('disabled')).toBeDefined()
  })

  it('says that the garage is full, and does not let the driver buy anything', async () => {
    const full = aMarket({ garage: { owned: true, capacity: 3, used: 3, free: 0 } })
    serve({ 'GET /api/v1/market': () => respond(200, full), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    expect(wrapper.get('[data-test="no-room"]').text()).toBe('The garage in Złomowo is full. Make room, or buy a garage in the Fleet tab.')
    expect(wrapper.get('[data-test="listing-0"] [data-test="buy"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="buy-wreck"]').attributes('disabled')).toBeDefined()
    // Looking is free: an inspection is still offered.
    expect(wrapper.get('[data-test="listing-0"] [data-test="inspect"]').attributes('disabled')).toBeUndefined()
  })

  it('counts down to the refresh with the clock of the server, and asks for the new cars when it is due', async () => {
    let asked = 0
    const refreshed = aMarket({}, 600_000, NOW + 1_101_000)
    refreshed.data.exchange.next_refresh_ms = NOW + 2_900_000
    refreshed.data.exchange.listings = [aListing({ key: 'zlomowo.995941.0.dddddddd', vehicle: aMarket().data.exchange.listings[1].vehicle, price_cents: 700_000 })]

    serve({
      'GET /api/v1/market': () => respond(200, ++asked === 1 ? aMarket({ exchange: { ...aMarket().data.exchange, next_refresh_ms: NOW + 4_000 } }) : refreshed),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    expect(wrapper.get('[data-test="sub"]').text()).toContain('refreshes in 0 min 04 s')

    await vi.advanceTimersByTimeAsync(5_000)
    await flushPromises()

    expect(asked).toBe(2)
    expect(wrapper.findAll('[data-listing]')).toHaveLength(1)
    expect(wrapper.get('[data-test="listing-0"] [data-test="name"]').text()).toBe('Kornet V6')
  })

  it('offers the wrecks of the yard as the cheapest cars, bought into the garage of the city with one request id', async () => {
    const afterBuying = { ...aMarket({ garage: { owned: true, capacity: 3, used: 2, free: 1 } }, 600_000 - 114_000), bought: 9, repeated: false }
    const calls = serve({
      'GET /api/v1/market': () => respond(200, aMarket()),
      'POST /api/v1/cars': () => respond(201, { ...aFleet({}, 486_000), bought: 9, repeated: false }),
      'GET /api/v1/runs/current': noRun,
    })
    // The market is asked for again after the purchase: it knows the garage got a car.
    let marketCalls = 0
    fetchMock.mockImplementation((url: string, init: RequestInit = {}) => {
      const method = init.method ?? 'GET'
      calls.push({ url, method, body: init.body ? JSON.parse(init.body as string) : null })

      if (url === '/api/v1/market') {
        return Promise.resolve(respond(200, ++marketCalls === 1 ? aMarket() : afterBuying))
      }

      if (url === '/api/v1/cars' && method === 'POST') {
        return Promise.resolve(respond(201, { ...aFleet({}, 486_000), bought: 9, repeated: false }))
      }

      return Promise.resolve(respond(404, { message: 'No run', code: 'no_run' }))
    })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    expect(wrapper.get('[data-test="wreck-rdzak-gt-4"]').text()).toContain('Hatchback')
    expect(wrapper.get('[data-test="wreck-bizon-m-2"]').text()).toContain('Muscle car')
    expect(wrapper.get('[data-test="wreck-rdzak-gt-4"] [data-test="buy-wreck"]').text()).toBe('Buy · $1,140')

    await wrapper.get('[data-test="wreck-rdzak-gt-4"] [data-test="buy-wreck"]').trigger('click')
    await flushPromises()

    const body = calls.find((call) => call.method === 'POST' && call.url === '/api/v1/cars')?.body as Record<string, string>
    expect(body).toMatchObject({ vehicle: 'rdzak-gt-4', city: 'zlomowo' })
    expect(body.request_id).toEqual(expect.any(String))
    expect(wrapper.get('[data-test="done"]').text()).toBe('Rdzak GT-4 is in the garage in Złomowo.')
    expect(wrapper.get('[data-test="garage"]').text()).toBe('Garage in Złomowo: 2 / 3 places')
  })

  it('shows what the server says when it refuses, and asks for the market again', async () => {
    let asked = 0
    serve({
      'GET /api/v1/market': () => respond(200, ++asked && aMarket()),
      'POST /api/v1/market/exchange/zlomowo.995940.0.aaaaaaaa/buy': () => respond(422, { message: 'That car is no longer on the exchange.', code: 'listing_gone' }),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()
    await wrapper.get('[data-test="listing-0"] [data-test="buy"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('That car is no longer on the exchange.')
    expect(asked).toBe(2)
  })

  it('has the tabs of the city, with the exchange lit, and keeps the city lit in the navigation', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    expect(wrapper.get('[data-test="tab-exchange"]').classes()).toContain('on')
    expect(wrapper.get('[data-test="tab-shop"]').classes()).not.toContain('on')
    expect(wrapper.get('[data-test="tab-shop"]').attributes('href')).toBe('/shop')
    expect(wrapper.get('[data-test="tab-dealer"]').attributes('href')).toBe('/dealer')
    expect(wrapper.get('[data-test="nav-shop"]').classes()).toContain('on')
  })

  it('offers a retry when the market cannot be loaded', async () => {
    let attempt = 0
    serve({ 'GET /api/v1/market': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : respond(200, aMarket())), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The market could not be loaded.')

    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="listing-0"]').exists()).toBe(true)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ExchangeView, '/exchange')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Giełda aut')
    expect(wrapper.get('[data-test="sub"]').text()).toBe('Złomowo · używane · odświeżenie za 18 min 20 s')
    expect(wrapper.get('[data-test="listing-0"] [data-test="word"]').text()).toBe('opis: stan dobry')
    expect(wrapper.get('[data-test="listing-0"] [data-test="inspect"]').text()).toMatch(/^Inspekcja /)
    expect(wrapper.get('[data-test="listing-2"] [data-test="inspection"]').text()).toBe('Inspekcja: sprawny w 40%')
    expect(wrapper.get('[data-test="listing-0"] [data-test="buy"]').text()).toBe('Kup')
  })

  describe('in a game that counts in nautical miles', () => {
    afterEach(() => {
      setGame()
    })

    it('says the mileage of a listing in the unit of travel of the game', async () => {
      setGame(aShipsGame())
      serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(ExchangeView, '/exchange')
      await flushPromises()

      // 142,000 km are 76,673.9 nm.
      expect(wrapper.get('[data-test="listing-0"] [data-test="km"]').text()).toBe('76,673.9 nm')
    })
  })
})
