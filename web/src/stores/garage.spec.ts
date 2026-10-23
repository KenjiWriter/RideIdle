import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/client'
import { respond, serveApi } from '@/test/fetchServer'
import { NOW, aCar, aGarage, aJob, aPart, anOffer } from '@/test/garageFixtures'

import { PURCHASE_ID_LIFETIME_MS, useGarageStore } from './garage'

describe('garage store', () => {
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

  it('loads the garage and counts time with the clock of the server, not its own', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(NOW - 90 * 60_000))
    serveApi(fetchMock, { 'GET /api/v1/garage': () => respond(200, aGarage({ inventory: [aPart()] })) })
    const store = useGarageStore()

    await store.loadGarage()

    expect(store.loaded).toBe(true)
    expect(store.garage?.inventory).toHaveLength(1)
    // This device is 90 minutes behind the server, and the store knows it.
    expect(store.serverNow()).toBe(NOW)

    vi.advanceTimersByTime(5_000)

    expect(store.serverNow()).toBe(NOW + 5_000)
  })

  describe('the level of the car', () => {
    const atLevel = (level: number, tierUnlock: { tier: number; level: number } | null, total = 0) =>
      aGarage({ car: aCar({ level, xp: { total, level_start: 0, next_level_at: 1 }, tier_unlock: tierUnlock }) })

    it('does not call the first answer a rise', async () => {
      serveApi(fetchMock, { 'GET /api/v1/garage': () => respond(200, atLevel(3, { tier: 3, level: 6 })) })
      const store = useGarageStore()

      await store.loadGarage()

      expect(store.levelUp).toBeNull()
    })

    it('says that the car rose, once it is seen at a higher level than before', async () => {
      const store = useGarageStore()
      serveApi(fetchMock, { 'GET /api/v1/garage': () => respond(200, atLevel(3, { tier: 3, level: 6 })) })
      await store.loadGarage()

      serveApi(fetchMock, { 'GET /api/v1/garage': () => respond(200, atLevel(4, { tier: 3, level: 6 })) })
      await store.loadGarage()

      // Level 4 opens no tier: 3 is for level 6.
      expect(store.levelUp).toEqual({ level: 4, tier: null })

      store.acknowledgeLevelUp()
      expect(store.levelUp).toBeNull()
    })

    it('says which tier the rise opened', async () => {
      const store = useGarageStore()
      serveApi(fetchMock, { 'GET /api/v1/garage': () => respond(200, atLevel(5, { tier: 3, level: 6 })) })
      await store.loadGarage()

      serveApi(fetchMock, { 'GET /api/v1/garage': () => respond(200, atLevel(6, { tier: 4, level: 9 })) })
      await store.loadGarage()

      expect(store.levelUp).toEqual({ level: 6, tier: 3 })
    })

    it('says the highest tier that a long absence opened, when several levels came at once', async () => {
      const store = useGarageStore()
      serveApi(fetchMock, { 'GET /api/v1/garage': () => respond(200, atLevel(5, { tier: 3, level: 6 })) })
      await store.loadGarage()

      serveApi(fetchMock, { 'GET /api/v1/garage': () => respond(200, atLevel(9, { tier: 5, level: 12 })) })
      await store.loadGarage()

      expect(store.levelUp).toEqual({ level: 9, tier: 4 })
    })

    it('says nothing when the level has not changed', async () => {
      const store = useGarageStore()
      serveApi(fetchMock, { 'GET /api/v1/garage': () => respond(200, atLevel(3, { tier: 3, level: 6 })) })
      await store.loadGarage()
      await store.loadGarage()

      expect(store.levelUp).toBeNull()
    })
  })

  it('loads the shop with what the car says about each part, and the cash', async () => {
    serveApi(fetchMock, {
      'GET /api/v1/shop': () => respond(200, { data: { parts: [anOffer(), anOffer({ slug: 'street-tires-t2', mountable: false })], car: { id: 5, level: 2 } }, wallet: { cash_cents: 90_000, xp: 0 } }),
    })
    const store = useGarageStore()

    await store.loadShop()

    expect(store.shopLoaded).toBe(true)
    expect(store.offers.map((offer) => offer.slug)).toEqual(['sport-exhaust-t2', 'street-tires-t2'])
    expect(store.shopCar).toEqual({ id: 5, level: 2 })
    expect(store.cash?.cash_cents).toBe(90_000)
  })

  it('buys a part with a request id, and keeps the cash the server answered with', async () => {
    const calls = serveApi(fetchMock, {
      'POST /api/v1/shop/purchases': () => respond(201, { data: aPart(), purchase: { cost_cents: 47_500, repeated: false }, wallet: { cash_cents: 12_500, xp: 0 } }),
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })
    const store = useGarageStore()

    const result = await store.buy('sport-exhaust-t2')

    expect(result.data?.slug).toBe('sport-exhaust-t2')
    expect(store.cash?.cash_cents).toBe(12_500)
    expect(calls[0]?.body).toEqual({ part: 'sport-exhaust-t2', request_id: expect.any(String) })
  })

  it('sends the same request id again when the answer was lost, so the purchase is made once', async () => {
    let attempt = 0
    const calls = serveApi(fetchMock, {
      'POST /api/v1/shop/purchases': () => {
        attempt += 1

        return attempt === 1 ? respond(500, { message: 'Server error' }) : respond(200, { data: null, purchase: { cost_cents: 0, repeated: true }, wallet: { cash_cents: 12_500, xp: 0 } })
      },
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })
    const store = useGarageStore()

    await expect(store.buy('sport-exhaust-t2')).rejects.toBeInstanceOf(ApiError)
    const second = await store.buy('sport-exhaust-t2')

    const ids = calls.filter((call) => call.method === 'POST').map((call) => (call.body as { request_id: string }).request_id)

    expect(ids[0]).toBe(ids[1])
    expect(second.purchase.repeated).toBe(true)
  })

  it('uses a new request id for another part, for a purchase after the last one was answered, and after a minute', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(NOW))
    const calls = serveApi(fetchMock, {
      'POST /api/v1/shop/purchases': () => respond(500, { message: 'Server error' }),
    })
    const store = useGarageStore()
    const idOf = (index: number): string => (calls.filter((call) => call.method === 'POST')[index]?.body as { request_id: string }).request_id

    await store.buy('sport-exhaust-t2').catch(() => undefined)
    await store.buy('disc-brakes-t2').catch(() => undefined)
    await store.buy('disc-brakes-t2').catch(() => undefined)
    vi.advanceTimersByTime(PURCHASE_ID_LIFETIME_MS + 1)
    await store.buy('disc-brakes-t2').catch(() => undefined)

    expect(idOf(1)).not.toBe(idOf(0))
    expect(idOf(2)).toBe(idOf(1))
    expect(idOf(3)).not.toBe(idOf(2))
  })

  it('queues a job and takes the garage from the answer', async () => {
    const calls = serveApi(fetchMock, {
      'POST /api/v1/garage/jobs': () => respond(201, aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [aJob()] } })),
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })
    const store = useGarageStore()

    await store.queue('mount', 11)

    expect(calls[0]?.body).toEqual({ kind: 'mount', part: 11 })
    expect(store.garage?.workshop.jobs).toHaveLength(1)
  })

  it('lets the error of a refused job reach the screen', async () => {
    serveApi(fetchMock, {
      'POST /api/v1/garage/jobs': () => respond(422, { message: 'The workshop queue is full.', code: 'queue_full' }),
    })
    const store = useGarageStore()

    await expect(store.queue('mount', 11)).rejects.toMatchObject({ status: 422, code: 'queue_full', message: 'The workshop queue is full.' })
  })

  describe('cancelling a job', () => {
    const takeOff = aJob({ id: 1, kind: 'unmount', part: aPart({ id: 11, slot: 'tires', mounted: true, car_id: 5 }) })
    const putOn = aJob({ id: 2, status: 'queued', part: aPart({ id: 21, slot: 'tires', name: 'Street tires T2' }) })
    const queueOf = (jobs: ReturnType<typeof aJob>[]) => aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs } })

    it('cancels on the server, takes the garage from the answer and asks for the run again', async () => {
      const calls = serveApi(fetchMock, {
        'GET /api/v1/garage': () => respond(200, queueOf([takeOff, putOn])),
        'DELETE /api/v1/garage/jobs/2': () => respond(200, { ...queueOf([takeOff]), cancelled: [2] }),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })
      const store = useGarageStore()
      await store.loadGarage()

      const gone = await store.cancelJob(2)

      expect(calls.some((call) => call.method === 'DELETE' && call.url === '/api/v1/garage/jobs/2')).toBe(true)
      expect(gone.map((job) => job.id)).toEqual([2])
      expect(store.garage?.workshop.jobs.map((job) => job.id)).toEqual([1])
      expect(calls.some((call) => call.url === '/api/v1/runs/current')).toBe(true)
    })

    it('gives back the jobs that went with the one asked for, as they stood in the queue', async () => {
      serveApi(fetchMock, {
        'GET /api/v1/garage': () => respond(200, queueOf([takeOff, putOn])),
        'DELETE /api/v1/garage/jobs/1': () => respond(200, { ...queueOf([]), cancelled: [1, 2] }),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })
      const store = useGarageStore()
      await store.loadGarage()

      const gone = await store.cancelJob(1)

      expect(gone.map((job) => job.part.name)).toEqual([takeOff.part.name, 'Street tires T2'])
      expect(store.garage?.workshop.jobs).toEqual([])
    })

    it('keeps the queue it has when the server refuses, and lets the error reach the screen', async () => {
      serveApi(fetchMock, {
        'GET /api/v1/garage': () => respond(200, queueOf([takeOff])),
        'DELETE /api/v1/garage/jobs/1': () => respond(422, { message: 'That job is already done.', code: 'job_done' }),
      })
      const store = useGarageStore()
      await store.loadGarage()

      await expect(store.cancelJob(1)).rejects.toMatchObject({ status: 422, code: 'job_done', message: 'That job is already done.' })
      expect(store.garage?.workshop.jobs).toHaveLength(1)
    })
  })
})
