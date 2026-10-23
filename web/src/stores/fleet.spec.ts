import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/client'
import { aFleet } from '@/test/fleetFixtures'
import { respond, serveApi } from '@/test/fetchServer'
import { NOW } from '@/test/garageFixtures'

import { WRECK_ID_LIFETIME_MS, useFleetStore } from './fleet'

const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

describe('fleet store', () => {
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

  it('loads the cars and counts time with the clock of the server, not its own', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(NOW - 90 * 60_000))
    serveApi(fetchMock, { 'GET /api/v1/cars': () => respond(200, aFleet()) })
    const store = useFleetStore()

    await store.load()

    expect(store.loaded).toBe(true)
    expect(store.fleet?.cars).toHaveLength(2)
    expect(store.cash?.cash_cents).toBe(600_000)
    // This device is 90 minutes behind the server, and the store knows it.
    expect(store.serverNow()).toBe(NOW)
  })

  it('selects a car and asks for the car, the garage and the map again, because they are about the selected one', async () => {
    const calls = serveApi(fetchMock, {
      'POST /api/v1/cars/6/select': () => respond(200, aFleet({ selected: 6 })),
      'GET /api/v1/runs/current': noRun,
      'GET /api/v1/garage': () => respond(500, { message: 'Server error' }),
      'GET /api/v1/map': () => respond(500, { message: 'Server error' }),
    })
    const store = useFleetStore()

    await store.select(6)
    await vi.waitFor(() => expect(calls.map((call) => call.url)).toEqual(expect.arrayContaining(['/api/v1/runs/current', '/api/v1/garage', '/api/v1/map'])))

    expect(store.fleet?.selected).toBe(6)
  })

  it('buys a garage and takes the fleet from the answer', async () => {
    const calls = serveApi(fetchMock, { 'POST /api/v1/garages': () => respond(201, aFleet({}, 100_000)), 'GET /api/v1/runs/current': noRun })
    const store = useFleetStore()

    await store.buyGarage('kruszyn')

    expect(calls[0]?.body).toEqual({ city: 'kruszyn' })
    expect(store.cash?.cash_cents).toBe(100_000)
  })

  it('buys a wreck with a request id, and says which car it is', async () => {
    const calls = serveApi(fetchMock, { 'POST /api/v1/cars': () => respond(201, { ...aFleet(), bought: 9, repeated: false }), 'GET /api/v1/runs/current': noRun })
    const store = useFleetStore()

    const bought = await store.buyCar('bizon-m-2', 'kruszyn')

    expect(bought).toBe(9)
    expect(calls[0]?.body).toEqual({ vehicle: 'bizon-m-2', city: 'kruszyn', request_id: expect.any(String) })
  })

  it('answers null for a purchase that was made before', async () => {
    serveApi(fetchMock, { 'POST /api/v1/cars': () => respond(200, { ...aFleet(), bought: null, repeated: true }), 'GET /api/v1/runs/current': noRun })

    expect(await useFleetStore().buyCar('bizon-m-2', 'kruszyn')).toBeNull()
  })

  it('sends the same request id again when the answer was lost, so the wreck is bought once', async () => {
    let attempt = 0
    const calls = serveApi(fetchMock, {
      'POST /api/v1/cars': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : respond(200, { ...aFleet(), bought: null, repeated: true })),
      'GET /api/v1/runs/current': noRun,
    })
    const store = useFleetStore()

    await expect(store.buyCar('bizon-m-2', 'kruszyn')).rejects.toBeInstanceOf(ApiError)
    await store.buyCar('bizon-m-2', 'kruszyn')

    const ids = calls.filter((call) => call.method === 'POST').map((call) => (call.body as { request_id: string }).request_id)

    expect(ids[0]).toBe(ids[1])
  })

  it('uses a new request id for another wreck or city, for a purchase after the last one was answered, and after a minute', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(NOW))
    const calls = serveApi(fetchMock, { 'POST /api/v1/cars': () => respond(500, { message: 'Server error' }) })
    const store = useFleetStore()
    const idOf = (index: number): string => (calls.filter((call) => call.method === 'POST')[index]?.body as { request_id: string }).request_id

    await store.buyCar('rdzak-gt-4', 'zlomowo').catch(() => undefined)
    await store.buyCar('bizon-m-2', 'zlomowo').catch(() => undefined)
    await store.buyCar('bizon-m-2', 'kruszyn').catch(() => undefined)
    await store.buyCar('bizon-m-2', 'kruszyn').catch(() => undefined)
    vi.advanceTimersByTime(WRECK_ID_LIFETIME_MS + 1)
    await store.buyCar('bizon-m-2', 'kruszyn').catch(() => undefined)

    expect(idOf(1)).not.toBe(idOf(0))
    expect(idOf(2)).not.toBe(idOf(1))
    expect(idOf(3)).toBe(idOf(2))
    expect(idOf(4)).not.toBe(idOf(3))
  })
})
