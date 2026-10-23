import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/client'
import type { Run, RunResponse, RunSummary, StarterCarsResponse } from '@/api/types'
import { readSeen, storeSeen } from '@/features/run/seen'
import { liveFields } from '@/test/runFixtures'

import { AWAY_MIN_MS, REQUEST_ID_LIFETIME_MS, useRunStore } from './run'
import { useSessionStore } from './session'

const NOW = 1_792_692_000_000

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function aRun(overrides: Partial<Run> = {}): Run {
  return {
    id: 7,
    car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
    track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
    status: 'running',
    stop_reason: null,
    laps_done: 0,
    fuel_ml: 45_000,
    tank_ml: 45_000,
    cursor_ms: NOW,
    state_version: 0,
    prices: { fuel_cents_per_liter: 200, repair_cents: 3_000 },
    lap: { ms: { dry: 75_000 }, cash_cents: { dry: 1_550 }, xp: { dry: 25 }, fuel_ml: 1_500, car_xp: 10, night_ppm: 1_000_000 },
    server_time_ms: NOW,
    ...liveFields(NOW),
    ...overrides,
  }
}

function aSummary(overrides: Partial<RunSummary> = {}): RunSummary {
  return { laps: 0, cash_cents: 0, xp: 0, from_ms: null, to_ms: null, stop_reason: null, ...overrides }
}

function aResponse(run: Run, summary?: RunSummary): RunResponse {
  return { data: run, wallet: { cash_cents: 4_650, xp: 75 }, settlements: [], ...(summary ? { summary } : {}) }
}

const starters: StarterCarsResponse = {
  data: [],
  track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
}

describe('run store', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  function lastUrl(): string {
    return (fetchMock.mock.calls.at(-1) as [string, RequestInit])[0]
  }

  it('starts with nothing known', () => {
    const store = useRunStore()

    expect(store.run).toBeNull()
    expect(store.loaded).toBe(false)
  })

  it('tells the time of the server by the clock of the last answer, and goes on keeping it', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(1_000_000)
    // The clock of the server is 5 s ahead of the one of the device.
    fetchMock.mockResolvedValue(respond(200, aResponse(aRun({ server_time_ms: 1_005_000 }))))
    const store = useRunStore()

    await store.load()
    expect(store.serverNow()).toBe(1_005_000)

    vi.setSystemTime(1_030_000)
    expect(store.serverNow()).toBe(1_035_000)

    store.reset()
    expect(store.serverNow()).toBe(1_030_000)
    vi.useRealTimers()
  })

  it('loads the wrecks and the starting track', async () => {
    fetchMock.mockResolvedValue(respond(200, starters))
    const store = useRunStore()

    await store.loadStarters()

    expect(lastUrl()).toBe('/api/v1/starter-cars')
    expect(store.startTrack?.slug).toBe('zlomowo-street')
  })

  it('starts a run with the chosen wreck on the starting track and remembers its version', async () => {
    fetchMock.mockResolvedValueOnce(respond(200, starters)).mockResolvedValueOnce(respond(201, aResponse(aRun())))
    const store = useRunStore()
    await store.loadStarters()

    await store.start('bizon-m-2')

    const [url, init] = fetchMock.mock.calls[1] as [string, RequestInit]
    expect(url).toBe('/api/v1/runs')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body as string)).toEqual({ car: 'bizon-m-2', track: 'zlomowo-street' })
    expect(store.run?.id).toBe(7)
    expect(store.wallet?.cash_cents).toBe(4_650)
    expect(store.loaded).toBe(true)
    expect(readSeen()).toEqual({ runId: 7, version: 0 })
  })

  it('does not start without a known starting track', async () => {
    await expect(useRunStore().start('bizon-m-2')).rejects.toThrow('starting track')

    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('passes a refused start on, for example when the driver already has a run', async () => {
    fetchMock
      .mockResolvedValueOnce(respond(200, starters))
      .mockResolvedValueOnce(respond(409, { message: 'You already have a car on the track.', code: 'run_exists', run_id: 7 }))
    const store = useRunStore()
    await store.loadStarters()

    const failure = await store.start('bizon-m-2').catch((error: unknown) => error)

    expect(failure).toBeInstanceOf(ApiError)
    expect((failure as ApiError).code).toBe('run_exists')
    expect(store.run).toBeNull()
  })

  it('knows that a driver has no run', async () => {
    fetchMock.mockResolvedValue(respond(404, { message: 'No run.', code: 'no_run' }))
    const store = useRunStore()

    await store.load()

    expect(store.run).toBeNull()
    expect(store.loaded).toBe(true)
  })

  it('passes other errors of the load on', async () => {
    fetchMock.mockResolvedValue(respond(500, { message: 'Server error' }))
    const store = useRunStore()

    await expect(store.load()).rejects.toBeInstanceOf(ApiError)
    expect(store.loaded).toBe(false)
  })

  it('loads the run of the first visit without a summary and remembers what it saw', async () => {
    fetchMock.mockResolvedValue(respond(200, aResponse(aRun({ state_version: 2 }), aSummary())))
    const store = useRunStore()

    await store.load()

    expect(lastUrl()).toBe('/api/v1/runs/current')
    expect(store.run?.state_version).toBe(2)
    expect(store.away).toBeNull()
    expect(readSeen()).toEqual({ runId: 7, version: 2 })
  })

  describe('after an absence', () => {
    const long = { laps: 312, cash_cents: 486_000, xp: 7_800, from_ms: NOW - 8 * 3_600_000, to_ms: NOW - 60_000, stop_reason: 'fuel_empty' as const }

    it('asks for everything since the version it saw and keeps the summary until it is dismissed', async () => {
      storeSeen({ runId: 7, version: 3 })
      fetchMock.mockResolvedValue(respond(200, aResponse(aRun({ state_version: 5, stop_reason: 'fuel_empty', status: 'stopped' }), aSummary(long))))
      const store = useRunStore()

      await store.load()

      expect(lastUrl()).toBe('/api/v1/runs/current?since_version=3')
      expect(store.away).toEqual(aSummary(long))
      // Not seen yet: a reload before the driver reads it must show the summary again.
      expect(readSeen()).toEqual({ runId: 7, version: 3 })
    })

    it('shows the same summary again after a reload while it was not dismissed', async () => {
      storeSeen({ runId: 7, version: 3 })
      // A Response body can be read once, so every call gets a new one.
      fetchMock.mockImplementation(() => Promise.resolve(respond(200, aResponse(aRun({ state_version: 5 }), aSummary(long)))))

      await useRunStore().load()
      setActivePinia(createPinia())
      const reloaded = useRunStore()
      await reloaded.load()

      expect(lastUrl()).toBe('/api/v1/runs/current?since_version=3')
      expect(reloaded.away).toEqual(aSummary(long))
    })

    it('counts everything as seen once the summary is dismissed', async () => {
      storeSeen({ runId: 7, version: 3 })
      fetchMock.mockResolvedValue(respond(200, aResponse(aRun({ state_version: 5 }), aSummary(long))))
      const store = useRunStore()
      await store.load()

      store.dismissAway()

      expect(store.away).toBeNull()
      expect(readSeen()).toEqual({ runId: 7, version: 5 })
    })

    it('does not bother the driver with a short absence, such as a reload', async () => {
      storeSeen({ runId: 7, version: 3 })
      const short = aSummary({ laps: 1, cash_cents: 1_550, xp: 25, from_ms: NOW - AWAY_MIN_MS + 1_000, to_ms: NOW })
      fetchMock.mockResolvedValue(respond(200, aResponse(aRun({ state_version: 4 }), short)))
      const store = useRunStore()

      await store.load()

      expect(store.away).toBeNull()
      expect(readSeen()).toEqual({ runId: 7, version: 4 })
    })

    it('ignores what was seen of another run', async () => {
      storeSeen({ runId: 99, version: 3 })
      fetchMock.mockResolvedValue(respond(200, aResponse(aRun({ id: 7, state_version: 5 }), aSummary(long))))
      const store = useRunStore()

      await store.load()

      expect(store.away).toBeNull()
      expect(readSeen()).toEqual({ runId: 7, version: 5 })
    })
  })

  describe('refreshing a screen that stays open', () => {
    it('brings the run up to date and counts it as seen', async () => {
      fetchMock.mockResolvedValue(respond(200, aResponse(aRun({ laps_done: 4, state_version: 1 }))))
      const store = useRunStore()

      await store.refresh()

      expect(lastUrl()).toBe('/api/v1/runs/current')
      expect(store.run?.laps_done).toBe(4)
      expect(readSeen()).toEqual({ runId: 7, version: 1 })
    })

    it('leaves the pending summary and what was seen alone', async () => {
      storeSeen({ runId: 7, version: 3 })
      const long = aSummary({ laps: 312, cash_cents: 1, xp: 1, from_ms: NOW - 3_600_000, to_ms: NOW })
      fetchMock.mockResolvedValueOnce(respond(200, aResponse(aRun({ state_version: 5 }), long)))
      const store = useRunStore()
      await store.load()

      fetchMock.mockResolvedValueOnce(respond(200, aResponse(aRun({ state_version: 6, laps_done: 9 }))))
      await store.refresh()

      expect(store.run?.laps_done).toBe(9)
      expect(store.away).toEqual(long)
      expect(readSeen()).toEqual({ runId: 7, version: 3 })
    })
  })

  describe('refuelling', () => {
    const refuelAnswer = (run: Run, refuel = { added_ml: 30_000, cost_cents: 6_000, repeated: false }) => ({
      ...aResponse(run),
      wallet: { cash_cents: 1_000, xp: 75 },
      refuel,
    })

    async function loaded(overrides: Partial<Run> = {}) {
      fetchMock.mockResolvedValueOnce(respond(200, aResponse(aRun({ state_version: 2, fuel_ml: 15_000, ...overrides }))))
      const store = useRunStore()
      await store.load()
      fetchMock.mockReset()

      return store
    }

    function lastBody(): Record<string, unknown> {
      return JSON.parse((fetchMock.mock.calls.at(-1) as [string, RequestInit])[1].body as string)
    }

    it('fills the tank: sends a request id and no amount, and takes over the answer', async () => {
      const store = await loaded()
      fetchMock.mockResolvedValue(respond(200, refuelAnswer(aRun({ state_version: 3, fuel_ml: 45_000 }))))

      const result = await store.refuel(null)

      expect(lastUrl()).toBe('/api/v1/runs/7/refuel')
      expect((fetchMock.mock.calls.at(-1) as [string, RequestInit])[1].method).toBe('POST')
      expect(Object.keys(lastBody()).sort()).toEqual(['request_id'])
      expect(typeof lastBody().request_id).toBe('string')
      expect(result).toEqual({ added_ml: 30_000, cost_cents: 6_000, repeated: false })
      expect(store.run?.fuel_ml).toBe(45_000)
      expect(store.wallet?.cash_cents).toBe(1_000)
      expect(readSeen()).toEqual({ runId: 7, version: 3 })
    })

    it('buys the liters asked for', async () => {
      const store = await loaded()
      fetchMock.mockResolvedValue(respond(200, refuelAnswer(aRun({ state_version: 3 }), { added_ml: 10_000, cost_cents: 2_000, repeated: false })))

      await store.refuel(10)

      expect(lastBody()).toMatchObject({ liters: 10 })
    })

    it('does not move what was seen while a summary of an absence waits', async () => {
      storeSeen({ runId: 7, version: 1 })
      fetchMock.mockResolvedValueOnce(
        respond(200, aResponse(aRun({ state_version: 2 }), aSummary({ laps: 50, cash_cents: 1, xp: 1, from_ms: NOW - 3_600_000, to_ms: NOW }))),
      )
      const store = useRunStore()
      await store.load()
      fetchMock.mockResolvedValue(respond(200, refuelAnswer(aRun({ state_version: 3 }))))

      await store.refuel(null)

      expect(store.away).not.toBeNull()
      expect(readSeen()).toEqual({ runId: 7, version: 1 })
    })

    it('repeats a request whose answer was lost with the same id, and uses a new one for the next refuel', async () => {
      const store = await loaded()
      fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
      await expect(store.refuel(null)).rejects.toBeInstanceOf(TypeError)
      const lost = lastBody().request_id

      fetchMock.mockResolvedValueOnce(respond(200, refuelAnswer(aRun({ state_version: 3 }), { added_ml: 0, cost_cents: 0, repeated: true })))
      await store.refuel(null)
      expect(lastBody().request_id).toBe(lost)

      fetchMock.mockResolvedValueOnce(respond(200, refuelAnswer(aRun({ state_version: 4 }))))
      await store.refuel(null)
      expect(lastBody().request_id).not.toBe(lost)
    })

    it('keeps the id after a refused refuel, so trying again is the same intention', async () => {
      const store = await loaded()
      fetchMock.mockResolvedValueOnce(respond(422, { message: 'Not enough cash.', code: 'insufficient_funds' }))

      const failure = await store.refuel(null).catch((error: unknown) => error)

      expect((failure as ApiError).code).toBe('insufficient_funds')
      const refused = lastBody().request_id

      fetchMock.mockResolvedValueOnce(respond(200, refuelAnswer(aRun({ state_version: 3 }))))
      await store.refuel(null)

      expect(lastBody().request_id).toBe(refused)
    })

    it('uses another id for another amount', async () => {
      const store = await loaded()
      fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
      await store.refuel(null).catch(() => undefined)
      const full = lastBody().request_id

      fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
      await store.refuel(10).catch(() => undefined)

      expect(lastBody().request_id).not.toBe(full)
    })

    it('uses a new id once the old one is a minute old, because the driver means a new refuel', async () => {
      vi.useFakeTimers({ toFake: ['Date'] })

      try {
        const store = await loaded()
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
        await store.refuel(null).catch(() => undefined)
        const old = lastBody().request_id

        vi.setSystemTime(Date.now() + REQUEST_ID_LIFETIME_MS + 1)
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
        await store.refuel(null).catch(() => undefined)

        expect(lastBody().request_id).not.toBe(old)
      } finally {
        vi.useRealTimers()
      }
    })

    it('cannot refuel without a run', async () => {
      await expect(useRunStore().refuel(null)).rejects.toThrow('no run')

      expect(fetchMock).not.toHaveBeenCalled()
    })
  })

  describe('repairing', () => {
    async function broken() {
      fetchMock.mockResolvedValueOnce(
        respond(200, aResponse(aRun({ state_version: 2, status: 'stopped', stop_reason: 'breakdown' }))),
      )
      const store = useRunStore()
      await store.load()
      fetchMock.mockReset()

      return store
    }

    function lastBody(): Record<string, unknown> {
      return JSON.parse((fetchMock.mock.calls.at(-1) as [string, RequestInit])[1].body as string)
    }

    it('repairs the car: sends a request id and takes over the answer', async () => {
      const store = await broken()
      fetchMock.mockResolvedValue(
        respond(200, { ...aResponse(aRun({ state_version: 3 })), wallet: { cash_cents: 1_650, xp: 75 }, repair: { repaired: true, cost_cents: 3_000, repeated: false } }),
      )

      const result = await store.repair()

      expect(lastUrl()).toBe('/api/v1/runs/7/repair')
      expect((fetchMock.mock.calls.at(-1) as [string, RequestInit])[1].method).toBe('POST')
      expect(Object.keys(lastBody())).toEqual(['request_id'])
      expect(result).toEqual({ repaired: true, cost_cents: 3_000, repeated: false })
      expect(store.run?.status).toBe('running')
      expect(store.wallet?.cash_cents).toBe(1_650)
      expect(readSeen()).toEqual({ runId: 7, version: 3 })
    })

    it('repeats a request whose answer was lost with the same id', async () => {
      const store = await broken()
      fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
      await expect(store.repair()).rejects.toBeInstanceOf(TypeError)
      const lost = lastBody().request_id

      fetchMock.mockResolvedValueOnce(
        respond(200, { ...aResponse(aRun({ state_version: 3 })), repair: { repaired: false, cost_cents: 0, repeated: true } }),
      )
      const result = await store.repair()

      expect(lastBody().request_id).toBe(lost)
      expect(result.repeated).toBe(true)
    })

    it('does not mix up its request id with the one of a refuel', async () => {
      const store = await broken()
      fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
      await store.repair().catch(() => undefined)
      const repair = lastBody().request_id

      fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
      await store.refuel(null).catch(() => undefined)

      expect(lastBody().request_id).not.toBe(repair)
    })

    it('passes a refused repair on', async () => {
      const store = await broken()
      fetchMock.mockResolvedValue(respond(422, { message: 'Not enough cash.', code: 'insufficient_funds' }))

      const failure = await store.repair().catch((error: unknown) => error)

      expect((failure as ApiError).code).toBe('insufficient_funds')
      expect(store.run?.stop_reason).toBe('breakdown')
    })

    it('cannot repair without a run', async () => {
      await expect(useRunStore().repair()).rejects.toThrow('no run')
    })
  })

  describe('towing', () => {
    const offer = { available: true, delay_ms: 300_000 }

    async function stuck() {
      fetchMock.mockResolvedValueOnce(
        respond(200, { ...aResponse(aRun({ state_version: 2, status: 'stopped', stop_reason: 'breakdown' })), tow: offer }),
      )
      const store = useRunStore()
      await store.load()
      fetchMock.mockReset()

      return store
    }

    it('knows from every answer whether the tow is on offer', async () => {
      const store = await stuck()
      expect(store.tow).toEqual(offer)

      fetchMock.mockResolvedValueOnce(respond(200, { ...aResponse(aRun({ state_version: 3 })), tow: { available: false, delay_ms: 300_000 } }))
      await store.refresh()

      expect(store.tow?.available).toBe(false)
    })

    it('asks for the tow without a body and takes over the answer', async () => {
      const store = await stuck()
      fetchMock.mockResolvedValue(
        respond(200, { ...aResponse(aRun({ state_version: 3, cursor_ms: NOW + 300_000 })), tow: { available: false, delay_ms: 300_000 }, towed: true }),
      )

      const towed = await store.towCar()

      const [url, init] = fetchMock.mock.calls.at(-1) as [string, RequestInit]
      expect(url).toBe('/api/v1/runs/7/tow')
      expect(init.method).toBe('POST')
      expect(init.body).toBeUndefined()
      expect(towed).toBe(true)
      expect(store.run?.status).toBe('running')
      expect(store.run?.cursor_ms).toBe(NOW + 300_000)
      expect(store.tow?.available).toBe(false)
      expect(readSeen()).toEqual({ runId: 7, version: 3 })
    })

    it('says when nothing was done', async () => {
      const store = await stuck()
      fetchMock.mockResolvedValue(respond(200, { ...aResponse(aRun({ state_version: 3 })), tow: { available: false, delay_ms: 300_000 }, towed: false }))

      expect(await store.towCar()).toBe(false)
    })

    it('passes a failure on', async () => {
      const store = await stuck()
      fetchMock.mockResolvedValue(respond(500, { message: 'Server error' }))

      await expect(store.towCar()).rejects.toBeInstanceOf(ApiError)
      expect(store.tow).toEqual(offer)
    })

    it('cannot tow without a run', async () => {
      await expect(useRunStore().towCar()).rejects.toThrow('no run')
    })

    it('forgets the offer on reset', async () => {
      const store = await stuck()

      store.reset()

      expect(store.tow).toBeNull()
    })
  })

  it('forgets everything on reset', async () => {
    fetchMock.mockResolvedValue(respond(200, aResponse(aRun())))
    const store = useRunStore()
    await store.load()

    store.reset()

    expect(store.run).toBeNull()
    expect(store.wallet).toBeNull()
    expect(store.loaded).toBe(false)
    expect(readSeen()).toBeNull()
  })

  it('is reset when the driver signs out', async () => {
    fetchMock.mockResolvedValue(respond(200, aResponse(aRun())))
    const store = useRunStore()
    await store.load()

    useSessionStore().signOut()

    expect(store.run).toBeNull()
    expect(readSeen()).toBeNull()
  })
})
