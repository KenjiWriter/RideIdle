import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setGame } from '@/game'
import { setLocale } from '@/i18n'
import { NOW } from '@/test/garageFixtures'
import { respond, serveApi } from '@/test/fetchServer'
import { aFind, aMarket } from '@/test/marketFixtures'
import { mountView } from '@/test/mountView'
import { aShipsGame } from '@/test/unitsFixtures'

import StorageView from './StorageView.vue'

const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)
const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

describe('StorageView', () => {
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

  it('lists the kinds of unit with their price, their odds and how far the guarantee is', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Storage units')
    expect(wrapper.get('[data-test="sub"]').text()).toBe('Złomowo · there is a car inside, but you see it only after you pay')

    const small = wrapper.get('[data-test="unit-small"]')
    expect(small.get('[data-test="name"]').text()).toBe('Small unit')
    expect(small.get('[data-test="price"]').text()).toBe('$1,200')
    expect(small.get('[data-test="odds-scrap"]').text()).toBe('Scrap 70%')
    expect(small.get('[data-test="odds-junk"]').text()).toBe('Junk 25%')
    expect(small.get('[data-test="odds-good"]').text()).toBe('Good car 4.5%')
    expect(small.get('[data-test="odds-gem"]').text()).toBe('Gem 0.5%')
    expect(small.get('[data-test="guarantee"]').text()).toBe('Until the guarantee: 12')
    expect(small.get('[data-test="open"]').text()).toBe('Open · $1,200')
    expect(wrapper.get('[data-test="rule"]').text()).toBe('At least one unit in every 15 in a row holds a good car or better.')
  })

  it('says what level a unit asks for, and does not let the driver open it', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()

    const locked = wrapper.get('[data-test="unit-large"]')

    expect(locked.get('[data-test="locked"]').text()).toBe('Requires player level 9')
    expect(locked.get('[data-test="locked"]').attributes('disabled')).toBeDefined()
    expect(locked.find('[data-test="open"]').exists()).toBe(false)
  })

  it('opens a unit with one request id, and shows what was in it', async () => {
    const afterOpening = { ...aMarket({ garage: { owned: true, capacity: 3, used: 2, free: 1 } }, 480_000), bought: 9, repeated: false, opened: aFind() }
    const calls = serve({
      'GET /api/v1/market': () => respond(200, aMarket()),
      'POST /api/v1/market/storage/small/open': () => respond(201, afterOpening),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()

    expect(wrapper.find('[data-test="found"]').exists()).toBe(false)

    await wrapper.get('[data-test="unit-small"] [data-test="open"]').trigger('click')
    await flushPromises()

    const body = calls.find((call) => call.method === 'POST')?.body as Record<string, string>

    expect(body.request_id).toEqual(expect.any(String))

    const found = wrapper.get('[data-test="found"]')
    expect(found.attributes('data-class')).toBe('good')
    expect(found.get('[data-test="found-title"]').text()).toBe('A good find!')
    expect(found.get('[data-test="found-name"]').text()).toBe('Rdzak GT-3')
    expect(found.get('.tag').text()).toBe('Hatchback')
    expect(found.get('[data-test="found-sale"]').text()).toBe('$2,800')
    expect(found.get('[data-test="found-km"]').text()).toBe('120,000 km')
    expect(found.get('[data-test="found-year"]').text()).toBe(`Model year ${new Date(NOW).getUTCFullYear() - 8}`)
    expect(found.get('[data-test="found-condition"]').text()).toBe('Condition 72%')
    expect(found.get('[data-test="found-defects"]').text()).toBe('Defects: 1')
    expect(found.get('[data-test="found-worth"]').text()).toBe('Worth $4,000 on the market')
    expect(found.get('[data-test="to-fleet"]').text()).toBe('To the fleet')
    expect(wrapper.get('[data-test="garage"]').text()).toBe('Garage in Złomowo: 2 / 3 places')
  })

  it('shows nothing new for a unit that was opened before', async () => {
    serve({
      'GET /api/v1/market': () => respond(200, aMarket()),
      'POST /api/v1/market/storage/small/open': () => respond(200, { ...aMarket(), bought: null, repeated: true, opened: null }),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()
    await wrapper.get('[data-test="unit-small"] [data-test="open"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="found"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="error"]').exists()).toBe(false)
  })

  it('does not let the driver open a unit that costs more than the cash', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket({}, 100_000)), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()

    expect(wrapper.get('[data-test="unit-small"] [data-test="open"]').attributes('disabled')).toBeDefined()
  })

  it('says that the garage is full, and does not let the driver open anything', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket({ garage: { owned: true, capacity: 3, used: 3, free: 0 } }, 9_000_000)), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()

    expect(wrapper.get('[data-test="no-room"]').text()).toBe('The garage in Złomowo is full. Make room, or buy a garage in the Fleet tab.')
    expect(wrapper.get('[data-test="unit-small"] [data-test="open"]').attributes('disabled')).toBeDefined()
  })

  it('shows what the server says when it refuses, and asks for the market again', async () => {
    let asked = 0
    serve({
      'GET /api/v1/market': () => respond(200, ++asked && aMarket()),
      'POST /api/v1/market/storage/small/open': () => respond(422, { message: 'Requires player level 3 (you are level 2). You get XP by driving.', code: 'unit_level_required' }),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()
    await wrapper.get('[data-test="unit-small"] [data-test="open"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('Requires player level 3 (you are level 2). You get XP by driving.')
    expect(asked).toBe(2)
  })

  it('has the tabs of the city, with the storage lit', async () => {
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()

    expect(wrapper.get('[data-test="tab-storage"]').classes()).toContain('on')
    expect(wrapper.get('[data-test="tab-storage"]').text()).toBe('Storage')
    expect(wrapper.get('[data-test="tab-dealer"]').classes()).not.toContain('on')
    expect(wrapper.get('[data-test="nav-shop"]').classes()).toContain('on')
  })

  it('offers a retry when the market cannot be loaded', async () => {
    let attempt = 0
    serve({ 'GET /api/v1/market': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : respond(200, aMarket())), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The market could not be loaded.')

    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="unit-small"]').exists()).toBe(true)
  })

  it('speaks the language of the driver, with the decimal comma of Polish', async () => {
    await setLocale('pl')
    serve({ 'GET /api/v1/market': () => respond(200, aMarket()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(StorageView, '/storage')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Magazyny')
    expect(wrapper.get('[data-test="unit-small"] [data-test="name"]').text()).toBe('Mały magazyn')
    expect(wrapper.get('[data-test="unit-small"] [data-test="odds-good"]').text()).toBe('Dobre auto 4,5%')
    expect(wrapper.get('[data-test="unit-small"] [data-test="open"]').text()).toContain('Otwórz')
    expect(wrapper.get('[data-test="unit-large"] [data-test="locked"]').text()).toBe('Wymaga poziomu gracza 9')
    expect(wrapper.get('[data-test="tab-storage"]').text()).toBe('Magazyny')
  })

  describe('in a game that counts in nautical miles', () => {
    afterEach(() => {
      setGame()
    })

    it('says the mileage of the car that was found in the unit of travel of the game', async () => {
      setGame(aShipsGame())
      const afterOpening = { ...aMarket({ garage: { owned: true, capacity: 3, used: 2, free: 1 } }, 480_000), bought: 9, repeated: false, opened: aFind() }
      serve({
        'GET /api/v1/market': () => respond(200, aMarket()),
        'POST /api/v1/market/storage/small/open': () => respond(201, afterOpening),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(StorageView, '/storage')
      await flushPromises()
      await wrapper.get('[data-test="unit-small"] [data-test="open"]').trigger('click')
      await flushPromises()

      // 120,000 km are 64,794.8 nm.
      expect(wrapper.get('[data-test="found-km"]').text()).toBe('64,794.8 nm')
    })
  })
})
