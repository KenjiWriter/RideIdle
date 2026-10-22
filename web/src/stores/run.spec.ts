import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/client'
import type { Run, RunResponse, RunSummary, StarterCarsResponse } from '@/api/types'
import { readSeen, storeSeen } from '@/features/run/seen'

import { AWAY_MIN_MS, useRunStore } from './run'
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
    lap: { ms: { dry: 75_000 }, cash_cents: { dry: 1_550 }, xp: { dry: 25 }, fuel_ml: 1_500 },
    server_time_ms: NOW,
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
