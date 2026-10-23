import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Run, RunSummary } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { POLL_MS } from '@/features/run/useRunPolling'
import { readSeen, storeSeen } from '@/features/run/seen'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import GarageView from './GarageView.vue'

const NOW = 1_792_692_000_000

const profile = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: false }

function aRun(overrides: Partial<Run> = {}): Run {
  return {
    id: 7,
    car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
    track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
    status: 'running',
    stop_reason: null,
    laps_done: 312,
    fuel_ml: 12_000,
    tank_ml: 45_000,
    cursor_ms: NOW,
    state_version: 4,
    lap: { ms: { dry: 75_000, rain: 84_391 }, cash_cents: { dry: 1_550, rain: 1_442 }, xp: { dry: 25, rain: 23 }, fuel_ml: 1_500 },
    server_time_ms: NOW,
    ...overrides,
  }
}

function aSummary(overrides: Partial<RunSummary> = {}): RunSummary {
  return { laps: 0, cash_cents: 0, xp: 0, from_ms: null, to_ms: null, stop_reason: null, ...overrides }
}

function runAnswer(run: Run, summary: RunSummary = aSummary()) {
  return { data: run, wallet: { cash_cents: 312_450, xp: 7_800 }, settlements: [], summary }
}

const fetchMock = vi.fn()
const mountedScreens: { unmount: () => void }[] = []

async function show() {
  const mounted = await mountView(GarageView, '/garage')
  mountedScreens.push(mounted.wrapper)

  return mounted
}

/** The calls of the screen: the profile and the run. */
function serve(run: () => Response | Promise<Response>, me: () => Response = () => respond(200, { data: profile })) {
  return serveApi(fetchMock, { 'GET /api/v1/me': me, 'GET /api/v1/runs/current': run })
}

function open(overrides: Partial<Run> = {}, summary?: RunSummary) {
  const calls = serve(() => respond(200, runAnswer(aRun(overrides), summary)))

  return { calls, mounted: show() }
}

describe('GarageView', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
  })

  afterEach(() => {
    mountedScreens.splice(0).forEach((screen) => screen.unmount())
    vi.useRealTimers()
    vi.unstubAllGlobals()
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false })
  })

  it('welcomes the driver and shows the car on the track', async () => {
    const { mounted } = open()
    const { wrapper } = await mounted

    expect(wrapper.get('[data-test="welcome"]').text()).toBe('Loading…')

    await flushPromises()

    expect(wrapper.get('[data-test="welcome"]').text()).toBe('Welcome, Maciek_Turbo')
    expect(wrapper.get('[data-test="car"]').text()).toBe('Rdzak GT-4')
    expect(wrapper.get('[data-test="track"]').text()).toBe('Złomowo Street Sprint')
    expect(wrapper.get('[data-test="status"]').text()).toBe('Racing')
  })

  it('shows the cash, the experience, the laps and what a lap pays', async () => {
    const { mounted } = open()
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="cash"]').text()).toBe('$3,124.50')
    expect(wrapper.get('[data-test="xp"]').text()).toBe('7,800 XP')
    expect(wrapper.get('[data-test="laps"]').text()).toBe('312')
    expect(wrapper.get('[data-test="reward"]').text()).toBe('$15.50')
    expect(wrapper.get('[data-test="lap-time"]').text()).toBe('1:15.0')
    expect(wrapper.find('[data-test="banner"]').exists()).toBe(false)
  })

  it('shows how far the fuel goes', async () => {
    const { mounted } = open({ fuel_ml: 12_000 })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="fuel-level"]').text()).toBe('12 l / 45 l')
    expect(wrapper.get('[data-test="fuel-range"]').text()).toBe('Enough for 8 laps (about 10 min 00 s)')
    expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('27')
  })

  it('says one lap in the singular', async () => {
    const { mounted } = open({ fuel_ml: 1_800 })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="fuel-range"]').text()).toBe('Enough for 1 lap (about 1 min 15 s)')
  })

  it('counts the laps the Polish way', async () => {
    await setLocale('pl')
    const { mounted } = open({ fuel_ml: 33_000 })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="fuel-range"]').text()).toBe('Wystarczy na 22 okrążenia (ok. 27 min 30 s)')
    expect(wrapper.get('[data-test="status"]').text()).toBe('Jedzie')
  })

  it('says the tank is empty when there is no fuel for a lap', async () => {
    const { mounted } = open({ fuel_ml: 700, status: 'stopped', stop_reason: 'fuel_empty' })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="fuel-range"]').text()).toBe('The tank is empty.')
  })

  it('tells why the car stands', async () => {
    const { mounted } = open({ fuel_ml: 0, status: 'stopped', stop_reason: 'fuel_empty' })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="status"]').text()).toBe('Out of fuel')
    expect(wrapper.get('[data-test="banner"]').text()).toBe('The tank is empty. The car stands until you refuel it.')
  })

  it('tells that the car broke down', async () => {
    const { mounted } = open({ status: 'stopped', stop_reason: 'breakdown' })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="status"]').text()).toBe('Broken down')
    expect(wrapper.get('[data-test="banner"]').text()).toBe('The car broke down. It stands until it is repaired.')
  })

  describe('the summary of an absence', () => {
    const away = aSummary({ laps: 312, cash_cents: 486_000, xp: 7_800, from_ms: NOW - (8 * 3_600_000 + 12 * 60_000), to_ms: NOW - 60_000, stop_reason: 'fuel_empty' })

    it('shows what happened while the driver was away', async () => {
      storeSeen({ runId: 7, version: 1 })
      const { mounted, calls } = open({ state_version: 4 }, away)
      const { wrapper } = await mounted
      await flushPromises()

      expect(calls.find((call) => call.url.startsWith('/api/v1/runs/current'))?.url).toBe('/api/v1/runs/current?since_version=1')
      expect(wrapper.find('[data-test="away"]').exists()).toBe(true)
      expect(wrapper.get('[data-test="away-duration"]').text()).toBe('8 h 12 min')
      expect(wrapper.get('[data-test="away-laps"]').text()).toBe('312')
      expect(wrapper.get('[data-test="away-cash"]').text()).toBe('+$4,860.00')
      expect(wrapper.get('[data-test="away-xp"]').text()).toBe('7,800 XP')
      expect(wrapper.get('[data-test="away-reason"]').text()).toBe('The tank ran dry.')
    })

    it('stays until the driver has read it, and then counts everything as seen', async () => {
      storeSeen({ runId: 7, version: 1 })
      const { mounted } = open({ state_version: 4 }, away)
      const { wrapper } = await mounted
      await flushPromises()
      expect(readSeen()).toEqual({ runId: 7, version: 1 })

      await wrapper.get('[data-test="away-dismiss"]').trigger('click')

      expect(wrapper.find('[data-test="away"]').exists()).toBe(false)
      expect(readSeen()).toEqual({ runId: 7, version: 4 })
    })

    it('is not shown when the driver was only gone for a moment', async () => {
      storeSeen({ runId: 7, version: 3 })
      const { mounted } = open({ state_version: 4 }, aSummary({ laps: 1, cash_cents: 1_550, xp: 25, from_ms: NOW - 30_000, to_ms: NOW }))
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="away"]').exists()).toBe(false)
    })
  })

  describe('keeping up to date', () => {
    function currentCalls(calls: { url: string }[]): string[] {
      return calls.filter((call) => call.url.startsWith('/api/v1/runs/current')).map((call) => call.url)
    }

    it('asks for the run again at an interval', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      const { mounted, calls } = open()
      await mounted
      await flushPromises()
      expect(currentCalls(calls)).toHaveLength(1)

      await vi.advanceTimersByTimeAsync(POLL_MS)
      expect(currentCalls(calls)).toHaveLength(2)

      await vi.advanceTimersByTimeAsync(POLL_MS)
      expect(currentCalls(calls)).toHaveLength(3)
      // A refresh does not ask for the summary of an absence again.
      expect(currentCalls(calls)[2]).toBe('/api/v1/runs/current')
    })

    it('shows the new state', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      const { mounted } = open({ laps_done: 312 })
      const { wrapper } = await mounted
      await flushPromises()

      serve(() => respond(200, runAnswer(aRun({ laps_done: 316, state_version: 5 }))))
      await vi.advanceTimersByTimeAsync(POLL_MS)

      expect(wrapper.get('[data-test="laps"]').text()).toBe('316')
    })

    it('does not ask while the page is hidden, and asks as soon as it is visible again', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      const { mounted, calls } = open()
      await mounted
      await flushPromises()

      Object.defineProperty(document, 'hidden', { configurable: true, get: () => true })
      await vi.advanceTimersByTimeAsync(POLL_MS * 3)
      expect(currentCalls(calls)).toHaveLength(1)

      Object.defineProperty(document, 'hidden', { configurable: true, get: () => false })
      document.dispatchEvent(new Event('visibilitychange'))
      await flushPromises()
      expect(currentCalls(calls)).toHaveLength(2)
    })

    it('stops asking when the screen is closed', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      const { mounted, calls } = open()
      const { wrapper } = await mounted
      await flushPromises()

      wrapper.unmount()
      await vi.advanceTimersByTimeAsync(POLL_MS * 3)

      expect(currentCalls(calls)).toHaveLength(1)
    })

    it('does not ask again while the last question is still waiting', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      const { mounted } = open()
      await mounted
      await flushPromises()

      let answer: (response: Response) => void = () => undefined
      const waiting = serve(() => new Promise<Response>((resolve) => (answer = resolve)))
      await vi.advanceTimersByTimeAsync(POLL_MS * 3)
      expect(currentCalls(waiting)).toHaveLength(1) // three intervals passed, but only one question was asked

      answer(respond(200, runAnswer(aRun())))
      await flushPromises()
    })
  })

  it('sends a driver without a run to the choice of a wreck', async () => {
    serve(() => respond(404, { message: 'You have no car on the track yet.', code: 'no_run' }))

    const { router } = await show()
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('choose'))
  })

  it('returns to the landing page when the server no longer knows the token', async () => {
    serve(() => respond(401, { message: 'Unauthenticated.' }), () => respond(401, { message: 'Unauthenticated.' }))

    const { router } = await show()
    await flushPromises()

    expect(readToken()).toBeNull()
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('landing'))
  })

  it('says so when the run cannot be loaded, and loads it again on request', async () => {
    serve(() => respond(500, { message: 'Server error' }))
    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The state of your car could not be loaded.')

    serve(() => respond(200, runAnswer(aRun())))
    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="car"]').text()).toBe('Rdzak GT-4')
  })

  it('offers a guest to save the garage', async () => {
    const { mounted } = open()
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.text()).toContain('Save your garage')
  })

  it('signs out and returns to the landing page', async () => {
    const { mounted } = open()
    const { wrapper, router } = await mounted
    await flushPromises()

    await wrapper.get('[data-test="sign-out"]').trigger('click')
    await flushPromises()

    expect(readToken()).toBeNull()
    expect(readSeen()).toBeNull()
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('landing'))
  })
})
