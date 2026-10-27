import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Run } from '@/api/types'
import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { useRunStore } from '@/stores/run'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'
import { liveFields } from '@/test/runFixtures'

import GameNav from './GameNav.vue'

const NOW = 1_792_692_000_000
const profile = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: false, has_recovery_code: false, identities: [] }

function aRun(overrides: Partial<Run> = {}): Run {
  return {
    id: 7,
    car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
    track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
    status: 'running',
    stop_reason: null,
    laps_done: 3,
    fuel_ml: 31_000,
    tank_ml: 45_000,
    cursor_ms: NOW,
    state_version: 4,
    prices: { fuel_cents_per_liter: 200, repair_cents: 3_000 },
    lap: { ms: { dry: 75_000 }, cash_cents: { dry: 1_550 }, xp: { dry: 25 }, fuel_ml: 1_500, car_xp: 10, night_ppm: 1_000_000 },
    server_time_ms: NOW + 10_000,
    ...liveFields(NOW),
    ...overrides,
  }
}

const fetchMock = vi.fn()
const mounted: { unmount: () => void }[] = []

/** What the server says when the lap is over: one more lap done, and the next lap begun where the last one ended. */
function answerAfterTheLap() {
  const next = aRun({ laps_done: 4, current_lap: { start_ms: NOW + 75_000, finish_ms: NOW + 150_000, ms: 75_000, weather: 'dry' } })

  return respond(200, { data: { ...next, server_time_ms: Date.now() }, wallet: { cash_cents: 313_000, xp: 7_825 }, settlements: [], summary: { laps: 0, cash_cents: 0, xp: 0, from_ms: null, to_ms: null, stop_reason: null } })
}

/** The nav of a screen of the game, with a run that is already known: a lap of 75 s that began ten seconds ago, the fourth of the run. */
async function show(run: Run = aRun()) {
  const calls = serveApi(fetchMock, {
    'GET /api/v1/me': () => respond(200, { data: profile }),
    'GET /api/v1/runs/current': answerAfterTheLap,
    'GET /api/v1/garage': () => respond(404, { message: 'none' }),
  })
  const view = await mountView(GameNav, '/race', { prepare: () => (useRunStore().run = run) })
  mounted.push(view.wrapper)
  await flushPromises()

  return { ...view, calls }
}

const asked = (calls: { url: string }[]) => calls.filter((call) => call.url.startsWith('/api/v1/runs/current')).length

describe('GameNav', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'setTimeout', 'clearTimeout', 'Date'] })
    vi.setSystemTime(NOW + 10_000)
  })

  afterEach(() => {
    mounted.splice(0).forEach((view) => view.unmount())
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('tells under the race which lap is in progress and how long it still lasts, by the clock of the server', async () => {
    const { wrapper } = await show()

    // Ten seconds into the fourth lap of 75 s.
    expect(wrapper.get('[data-test="nav-lap"]').text()).toBe('Lap 4 · 1:05')
    expect(wrapper.get('[data-test="nav-race"]').text()).toContain('Tournament')

    await vi.advanceTimersByTimeAsync(5_000)

    expect(wrapper.get('[data-test="nav-lap"]').text()).toBe('Lap 4 · 1:00')
  })

  it('goes on to the next lap by itself when the lap is over, and the server then says what really happened', async () => {
    const { wrapper, calls } = await show()

    // The lap ends 65 s from now; the clock rolls over to the next lap at once, and the server is asked a moment later.
    await vi.advanceTimersByTimeAsync(65_200)
    expect(wrapper.get('[data-test="nav-lap"]').text()).toBe('Lap 5 · 1:15')
    expect(asked(calls)).toBe(0)

    await vi.advanceTimersByTimeAsync(1_000)
    expect(asked(calls)).toBe(1)
    expect(wrapper.get('[data-test="nav-lap"]').text()).toBe('Lap 5 · 1:14')
  })

  it('says nothing for a car that stands, and keeps the entry of the race', async () => {
    const { wrapper } = await show(aRun({ status: 'stopped', stop_reason: 'breakdown', current_lap: null }))

    expect(wrapper.get('[data-test="nav-lap"]').text()).toBe('')
    expect(wrapper.get('[data-test="nav-race"]').text()).toBe('Tournament')
  })

  it('says nothing before the run is known', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/me': () => respond(200, { data: profile }), 'GET /api/v1/garage': () => respond(404, { message: 'none' }) })
    const view = await mountView(GameNav, '/race')
    mounted.push(view.wrapper)
    await flushPromises()

    expect(view.wrapper.get('[data-test="nav-lap"]').text()).toBe('')
    expect(asked(calls)).toBe(0)
  })

  it('has the line in every tab, empty in the others, so that the icons stand level', async () => {
    const { wrapper } = await show()

    const tabs = wrapper.findAll('a')

    expect(tabs).toHaveLength(5)
    expect(tabs.every((tab) => tab.find('.navb-sub').exists())).toBe(true)
    expect(tabs.filter((tab) => tab.get('.navb-sub').text() !== '')).toHaveLength(1)
  })

  it('has a tab for every screen that the tutorial points at, named by the screen', async () => {
    const { wrapper } = await show()

    expect(wrapper.findAll('a').map((tab) => tab.attributes('data-tutorial'))).toEqual(['map', 'garage', 'race', 'workshop', 'shop'])
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="nav-lap"]').text()).toBe('Okr. 4 · 1:05')
  })
})
