import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/client'
import { aFleet } from '@/test/fleetFixtures'
import { respond, serveApi } from '@/test/fetchServer'
import { NOW } from '@/test/garageFixtures'
import { aFind, aMarket } from '@/test/marketFixtures'

import { DEALER_ID_LIFETIME_MS, useMarketStore } from './market'

const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

describe('market store', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('loads the market and counts time with the clock of the server, not its own', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(NOW - 90 * 60_000))
    serveApi(fetchMock, { 'GET /api/v1/market': () => respond(200, aMarket()) })
    const store = useMarketStore()

    await store.load()

    expect(store.loaded).toBe(true)
    expect(store.market?.exchange.listings).toHaveLength(3)
    expect(store.cash?.cash_cents).toBe(600_000)
    // This device is 90 minutes behind the server, and the store knows it.
    expect(store.serverNow()).toBe(NOW)
  })

  it('pays for an inspection and takes the market from the answer', async () => {
    const calls = serveApi(fetchMock, { 'POST /api/v1/market/exchange/zlomowo.995940.0.aaaaaaaa/inspect': () => respond(200, aMarket({}, 500_000)), 'GET /api/v1/runs/current': noRun })
    const store = useMarketStore()

    await store.inspect('zlomowo.995940.0.aaaaaaaa')

    expect(calls[0]?.method).toBe('POST')
    expect(store.cash?.cash_cents).toBe(500_000)
  })

  it('buys a car of the exchange and says which car it is', async () => {
    serveApi(fetchMock, { 'POST /api/v1/market/exchange/zlomowo.995940.0.aaaaaaaa/buy': () => respond(201, { ...aMarket(), bought: 9, repeated: false }), 'GET /api/v1/runs/current': noRun })

    expect(await useMarketStore().buyListing('zlomowo.995940.0.aaaaaaaa')).toBe(9)
  })

  it('answers null for a car that was bought before', async () => {
    serveApi(fetchMock, { 'POST /api/v1/market/exchange/zlomowo.995940.0.aaaaaaaa/buy': () => respond(200, { ...aMarket(), bought: null, repeated: true }), 'GET /api/v1/runs/current': noRun })

    expect(await useMarketStore().buyListing('zlomowo.995940.0.aaaaaaaa')).toBeNull()
  })

  it('buys a new car with a request id', async () => {
    const calls = serveApi(fetchMock, { 'POST /api/v1/market/dealer': () => respond(201, { ...aMarket(), bought: 9, repeated: false }), 'GET /api/v1/runs/current': noRun })

    const bought = await useMarketStore().buyNew('rdzak-gt-2')

    expect(bought).toBe(9)
    expect(calls[0]?.body).toEqual({ vehicle: 'rdzak-gt-2', request_id: expect.any(String) })
  })

  it('sends the same request id again when the answer was lost, so the car is bought once', async () => {
    let attempt = 0
    const calls = serveApi(fetchMock, {
      'POST /api/v1/market/dealer': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : respond(200, { ...aMarket(), bought: null, repeated: true })),
      'GET /api/v1/runs/current': noRun,
    })
    const store = useMarketStore()

    await expect(store.buyNew('rdzak-gt-2')).rejects.toBeInstanceOf(ApiError)
    await store.buyNew('rdzak-gt-2')

    const ids = calls.filter((call) => call.method === 'POST').map((call) => (call.body as { request_id: string }).request_id)

    expect(ids[0]).toBe(ids[1])
  })

  it('uses a new request id for another car, for a purchase after the last one was answered, and after a minute', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(NOW))
    const calls = serveApi(fetchMock, { 'POST /api/v1/market/dealer': () => respond(500, { message: 'Server error' }) })
    const store = useMarketStore()
    const idOf = (index: number): string => (calls.filter((call) => call.method === 'POST')[index]?.body as { request_id: string }).request_id

    await store.buyNew('rdzak-gt-2').catch(() => undefined)
    await store.buyNew('rdzak-gt-3').catch(() => undefined)
    await store.buyNew('rdzak-gt-3').catch(() => undefined)
    vi.advanceTimersByTime(DEALER_ID_LIFETIME_MS + 1)
    await store.buyNew('rdzak-gt-3').catch(() => undefined)

    expect(idOf(1)).not.toBe(idOf(0))
    expect(idOf(2)).toBe(idOf(1))
    expect(idOf(3)).not.toBe(idOf(2))
  })

  it('buys a wreck into the garage of the city of the market, through the fleet, and asks for the market again', async () => {
    let marketCalls = 0
    const calls = serveApi(fetchMock, {
      'GET /api/v1/market': () => respond(200, ++marketCalls === 1 ? aMarket() : aMarket({ garage: { owned: true, capacity: 3, used: 2, free: 1 } })),
      'POST /api/v1/cars': () => respond(201, { ...aFleet(), bought: 9, repeated: false }),
      'GET /api/v1/runs/current': noRun,
    })
    const store = useMarketStore()

    await store.load()
    const bought = await store.buyWreck('rdzak-gt-4')

    expect(bought).toBe(9)
    expect(calls.find((call) => call.method === 'POST')?.body).toMatchObject({ vehicle: 'rdzak-gt-4', city: 'zlomowo' })
    expect(store.market?.garage.used).toBe(2)
  })

  it('does not buy a wreck before it knows the city', async () => {
    await expect(useMarketStore().buyWreck('rdzak-gt-4')).rejects.toThrow('not known')
  })

  it('opens a storage unit with a request id, and says what was in it', async () => {
    const calls = serveApi(fetchMock, { 'POST /api/v1/market/storage/small/open': () => respond(201, { ...aMarket(), bought: 9, repeated: false, opened: aFind() }), 'GET /api/v1/runs/current': noRun })

    const found = await useMarketStore().openStorage('small')

    expect(found).toEqual(aFind())
    expect(calls[0]?.body).toEqual({ request_id: expect.any(String) })
  })

  it('answers null for a unit that was opened before', async () => {
    serveApi(fetchMock, { 'POST /api/v1/market/storage/small/open': () => respond(200, { ...aMarket(), bought: null, repeated: true, opened: null }), 'GET /api/v1/runs/current': noRun })

    expect(await useMarketStore().openStorage('small')).toBeNull()
  })

  it('sends the same request id again when the answer was lost, so the unit is opened once, and a new one for the next unit', async () => {
    let attempt = 0
    const calls = serveApi(fetchMock, {
      'POST /api/v1/market/storage/small/open': () => (++attempt === 3 ? respond(201, { ...aMarket(), bought: 9, opened: aFind() }) : respond(500, { message: 'Server error' })),
      'GET /api/v1/runs/current': noRun,
    })
    const store = useMarketStore()
    const idOf = (index: number): string => (calls.filter((call) => call.method === 'POST')[index]?.body as { request_id: string }).request_id

    await expect(store.openStorage('small')).rejects.toBeInstanceOf(ApiError)
    await expect(store.openStorage('small')).rejects.toBeInstanceOf(ApiError)
    await store.openStorage('small')
    await store.openStorage('small').catch(() => undefined)

    expect(idOf(1)).toBe(idOf(0))
    expect(idOf(2)).toBe(idOf(0))
    // The unit was answered, so the next one is a new intention.
    expect(idOf(3)).not.toBe(idOf(2))
  })
})
