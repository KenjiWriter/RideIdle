import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Run } from '@/api/types'
import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'
import { liveFields } from '@/test/runFixtures'

import RaceView from './RaceView.vue'

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
    lap: { ms: { dry: 75_000, rain: 84_000 }, cash_cents: { dry: 1_550, rain: 1_440 }, xp: { dry: 25, rain: 23 }, fuel_ml: 1_500, car_xp: 10 },
    server_time_ms: NOW + 10_000,
    ...liveFields(NOW),
    ...overrides,
  }
}

function runAnswer(run: Run) {
  return { data: run, wallet: { cash_cents: 312_450, xp: 7_800 }, settlements: [], summary: { laps: 0, cash_cents: 0, xp: 0, from_ms: null, to_ms: null, stop_reason: null } }
}

const fetchMock = vi.fn()
const mounted: { unmount: () => void }[] = []

/** The server answers with its clock at the moment of the answer, as the real one does, so the countdowns keep time over a poll. */
function serve(run: () => Response) {
  return serveApi(fetchMock, {
    'GET /api/v1/me': () => respond(200, { data: profile }),
    'GET /api/v1/runs/current': run,
    'GET /api/v1/garage': () => respond(404, { message: 'none' }),
  })
}

async function show(run: Run = aRun()) {
  const calls = serve(() => respond(200, runAnswer({ ...run, server_time_ms: Date.now() })))
  const view = await mountView(RaceView, '/race')
  mounted.push(view.wrapper)
  await flushPromises()

  return { ...view, calls }
}

describe('RaceView', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
    // The clock of the device is the clock of the server at the first answer, so the countdowns can be read exactly.
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'setTimeout', 'clearTimeout', 'Date'] })
    vi.setSystemTime(NOW + 10_000)
  })

  afterEach(() => {
    mounted.splice(0).forEach((view) => view.unmount())
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false })
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('shows the track, the lap in progress, the car and the weather, with the race marked live', async () => {
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="track"]').text()).toBe('Złomowo Street Sprint')
    expect(wrapper.get('[data-test="sub"]').text()).toBe('Lap 4 · Rdzak GT-4 · dry')
    expect(wrapper.get('[data-test="status"]').text()).toBe('LIVE')
    expect(wrapper.get('[data-test="status"]').classes()).toContain('chip-cy')
  })

  it('counts down the lap in progress in the ring, by the clock of the server', async () => {
    const { wrapper } = await show()

    // Ten seconds into a lap of 75 s.
    expect(wrapper.get('[data-test="ring-value"]').text()).toBe('1:05')
    expect(wrapper.get('[data-test="ring"]').attributes('aria-label')).toBe('Lap 13 percent done, 1:05 to go')

    await vi.advanceTimersByTimeAsync(5_000)

    expect(wrapper.get('[data-test="ring-value"]').text()).toBe('1:00')
    expect(wrapper.get('[data-test="ring-arc"]').attributes('stroke-dasharray')).toBe('60.3 301.6')
  })

  it('says what the lap takes and pays, in the weather of the lap', async () => {
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="lap-number"]').text()).toBe('4')
    expect(wrapper.get('[data-test="lap-time"]').text()).toBe('1:15.0')
    expect(wrapper.get('[data-test="lap-pays"]').text()).toBe('$15.50')
    expect(wrapper.get('[data-test="lap-cash"]').text()).toBe('+$15.50')
    expect(wrapper.get('[data-test="lap-xp"]').text()).toBe('+25 XP')
    // The car levels on its laps, whatever the weather: the same in the rain.
    expect(wrapper.get('[data-test="lap-car-xp"]').text()).toBe('+10 car XP')
  })

  it('tells the forecast, and says nothing about the lap when the weather does not change it', async () => {
    const { wrapper } = await show(aRun({ weather: { now: 'dry', ends_ms: NOW + 10_000 + 750_000, next: 'rain' } }))

    expect(wrapper.get('[data-test="weather-forecast"]').text()).toBe('Now: dry. In 12 min 30 s: rain.')
    expect(wrapper.find('[data-test="weather-lap"]').exists()).toBe(false)
  })

  it('says that the weather stays, when the next segment is the same', async () => {
    const { wrapper } = await show(aRun({ weather: { now: 'dry', ends_ms: NOW + 10_000 + 60_000, next: 'dry' } }))

    expect(wrapper.get('[data-test="weather-forecast"]').text()).toBe('Now: dry, and the same after the change in 1 min 00 s.')
  })

  it('shows the lap of the rain with its own time and pay, and what it is in the dry', async () => {
    const { wrapper } = await show(
      aRun({
        weather: { now: 'rain', ends_ms: NOW + 10_000 + 300_000, next: 'dry' },
        current_lap: { start_ms: NOW, finish_ms: NOW + 84_000, ms: 84_000, weather: 'rain' },
      }),
    )

    expect(wrapper.get('[data-test="sub"]').text()).toBe('Lap 4 · Rdzak GT-4 · rain')
    expect(wrapper.get('[data-test="lap-time"]').text()).toBe('1:24.0')
    expect(wrapper.get('[data-test="lap-pays"]').text()).toBe('$14.40')
    expect(wrapper.get('[data-test="lap-xp"]').text()).toBe('+23 XP')
    expect(wrapper.get('[data-test="weather-lap"]').text()).toBe('A lap in this weather: 1:24.0, paying $14.40 (dry: 1:15.0).')
  })

  it('has the board with the driver alone on it, and says when the others come', async () => {
    const { wrapper } = await show()

    expect(wrapper.findAll('[data-test="board"] .lbr')).toHaveLength(2)
    expect(wrapper.get('[data-test="board"] .lbr.me').text()).toContain('You · Rdzak GT-4')
    expect(wrapper.get('[data-test="board"] .lbr.me').text()).toContain('1:15.0')
    expect(wrapper.get('[data-test="board-later"]').text()).toBe('Bots and the ghosts of other drivers come later. For now you race alone.')
  })

  it('shows the tank, with the refuel', async () => {
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="fuel-level"]').text()).toBe('31 l / 45 l')
    expect(wrapper.find('[data-test="refuel-full"]').exists()).toBe(true)
  })

  it('shows a car that stands with no lap in progress, why, and the way out', async () => {
    const { wrapper } = await show(aRun({ status: 'stopped', stop_reason: 'breakdown', current_lap: null }))

    expect(wrapper.get('[data-test="status"]').text()).toBe('Broken down')
    expect(wrapper.get('[data-test="status"]').classes()).toContain('chip-bad')
    expect(wrapper.get('[data-test="ring-value"]').text()).toBe('—')
    expect(wrapper.find('[data-test="ring-arc"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="ring"]').attributes('aria-label')).toBe('The car stands, no lap is in progress')
    expect(wrapper.get('[data-test="sub"]').text()).toBe('Done: 3 · Rdzak GT-4 · dry')
    expect(wrapper.get('[data-test="banner"]').text()).toContain('broke down')
    expect(wrapper.find('[data-test="repair-button"]').exists()).toBe(true)
  })

  it('counts the wait of the tow in the ring', async () => {
    const { wrapper } = await show(aRun({ cursor_ms: NOW + 10_000 + 300_000, current_lap: null }))

    expect(wrapper.get('[data-test="status"]').text()).toBe('Towed')
    expect(wrapper.get('[data-test="ring-value"]').text()).toBe('5:00')
    expect(wrapper.get('[data-test="ring"]').text()).toContain('TOWED')

    await vi.advanceTimersByTimeAsync(60_000)
    expect(wrapper.get('[data-test="ring-value"]').text()).toBe('4:00')
  })

  it('asks the server what the lap paid as soon as the lap is over, and not before', async () => {
    // The regular poll is not asked: this is about the one that comes with the end of the lap.
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true })
    const { calls } = await show()
    const asked = () => calls.filter((call) => call.url.startsWith('/api/v1/runs/current')).length
    expect(asked()).toBe(1)

    // The lap of 75 s began 10 s ago.
    await vi.advanceTimersByTimeAsync(64_000)
    expect(asked()).toBe(1)

    await vi.advanceTimersByTimeAsync(2_000)
    expect(asked()).toBe(2)
  })

  it('has the entry of the race in the navigation, marked', async () => {
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="nav-race"]').text()).toBe('Tournament')
    expect(wrapper.get('[data-test="nav-race"]').classes()).toContain('on')
    expect(wrapper.get('[data-test="nav-garage"]').classes()).not.toContain('on')
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="status"]').text()).toBe('NA ŻYWO')
    expect(wrapper.get('[data-test="sub"]').text()).toBe('Okrążenie 4 · Rdzak GT-4 · sucho')
    expect(wrapper.get('[data-test="nav-race"]').text()).toBe('Turniej')

    await setLocale('en')
  })

  it('sends a driver without a run to the choice of a wreck', async () => {
    serveApi(fetchMock, { 'GET /api/v1/runs/current': () => respond(404, { message: 'none', code: 'no_run' }) })
    const { router } = await mountView(RaceView, '/race')
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('choose'))
  })

  it('says so when the run cannot be loaded, and loads it again on request', async () => {
    serveApi(fetchMock, { 'GET /api/v1/runs/current': () => respond(500, { message: 'Server error' }) })
    const { wrapper } = await mountView(RaceView, '/race')
    mounted.push(wrapper)
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The state of your car could not be loaded.')

    serveApi(fetchMock, { 'GET /api/v1/runs/current': () => respond(200, runAnswer(aRun({ server_time_ms: Date.now() }))) })
    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="track"]').text()).toBe('Złomowo Street Sprint')
  })
})
