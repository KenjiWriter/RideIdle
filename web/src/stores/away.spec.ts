import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { AwayAccount } from '@/api/types'
import { respond, serveApi } from '@/test/fetchServer'

import { useAwayStore } from './away'

const NOW = 1_792_692_000_000

function aSummary(overrides: Partial<AwayAccount> = {}): AwayAccount {
  return {
    pending: true,
    from_ms: NOW - 3_600_000,
    to_ms: NOW,
    away_minutes: 60,
    earned_cents: 4_500,
    cars: [{ id: 2, name: 'Bizon M-2', played: false, earned_cents: 4_500, laps: 12, waiting: null }],
    jobs: [],
    journeys: [],
    ...overrides,
  }
}

describe('away store', () => {
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

  it('keeps the summary of a break that has something to tell of beyond the car that is played, and shows it', async () => {
    serveApi(fetchMock, { 'GET /api/v1/away': () => respond(200, { data: aSummary(), now_ms: NOW }) })
    const away = useAwayStore()

    await away.load()

    expect(away.summary?.earned_cents).toBe(4_500)
    expect(away.visible).toBe(true)
  })

  it('ends a break at once when there is only the car that is played to tell of, because the card of the garage tells of it', async () => {
    const calls = serveApi(fetchMock, {
      'GET /api/v1/away': () => respond(200, { data: aSummary({ cars: [{ id: 1, name: 'Rdzak GT-4', played: true, earned_cents: 9_000, laps: 40, waiting: null }] }), now_ms: NOW }),
      'POST /api/v1/away/seen': () => respond(200, { data: { pending: false }, now_ms: NOW }),
    })
    const away = useAwayStore()

    await away.load()

    expect(away.summary).toBeNull()
    expect(away.visible).toBe(false)
    expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/away/seen')).toBe(true)
  })

  it('has nothing when there is no break', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/away': () => respond(200, { data: { pending: false }, now_ms: NOW }) })
    const away = useAwayStore()

    await away.load()

    expect(away.summary).toBeNull()
    expect(calls.some((call) => call.method === 'POST')).toBe(false)
  })

  it('tells the server when the driver has seen it, and forgets it', async () => {
    const calls = serveApi(fetchMock, {
      'GET /api/v1/away': () => respond(200, { data: aSummary({ jobs: [{ kind: 'upgrade', part: 'Street tires', finished_ms: NOW }] }), now_ms: NOW }),
      'POST /api/v1/away/seen': () => respond(200, { data: { pending: false }, now_ms: NOW }),
    })
    const away = useAwayStore()
    await away.load()

    await away.dismiss()

    expect(away.summary).toBeNull()
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(1)
  })
})
