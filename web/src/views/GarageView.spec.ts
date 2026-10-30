import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Run, RunSummary } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { setGame } from '@/game'
import { POLL_MS } from '@/features/run/useRunPolling'
import { readSeen, storeSeen } from '@/features/run/seen'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { aCar, aGarage, aMountedPart, aPart, aService } from '@/test/garageFixtures'
import { setModel } from '@/model'
import { mountView } from '@/test/mountView'
import { aStep, aTutorial } from '@/test/tutorialFixtures'
import { aShipsGame } from '@/test/unitsFixtures'

import RecoveryPanel from '@/components/RecoveryPanel.vue'
import { liveFields } from '@/test/runFixtures'

import GarageView from './GarageView.vue'

const NOW = 1_792_692_000_000

const profile = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: false, identities: [] }

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
    prices: { fuel_cents_per_liter: 200, repair_cents: 3_000 },
    lap: { ms: { dry: 75_000, rain: 84_391 }, cash_cents: { dry: 1_550, rain: 1_442 }, xp: { dry: 25, rain: 23 }, fuel_ml: 1_500, car_xp: 10, night_ppm: 1_000_000 },
    server_time_ms: NOW,
    ...liveFields(NOW),
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
function serve(
  run: () => Response | Promise<Response>,
  me: () => Response = () => respond(200, { data: profile }),
  extra: Record<string, () => Response | Promise<Response>> = {},
) {
  return serveApi(fetchMock, {
    'GET /api/v1/me': me,
    'GET /api/v1/runs/current': run,
    'GET /api/v1/auth/providers': () => respond(200, { data: ['google', 'apple'] }),
    ...extra,
  })
}

function open(overrides: Partial<Run> = {}, summary?: RunSummary) {
  const calls = serve(() => respond(200, runAnswer(aRun(overrides), summary)))

  return { calls, mounted: show() }
}

describe('GarageView', () => {
  beforeEach(async () => {
    localStorage.clear()
    // These tests are about the run: the window "Save your garage" has its own, so it starts out dismissed here.
    localStorage.setItem('ride.linkPrompt', '1')
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

    expect(wrapper.get('[data-test="cash-chip"]').text()).toBe('$3,124.50')
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
    expect(wrapper.get('[data-test="fuel-bar"]').attributes('aria-valuenow')).toBe('27')
    // Three of the ten cells of the tank are full.
    expect(wrapper.findAll('[data-test="fuel-bar"] i.on')).toHaveLength(3)
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

  it('tells that the car waits in the garage, and leads to the Start of the tournament', async () => {
    const { mounted } = open({ status: 'stopped', stop_reason: 'parked', laps_done: 0 })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="status"]').text()).toBe('Waiting to start')
    expect(wrapper.get('[data-test="banner"]').text()).toContain('The car is waiting in the garage. It earns nothing until you send it out with Start.')
    expect(wrapper.get('[data-test="to-race"]').text()).toBe('Go to the tournament')
    expect(wrapper.get('[data-test="to-race"]').attributes('href')).toBe('/race')
    expect(wrapper.find('[data-test="repair-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tow-button"]').exists()).toBe(false)
  })

  it('tells that the car stands in the workshop, and offers neither the repair nor the tow', async () => {
    const { mounted } = open({ status: 'stopped', stop_reason: 'workshop' })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="status"]').text()).toBe('In the workshop')
    expect(wrapper.get('[data-test="banner"]').text()).toBe('The car stands in the workshop while the mechanic works. It drives again when the last job is done.')
    expect(wrapper.find('[data-test="repair-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tow-button"]').exists()).toBe(false)
  })

  it('has the navigation of the game, with the garage marked', async () => {
    const { mounted } = open()
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.get('[data-test="nav-garage"]').classes()).toContain('on')
    expect(wrapper.get('[data-test="nav-workshop"]').text()).toBe('Workshop')
    expect(wrapper.get('[data-test="nav-shop"]').text()).toBe('City')
  })

  it('offers the repair only to a car that broke down', async () => {
    const racing = open()
    const { wrapper } = await racing.mounted
    await flushPromises()
    expect(wrapper.find('[data-test="repair-button"]').exists()).toBe(false)

    const empty = open({ fuel_ml: 0, status: 'stopped', stop_reason: 'fuel_empty' })
    const { wrapper: emptyTank } = await empty.mounted
    await flushPromises()
    expect(emptyTank.find('[data-test="repair-button"]').exists()).toBe(false)
  })

  it('repairs a car that broke down and sends it on its way', async () => {
    const calls = serveApi(fetchMock, {
      'GET /api/v1/me': () => respond(200, { data: profile }),
      'GET /api/v1/runs/current': () => respond(200, runAnswer(aRun({ status: 'stopped', stop_reason: 'breakdown' }))),
      'POST /api/v1/runs/7/repair': () =>
        respond(200, {
          ...runAnswer(aRun({ status: 'running', stop_reason: null, state_version: 5 })),
          repair: { repaired: true, cost_cents: 3_000, repeated: false },
        }),
    })
    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.get('[data-test="status"]').text()).toBe('Broken down')
    expect(wrapper.get('[data-test="banner"]').text()).toBe('The car broke down. It stands until it is repaired.')
    expect(wrapper.get('[data-test="repair-button"]').text()).toBe('Repair · $30.00')

    await wrapper.get('[data-test="repair-button"]').trigger('click')
    await flushPromises()

    expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/runs/7/repair')).toBe(true)
    expect(wrapper.get('[data-test="status"]').text()).toBe('Racing')
    expect(wrapper.find('[data-test="banner"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="repair-button"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="repair-done"]').text()).toBe('Repaired for $30.00. The car is back on the track.')
  })

  it('offers the tow to a driver who cannot pay, and shows the car on its way after it', async () => {
    const stuck = aRun({ status: 'stopped', stop_reason: 'breakdown', fuel_ml: 30_000 })
    const calls = serveApi(fetchMock, {
      'GET /api/v1/me': () => respond(200, { data: profile }),
      'GET /api/v1/runs/current': () => respond(200, { ...runAnswer(stuck), tow: { available: true, delay_ms: 300_000 } }),
      'POST /api/v1/runs/7/tow': () =>
        respond(200, {
          ...runAnswer(aRun({ status: 'running', stop_reason: null, cursor_ms: NOW + 300_000, state_version: 5 })),
          tow: { available: false, delay_ms: 300_000 },
          towed: true,
        }),
    })
    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.get('[data-test="status"]').text()).toBe('Broken down')
    expect(wrapper.get('[data-test="tow-offer"]').text()).toContain('The tow is free')

    await wrapper.get('[data-test="tow-button"]').trigger('click')
    await flushPromises()

    expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/runs/7/tow')).toBe(true)
    expect(wrapper.find('[data-test="tow-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="banner"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="status"]').text()).toBe('Towed')
    expect(wrapper.get('[data-test="towing"]').text()).toBe('The car is being towed. It drives again in about 5 min 00 s.')
  })

  it('does not call a car towed when its start is not in the future', async () => {
    const { mounted } = open({ cursor_ms: NOW, server_time_ms: NOW })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.find('[data-test="towing"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="status"]').text()).toBe('Racing')
  })

  it('does not offer the tow to a driver who can pay', async () => {
    const { mounted } = open({ status: 'stopped', stop_reason: 'breakdown' })
    const { wrapper } = await mounted
    await flushPromises()

    expect(wrapper.find('[data-test="tow-button"]').exists()).toBe(false)
  })

  describe('the summary of the rest of the account', () => {
    const rest = {
      pending: true,
      from_ms: NOW - 5 * 3_600_000,
      to_ms: NOW - 60_000,
      away_minutes: 299,
      earned_cents: 4_500,
      cars: [{ id: 2, name: 'Bizon M-2', played: false, earned_cents: 4_500, laps: 12, waiting: null }],
      jobs: [{ kind: 'upgrade', part: 'Street tires', finished_ms: NOW - 3_600_000 }],
      journeys: [],
    }

    it('shows what the other cars earned and the jobs that were done, until the driver has seen it', async () => {
      const calls = serve(() => respond(200, runAnswer(aRun())), undefined, {
        'GET /api/v1/away': () => respond(200, { data: rest, now_ms: NOW }),
        'POST /api/v1/away/seen': () => respond(200, { data: { pending: false }, now_ms: NOW }),
      })
      const { wrapper } = await show()
      await flushPromises()

      expect(wrapper.get('[data-test="away-account"]').text()).toContain('Other cars: +$45.00')
      expect(wrapper.get('[data-test="away-jobs"]').text()).toContain('Upgraded: Street tires')

      await wrapper.get('[data-test="away-account-dismiss"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="away-account"]').exists()).toBe(false)
      expect(calls.some((call) => call.method === 'POST' && call.url === '/api/v1/away/seen')).toBe(true)
    })

    it('shows nothing when the server has no break to tell of', async () => {
      serve(() => respond(200, runAnswer(aRun())), undefined, { 'GET /api/v1/away': () => respond(200, { data: { pending: false }, now_ms: NOW }) })
      const { wrapper } = await show()
      await flushPromises()

      expect(wrapper.find('[data-test="away-account"]').exists()).toBe(false)
    })
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

    it('learns that a guest account was secured elsewhere, and stops asking to save the garage', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      localStorage.removeItem('ride.linkPrompt')
      const { mounted } = open()
      const { wrapper } = await mounted
      await flushPromises()
      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(true)

      // The driver opened the link of the e-mail in another tab.
      serve(
        () => respond(200, runAnswer(aRun())),
        () => respond(200, { data: { ...profile, is_guest: false, identities: [{ provider: 'email', email: 't***@example.com' }] } }),
      )
      await vi.advanceTimersByTimeAsync(POLL_MS)

      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="link-banner"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="identity-email"]').exists()).toBe(true)
    })

    it('does not ask for the profile again once the driver has an account', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      const calls = serve(
        () => respond(200, runAnswer(aRun())),
        () => respond(200, { data: { ...profile, is_guest: false } }),
      )
      await show()
      await flushPromises()

      await vi.advanceTimersByTimeAsync(POLL_MS * 3)

      expect(calls.filter((call) => call.url === '/api/v1/me')).toHaveLength(1)
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

  describe('your data', () => {
    it('is in the garage, for a guest as well', async () => {
      const { mounted } = open()
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.get('[data-test="data"] h2').text()).toBe('Your data')
    })

    it('lets the driver delete the account from the garage, which takes them to the start and says it was deleted', async () => {
      serve(() => respond(200, runAnswer(aRun())), () => respond(200, { data: profile }), { 'DELETE /api/v1/account': () => respond(200, { deleted: true }) })
      const { wrapper, router } = await show()
      await flushPromises()

      await wrapper.get('[data-test="delete"]').trigger('click')
      await wrapper.get('[data-test="confirm-name"]').setValue('Maciek_Turbo')
      await wrapper.get('[data-test="delete-form"]').trigger('submit')
      await flushPromises()

      expect(readToken()).toBeNull()
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('landing'))
    })
  })

  describe('saving the garage', () => {
    const away = aSummary({ laps: 312, cash_cents: 486_000, xp: 7_800, from_ms: NOW - (8 * 3_600_000 + 12 * 60_000), to_ms: NOW - 60_000, stop_reason: 'fuel_empty' })

    beforeEach(() => {
      // The window has not been told to wait yet.
      localStorage.removeItem('ride.linkPrompt')
    })

    it('opens the window for a guest who has earned something', async () => {
      const { mounted } = open({ laps_done: 1 })
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.get('[data-test="dialog"]').text()).toContain('Save your garage')
      expect(wrapper.find('[data-test="link-banner"]').exists()).toBe(false)
    })

    it('does not bother a guest who has earned nothing yet', async () => {
      const { mounted } = open({ laps_done: 0 })
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="link-banner"]').exists()).toBe(false)
    })

    it('opens when the first lap is earned while the driver watches', async () => {
      vi.useFakeTimers()
      const { mounted } = open({ laps_done: 0 })
      const { wrapper } = await mounted
      await flushPromises()
      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)

      serve(() => respond(200, runAnswer(aRun({ laps_done: 1, state_version: 5 }))))
      await vi.advanceTimersByTimeAsync(POLL_MS)
      await flushPromises()

      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(true)
    })

    it('leaves a quiet banner when the driver says later, and remembers it', async () => {
      const { mounted } = open()
      const { wrapper } = await mounted
      await flushPromises()

      await wrapper.get('[data-test="later"]').trigger('click')

      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="link-banner"]').exists()).toBe(true)
      expect(localStorage.getItem('ride.linkPrompt')).not.toBeNull()
    })

    it('opens the window again from the banner, and the banner steps aside while it is open', async () => {
      const { mounted } = open()
      const { wrapper } = await mounted
      await flushPromises()
      await wrapper.get('[data-test="later"]').trigger('click')

      await wrapper.get('[data-test="link-banner-open"]').trigger('click')

      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="link-banner"]').exists()).toBe(false)
    })

    it('shows only the banner to a driver who has already said later', async () => {
      localStorage.setItem('ride.linkPrompt', '1')
      const { mounted } = open()
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="link-banner"]').exists()).toBe(true)
    })

    it('waits until the summary of an absence has been read', async () => {
      storeSeen({ runId: 7, version: 1 })
      const { mounted } = open({ state_version: 4 }, away)
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="away"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)

      await wrapper.get('[data-test="away-dismiss"]').trigger('click')

      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(true)
    })

    it('closes the window and shows the recovery code panel when the driver prefers the code', async () => {
      const scrollIntoView = vi.fn()
      Element.prototype.scrollIntoView = scrollIntoView
      const { mounted } = open()
      const { wrapper } = await mounted
      await flushPromises()

      await wrapper.get('[data-test="recovery"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)
      expect(wrapper.findComponent(RecoveryPanel).exists()).toBe(true)
      expect(scrollIntoView).toHaveBeenCalledTimes(1)
      expect(scrollIntoView.mock.contexts[0]).toBe(wrapper.findComponent(RecoveryPanel).element)
      expect(wrapper.find('[data-test="link-banner"]').exists()).toBe(true)

      Reflect.deleteProperty(Element.prototype, 'scrollIntoView')
    })

    it('has nothing to ask of a driver who has an account, and lists how they sign in', async () => {
      serve(() => respond(200, runAnswer(aRun())), () =>
        respond(200, {
          data: {
            ...profile,
            is_guest: false,
            identities: [
              { provider: 'email', email: 't***@example.com' },
              { provider: 'google', email: 'm***@gmail.com' },
            ],
          },
        }),
      )
      const { wrapper } = await show()
      await flushPromises()

      expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="link-banner"]').exists()).toBe(false)
      expect(wrapper.findComponent(RecoveryPanel).exists()).toBe(false)
      expect(wrapper.findAll('[data-test="identity-email"] span').map((part) => part.text())).toEqual(['E-mail', 't***@example.com'])
      expect(wrapper.findAll('[data-test="identity-google"] span').map((part) => part.text())).toEqual(['Google', 'm***@gmail.com'])
      // The server has Google and Apple on and the driver has Google: Apple is left to add.
      expect(wrapper.get('[data-test="add-toggle"]').text()).toBe('Add another sign-in')
    })

    it('does not list sign-ins for a guest, who has none', async () => {
      const { mounted } = open()
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="linked"]').exists()).toBe(false)
    })
  })

  describe('the tutorial', () => {
    function openWithTutorial(tutorial: () => Response, overrides: Partial<Run> = {}) {
      const calls = serve(() => respond(200, runAnswer(aRun(overrides))), undefined, { 'GET /api/v1/tutorial': tutorial })

      return { calls, mounted: show() }
    }

    it('takes the phone of the mentor out over the garage when a step waits, and points at what it names', async () => {
      const { mounted } = openWithTutorial(() => respond(200, aTutorial(aStep({ key: 'first-lap', number: 2, target: 'laps' }))))
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(true)
      expect(wrapper.get('[data-test="tutorial-step"]').text()).toBe('Step 2/5')
      // The laps are what the step points at: the card of them carries the mark the ring is found by.
      expect(wrapper.find('[data-tutorial="laps"] [data-test="laps"]').exists()).toBe(true)
      expect(wrapper.find('[data-tutorial="cash"]').exists()).toBe(true)
    })

    it('shows no phone once the tutorial is over', async () => {
      const { mounted } = openWithTutorial(() => respond(200, aTutorial(null, { status: 'finished', done: 5 })))
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="tutorial"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="run"]').exists()).toBe(true)
    })

    it('is no reason for an error: a garage whose tutorial cannot be loaded is still the garage', async () => {
      const { mounted } = openWithTutorial(() => respond(500, { message: 'Server error' }))
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="tutorial"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="car"]').text()).toBe('Rdzak GT-4')
    })

    it('asks the tutorial again when the run has moved on, so that a step is done as soon as it is', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      let answer = aTutorial(aStep({ key: 'first-lap', number: 2, target: 'laps', acknowledged: true }))
      const { mounted, calls } = openWithTutorial(() => respond(200, answer))
      const { wrapper } = await mounted
      await flushPromises()
      expect(calls.filter((call) => call.url === '/api/v1/tutorial')).toHaveLength(1)

      // The first lap is done: the run has a new version, and the step after the one that was done is waiting.
      answer = aTutorial(aStep({ key: 'rewards', number: 3, target: 'cash' }), { done: 2 })
      serve(() => respond(200, runAnswer(aRun({ laps_done: 313, state_version: 5 }))), undefined, { 'GET /api/v1/tutorial': () => respond(200, answer) })
      await vi.advanceTimersByTimeAsync(POLL_MS)

      expect(wrapper.get('[data-test="tutorial-step"]').text()).toBe('Step 3/5')
    })

    it('says what the mentor paid, for a while', async () => {
      const paid = aTutorial(aStep({ key: 'rewards', number: 3, target: 'cash' }), { rewarded: [{ key: 'first-lap', cash_cents: 20_000, car_xp: 0, xp: 0, part: null, garage: null }] })
      const { mounted } = openWithTutorial(() => respond(200, paid))
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.get('[data-test="tutorial-reward"]').text()).toBe('K. sent you $200.00.')
    })

    describe('and the window "Save your garage"', () => {
      beforeEach(() => {
        localStorage.removeItem('ride.linkPrompt')
      })

      it('keeps the window under the phone, and opens it when the driver has answered the mentor', async () => {
        const { mounted } = openWithTutorial(() => respond(200, aTutorial(aStep({ key: 'rewards', number: 3, target: 'cash' }))), { laps_done: 1 })
        const { wrapper } = await mounted
        await flushPromises()

        expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)

        serve(() => respond(200, runAnswer(aRun({ laps_done: 1 }))), undefined, {
          'POST /api/v1/tutorial/reply': () => respond(200, aTutorial(null, { done: 3 })),
        })
        await wrapper.get('[data-test="tutorial-reply"]').trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(false)
        expect(wrapper.get('[data-test="dialog"]').text()).toContain('Save your garage')
      })

      it('keeps waiting while a step waits for the driver, though the phone is put away', async () => {
        const { mounted } = openWithTutorial(() => respond(200, aTutorial(aStep({ key: 'first-lap', number: 2, target: 'laps', acknowledged: true }))), { laps_done: 1 })
        const { wrapper } = await mounted
        await flushPromises()

        expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(false)
        expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)
      })
    })
  })

  describe('the car', () => {
    function openWithCar(car = aCar(), overrides: Partial<Run> = {}) {
      const calls = serve(() => respond(200, runAnswer(aRun(overrides))), undefined, { 'GET /api/v1/garage': () => respond(200, aGarage({ car })) })

      return { calls, mounted: show() }
    }

    it('shows its level and its experience as the board of the garage does: how far into the level, and how much the level takes', async () => {
      const { mounted } = openWithCar()
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.get('[data-test="car-level"]').text()).toBe('Car LV 3')
      expect(wrapper.get('[data-test="car-xp-value"]').text()).toBe('410 / 600')
      expect(wrapper.get('[data-test="car-xp"] [role="progressbar"]').attributes('aria-valuenow')).toBe('68')
    })

    it('has the language in the account section, where the gear of the bar at the top leads, as the screens of the game have no header for it', async () => {
      const { mounted } = openWithCar()
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="account"] [data-test="language"] select').exists()).toBe(true)
      expect(wrapper.find('[data-test="account"]').attributes('id')).toBe('account')
      expect(wrapper.find('[data-test="fuel"]').attributes('id')).toBe('fuel')
    })

    it('stands on a stage: the name in outline, the level and the state of the run on the floor', async () => {
      const { mounted } = openWithCar()
      const { wrapper } = await mounted
      await flushPromises()

      const stage = wrapper.get('[data-test="stage"]')
      expect(stage.get('.stage-name').text()).toBe('Rdzak GT-4')
      expect(stage.get('[data-test="car-level"]').text()).toBe('Car LV 3')
      expect(stage.get('[data-test="status"]').text()).toBe('Racing')
      // The car of the app has a picture of its own, which is the one on the stage.
      expect(stage.get('[data-test="picture"]').attributes('alt')).toBe('Rdzak GT-4')
    })

    it('shows the picture the pack gives the car in place of the one the app brings, and the outline of a body for a car with none', async () => {
      const { mounted } = openWithCar(aCar(), { car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4', image_url: '/api/v1/media/aaaaaaaa.png' } })
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.get('[data-test="stage"] [data-test="picture"]').attributes('src')).toBe('/api/v1/media/aaaaaaaa.png')

      const none = openWithCar(aCar({ vehicle: { ...aCar().vehicle, slug: 'zubr-v8', name: 'Zubr V8' } }), { car: { slug: 'zubr-v8', name: 'Zubr V8', image_url: null } })
      const bare = await none.mounted
      await flushPromises()

      expect(bare.wrapper.find('[data-test="stage"] [data-test="picture"]').exists()).toBe(false)
      expect(bare.wrapper.get('[data-test="stage"] [data-test="picture-empty"]').attributes('aria-label')).toBe('Zubr V8')
    })

    it('has the card of the specification: the kind of the body, the tier, and the overall score of the bars', async () => {
      const { mounted } = openWithCar()
      const { wrapper } = await mounted
      await flushPromises()

      const card = wrapper.get('[data-test="spec"]')
      expect(card.get('[data-test="car-kind"]').text()).toBe('Hatchback')
      expect(card.get('h1').text()).toBe('Rdzak GT-4')
      expect(card.get('[data-test="car-tier"]').text()).toBe('T2')
      // The bars of the fixture are 24%, 39%, 36%, 35%, 36% and 10% full: 30% of a thousand.
      expect(card.get('[data-test="score"] b').text()).toBe('301')
      expect(card.get('[data-test="score"] small').text()).toBe('Overall score')
      // The bars are in the card, and so are the experience and the way to the technical state.
      expect(card.findAll('.st').length).toBe(6)
      expect(card.find('[data-test="car-xp"]').exists()).toBe(true)
      expect(card.find('[data-test="condition-link"]').exists()).toBe(true)
    })

    it('has no plate and no line for a body that the server does not give a tier and a kind for (an older server)', async () => {
      const { mounted } = openWithCar(aCar({ vehicle: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4', engine: 'R4 1.8', power_kw: 97, drivetrain: 'fwd' } }))
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.find('[data-test="car-tier"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="car-kind"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="score"] b').text()).toBe('301')
    })

    it('says what the next level that opens parts opens', async () => {
      const { mounted } = openWithCar()
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.get('[data-test="tier-unlock"]').text()).toBe('From level 6: T3 parts')
    })

    it('has a full bar and no next tier at the highest level', async () => {
      const { mounted } = openWithCar(aCar({ level: 30, xp: { total: 87_000, level_start: 87_000, next_level_at: null }, tier_unlock: null }))
      const { wrapper } = await mounted
      await flushPromises()

      expect(wrapper.get('[data-test="car-xp-value"]').text()).toBe('Highest level')
      expect(wrapper.get('[data-test="car-xp"] [role="progressbar"]').attributes('aria-valuenow')).toBe('100')
      expect(wrapper.find('[data-test="tier-unlock"]').exists()).toBe(false)
    })

    it('says that the car rose, with the tier of parts that opened, when the garage is asked again and the car is higher', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      const { mounted } = openWithCar(aCar({ level: 5, tier_unlock: { tier: 3, level: 6 } }))
      const { wrapper } = await mounted
      await flushPromises()
      expect(wrapper.find('[data-test="level-up"]').exists()).toBe(false)

      // The run moves on (a lap is settled), and with it the car, which is level 6 now.
      serve(() => respond(200, runAnswer(aRun({ state_version: 5 }))), undefined, {
        'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ level: 6, tier_unlock: { tier: 4, level: 9 } }) })),
      })
      await vi.advanceTimersByTimeAsync(POLL_MS)

      expect(wrapper.get('[data-test="level-up"]').text()).toBe('The car reached level 6. You can mount T3 parts now.')
      expect(wrapper.get('[data-test="car-level"]').text()).toBe('Car LV 6')
    })

    it('says only the level when a rise opens no tier', async () => {
      vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
      const { mounted } = openWithCar()
      const { wrapper } = await mounted
      await flushPromises()

      serve(() => respond(200, runAnswer(aRun({ state_version: 5 }))), undefined, {
        'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ level: 4 }) })),
      })
      await vi.advanceTimersByTimeAsync(POLL_MS)

      expect(wrapper.get('[data-test="level-up"]').text()).toBe('The car reached level 4.')
    })

    it('shows the experience the mentor gave the car in the bar, which is asked for again because the run has not moved', async () => {
      const paid = aTutorial(null, { rewarded: [{ key: 'rewards', cash_cents: 0, car_xp: 100, xp: 0, part: null, garage: null }] })
      let total = 1_010
      const calls = serve(() => respond(200, runAnswer(aRun())), undefined, {
        'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ xp: { total, level_start: 600, next_level_at: 1_200 } }) })),
        'GET /api/v1/tutorial': () => {
          total = 1_110

          return respond(200, paid)
        },
      })
      const { wrapper } = await show()
      await flushPromises()

      expect(calls.filter((call) => call.url === '/api/v1/garage').length).toBeGreaterThanOrEqual(2)
      expect(wrapper.get('[data-test="car-xp-value"]').text()).toBe('510 / 600')
    })

    it('says what the mentor gave the car, with the cash when there is some', async () => {
      const paid = aTutorial(null, { rewarded: [{ key: 'rewards', cash_cents: 0, car_xp: 100, xp: 0, part: null, garage: null }] })
      serve(() => respond(200, runAnswer(aRun())), undefined, { 'GET /api/v1/tutorial': () => respond(200, paid) })
      const { wrapper } = await show()
      await flushPromises()

      expect(wrapper.get('[data-test="tutorial-reward"]').text()).toBe('K. added 100 XP to your car.')
    })
  })

  it('marks the spot of an upgraded part with its level, and says it on the card of the part; a part that is not upgraded has neither', async () => {
    const upgraded = aMountedPart({ id: 31, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2, upgrade: 3 })
    const plain = aMountedPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes' })
    serve(() => respond(200, runAnswer(aRun())), undefined, { 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires: upgraded, brakes: plain }) })) })

    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.get('[data-test="spot-tires"] .spot-up').text()).toBe('+3')
    expect(wrapper.find('[data-test="spot-brakes"] .spot-up').exists()).toBe(false)

    await wrapper.get('[data-test="spot-tires"]').trigger('click')
    expect(wrapper.get('[data-test="slot-detail"] [data-test="upgrade-mark"]').text()).toBe('+3')

    await wrapper.get('[data-test="spot-brakes"]').trigger('click')
    expect(wrapper.find('[data-test="slot-detail"] [data-test="upgrade-mark"]').exists()).toBe(false)
  })

  it('rings the spot of a part that is worn out in red, and says how much of its life a part has left on its card, leaving a part as good as new as it is', async () => {
    const worn = aMountedPart({ id: 31, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2, condition_percent: 63 })
    const dead = aMountedPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', condition_percent: 9 })
    const fresh = aMountedPart({ id: 11 })
    serve(() => respond(200, runAnswer(aRun())), undefined, { 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires: worn, brakes: dead, exhaust: fresh }) })) })

    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.get('[data-test="spot-tires"]').classes()).toContain('spot-ok')
    expect(wrapper.get('[data-test="spot-brakes"]').classes()).toContain('spot-worn')
    expect(wrapper.get('[data-test="spot-exhaust"]').classes()).toContain('spot-ok')

    await wrapper.get('[data-test="spot-tires"]').trigger('click')
    expect(wrapper.get('[data-test="slot-detail"] [data-test="part-condition-value"]').text()).toBe('Condition: 63%')
    expect(wrapper.find('[data-test="slot-detail"] [data-test="part-unserviceable"]').exists()).toBe(false)

    await wrapper.get('[data-test="spot-brakes"]').trigger('click')
    expect(wrapper.get('[data-test="slot-detail"] [data-test="part-condition-value"]').text()).toBe('Condition: 9%')
    expect(wrapper.get('[data-test="slot-detail"] [data-test="part-unserviceable"]').text()).toBe('Unserviceable')

    await wrapper.get('[data-test="spot-exhaust"]').trigger('click')
    expect(wrapper.find('[data-test="slot-detail"] [data-test="part-condition"]').exists()).toBe(false)
  })

  it('shows the odometer of the car and how far its service is, and how late it is when it is overdue', async () => {
    serve(() => respond(200, runAnswer(aRun())), undefined, { 'GET /api/v1/garage': () => respond(200, aGarage()) })

    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.get('[data-test="upkeep"]').text()).toBe('Mileage: 260,123 km · Service in 3,420 km')
    expect(wrapper.get('[data-test="upkeep-service"]').classes()).not.toContain('text-danger')

    serve(() => respond(200, runAnswer(aRun())), undefined, {
      'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ mileage_km: 265_800, service: aService({ since_km: 5_800, due_in_km: -800, overdue_km: 800, penalty_percent: 4 }) }) })),
    })
    const late = await show()
    await flushPromises()

    expect(late.wrapper.get('[data-test="upkeep"]').text()).toBe('Mileage: 265,800 km · Service overdue by 800 km: −4% performance')
    expect(late.wrapper.get('[data-test="upkeep-service"]').classes()).toContain('text-danger')
  })

  it('links to the technical state of the car with its condition, and says the limit of the gearbox when it has one', async () => {
    serve(() => respond(200, runAnswer(aRun())), undefined, { 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ parts_condition_percent: 82 }) })) })

    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.get('[data-test="condition-link"]').text()).toBe('Technical condition: 82%')
    expect(wrapper.get('[data-test="condition-link"]').attributes('href')).toBe('/garage/condition')
    expect(wrapper.find('[data-test="gearbox-limit"]').exists()).toBe(false)

    const gearbox = aMountedPart({ id: 31, slug: 'manual-5-gearbox-t1', name: 'Manual 5', slot: 'gearbox', stats: { gears: 5, top_speed_cap_kmh: 190, launch: 0 } })
    serve(() => respond(200, runAnswer(aRun())), undefined, { 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ parts_condition_percent: 82 }, { gearbox }) })) })
    const limited = await show()
    await flushPromises()

    expect(limited.wrapper.get('[data-test="gearbox-limit"]').text()).toBe('Gearbox limit: 190 km/h')
  })

  it('says which part broke down when the server told it, and says nothing more when it did not', async () => {
    const broke = { ...runAnswer(aRun({ status: 'stopped', stop_reason: 'breakdown' })), settlements: [{ version: 5, from_ms: NOW - 60_000, to_ms: NOW, at_ms: NOW, laps: 3, cash_cents: 4_650, xp: 75, stop_reason: 'breakdown' as const, events: [{ type: 'breakdown' as const, at_ms: NOW, payload: { part: 'tires' } }] }] }
    serve(() => respond(200, broke))

    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.get('[data-test="banner"]').text()).toBe('The car broke down. It stands until it is repaired. It was the Tires.')
    expect(wrapper.get('[data-test="broken-part"]').text()).toBe('It was the Tires.')

    // An event of a kind that the screen does not know is no reason for anything.
    const odd = { ...runAnswer(aRun({ status: 'stopped', stop_reason: 'breakdown' })), settlements: [{ ...broke.settlements[0], events: [{ type: 'service_due' as const, at_ms: NOW, payload: { mileage_km: 265_000 } }] }] }
    serve(() => respond(200, odd))
    const plain = await show()
    await flushPromises()

    expect(plain.wrapper.get('[data-test="banner"]').text()).toBe('The car broke down. It stands until it is repaired.')
    expect(plain.wrapper.find('[data-test="broken-part"]').exists()).toBe(false)
  })

  it('marks the spot of a rarer part with the color of its rarity, and says its name on the card; a common part has neither', async () => {
    const epic = aPart({ id: 31, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2, mounted: true, car_id: 5, rarity: 'epic', rarity_rank: 4 })
    const common = aPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', mounted: true, car_id: 5 })
    serve(() => respond(200, runAnswer(aRun())), undefined, { 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires: epic, brakes: common }) })) })

    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.get('[data-test="spot-tires"]').classes()).toContain('r4')
    expect(wrapper.get('[data-test="spot-brakes"]').classes().filter((name) => /^r\d$/.test(name))).toEqual([])

    await wrapper.get('[data-test="spot-tires"]').trigger('click')
    expect(wrapper.get('[data-test="slot-detail"] [data-test="rarity"]').text()).toBe('Epic')
    expect(wrapper.get('[data-test="slot-detail"]').classes()).toContain('card-r4')

    await wrapper.get('[data-test="spot-brakes"]').trigger('click')
    expect(wrapper.find('[data-test="slot-detail"] [data-test="rarity"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="slot-detail"]').classes().filter((name) => /^card-r\d$/.test(name))).toEqual([])
  })

  describe('the parts on the car', () => {
    const SLOTS = ['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank']

    function openWithParts(mounted: Parameters<typeof aCar>[1] = {}, car = aCar({}, mounted), run: Partial<Run> = {}) {
      serve(() => respond(200, runAnswer(aRun(run))), undefined, { 'GET /api/v1/garage': () => respond(200, aGarage({ car })) })

      return show()
    }

    it('puts a spot on the car for every slot, each saying for a reader what the slot is and what is on it', async () => {
      const tires = aMountedPart({ id: 31, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2 })
      const { wrapper } = await openWithParts({ tires })
      await flushPromises()

      expect(wrapper.findAll('[data-test="spots"] .spot').map((spot) => spot.attributes('data-test'))).toEqual(SLOTS.map((slot) => `spot-${slot}`))
      expect(wrapper.get('[data-test="spot-tires"]').attributes('aria-label')).toBe('Tires: Street tires T2')
      // The engine slot is the engine of the body until a part is on it, the others are the factory ones.
      expect(wrapper.get('[data-test="spot-engine"]').attributes('aria-label')).toBe('Engine: R4 1.8')
      expect(wrapper.get('[data-test="spot-gearbox"]').attributes('aria-label')).toBe('Gearbox: Factory')
      expect(wrapper.get('[data-test="spot-tires"]').attributes('style')).toContain('left: 57%')
      // A slot with nothing on it is dashed, and the rest of the slots are no row under the stage.
      expect(wrapper.get('[data-test="spot-gearbox"]').classes()).toContain('spot-empty')
      expect(wrapper.find('[data-test="slots"]').exists()).toBe(false)
    })

    it('says how many parts are fitted until a part is tapped, and what is on the slot that was tapped, which is put away when it is tapped again or closed', async () => {
      const tires = aMountedPart({ id: 31, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2 })
      const { wrapper } = await openWithParts({ tires })
      await flushPromises()

      expect(wrapper.get('[data-test="slot-hint"]').text()).toContain('Tap a part on the car to see what is fitted.')
      expect(wrapper.get('[data-test="slot-summary"]').text()).toBe('Parts fitted: 1 of 9')
      expect(wrapper.find('[data-test="slot-detail"]').exists()).toBe(false)

      await wrapper.get('[data-test="spot-tires"]').trigger('click')

      expect(wrapper.find('[data-test="slot-hint"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="slot-name"]').text()).toBe('Tires')
      expect(wrapper.get('[data-test="slot-part"]').text()).toBe('Street tires T2')
      expect(wrapper.get('[data-test="slot-tier"]').text()).toBe('T2')
      expect(wrapper.get('[data-test="spot-tires"]').classes()).toContain('spot-on')
      expect(wrapper.get('[data-test="spot-tires"]').attributes('aria-pressed')).toBe('true')
      expect(wrapper.get('[data-test="spot-tires"] .spot-tag').text()).toBe('Tires')

      await wrapper.get('[data-test="spot-tires"]').trigger('click')
      expect(wrapper.find('[data-test="slot-detail"]').exists()).toBe(false)

      await wrapper.get('[data-test="spot-tires"]').trigger('click')
      await wrapper.get('[data-test="slot-close"]').trigger('click')
      expect(wrapper.find('[data-test="slot-detail"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="spot-tires"]').attributes('aria-pressed')).toBe('false')
    })

    it('shows another slot in place of the one that was looked at, and leads from its card to the workshop', async () => {
      const { wrapper } = await openWithParts({ tires: aMountedPart({ id: 31, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2 }) })
      await flushPromises()

      await wrapper.get('[data-test="spot-tires"]').trigger('click')
      await wrapper.get('[data-test="spot-brakes"]').trigger('click')

      expect(wrapper.findAll('[data-test="slot-detail"]')).toHaveLength(1)
      expect(wrapper.get('[data-test="slot-detail"]').attributes('data-slot')).toBe('brakes')
      expect(wrapper.get('[data-test="spot-tires"]').classes()).not.toContain('spot-on')
      expect(wrapper.get('[data-test="slot-workshop"]').attributes('href')).toBe('/workshop')
      expect(wrapper.get('[data-test="slot-workshop"]').text()).toBe('Fit one in the workshop')

      await wrapper.get('[data-test="spot-tires"]').trigger('click')
      expect(wrapper.get('[data-test="slot-workshop"]').text()).toBe('Change in the workshop')
    })

    it('says that the engine slot holds the engine of the body, and that any other empty slot holds the factory part', async () => {
      const { wrapper } = await openWithParts()
      await flushPromises()

      await wrapper.get('[data-test="spot-engine"]').trigger('click')
      expect(wrapper.get('[data-test="slot-part"]').text()).toBe('R4 1.8')
      expect(wrapper.get('[data-test="slot-factory"]').text()).toBe('This is the part of the body the car left the factory with. You can fit a better one in the workshop.')

      await wrapper.get('[data-test="spot-gearbox"]').trigger('click')
      expect(wrapper.get('[data-test="slot-part"]').text()).toBe('Factory')
      expect(wrapper.get('[data-test="slot-factory"]').text()).toBe('The factory part of the car. You can fit a better one in the workshop.')
    })

    it('shows the statistics and the affixes of a part on its card', async () => {
      const grippy = aMountedPart({ id: 31, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2, stats: { grip: 120 } })
      const { wrapper } = await openWithParts({ tires: grippy })
      await flushPromises()

      await wrapper.get('[data-test="spot-tires"]').trigger('click')

      expect(wrapper.get('[data-test="slot-detail"]').text()).toContain('Grip 120')
    })

    it('has no spots, and offers every slot as a row under the stage, for a car that has no picture to put them on', async () => {
      const car = aCar({ vehicle: { ...aCar().vehicle, slug: 'zubr-v8', name: 'Zubr V8' } })
      const { wrapper } = await openWithParts({}, car, { car: { slug: 'zubr-v8', name: 'Zubr V8', image_url: null } })
      await flushPromises()

      expect(wrapper.find('[data-test="spots"]').exists()).toBe(false)
      expect(wrapper.findAll('[data-test="slots"] button')).toHaveLength(9)

      await wrapper.get('[data-test="chip-boost"]').trigger('click')

      expect(wrapper.get('[data-test="chip-boost"]').attributes('aria-pressed')).toBe('true')
      expect(wrapper.get('[data-test="slot-detail"]').attributes('data-slot')).toBe('boost')
      expect(wrapper.get('[data-test="slot-name"]').text()).toBe('Forced induction')
    })

    it('puts a car of a pack, with a picture the app does not have, on the layout of the pictures of the game', async () => {
      const car = aCar({ vehicle: { ...aCar().vehicle, slug: 'zubr-v8', name: 'Zubr V8' } })
      const { wrapper } = await openWithParts({}, car, { car: { slug: 'zubr-v8', name: 'Zubr V8', image_url: '/api/v1/media/aaaaaaaa.png' } })
      await flushPromises()

      expect(wrapper.findAll('[data-test="spots"] .spot')).toHaveLength(9)
      expect(wrapper.find('[data-test="slots"]').exists()).toBe(false)
    })
  })

  describe('in a game that counts in nautical miles and knots', () => {
    afterEach(() => {
      setGame()
    })

    it('says the mileage, the service and the limit of the gearbox in the units of the game', async () => {
      setGame(aShipsGame())
      const gearbox = aMountedPart({ id: 31, slug: 'manual-5-gearbox-t1', name: 'Manual 5', slot: 'gearbox', stats: { gears: 5, top_speed_cap_kmh: 190, launch: 0 } })
      serve(() => respond(200, runAnswer(aRun())), undefined, { 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { gearbox }) })) })

      const { wrapper } = await show()
      await flushPromises()

      // 260,123 km are 140,455.2 nm and 3,420 km are 1,846.7 nm; 190 km/h are 103 knots.
      expect(wrapper.get('[data-test="upkeep"]').text()).toBe('Mileage: 140,455.2 nm · Service in 1,846.7 nm')
      expect(wrapper.get('[data-test="gearbox-limit"]').text()).toBe('Gearbox limit: 103 kn')
    })
  })
})

describe('GarageView in a game with another model', () => {
  beforeEach(async () => {
    localStorage.clear()
    localStorage.setItem('ride.linkPrompt', '1')
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
    setModel({ slots: [{ key: 'drive', role: 'gearbox', hidden: false }], stats: ['grip', 'accel'] })
  })

  afterEach(() => {
    setModel()
    mountedScreens.splice(0).forEach((screen) => screen.unmount())
    vi.unstubAllGlobals()
  })

  it('shows only the statistics that the model lists, in its order, and finds the limit of the gearbox in the slot that has the role', async () => {
    const drive = aMountedPart({ id: 31, slug: 'drive-1', name: 'Drive', slot: 'drive', stats: { top_speed_cap_kmh: 190 } })
    serve(() => respond(200, runAnswer(aRun())), undefined, {
      'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ slots: [{ slot: 'drive', role: 'gearbox', part: drive, built_in: false }] }) })),
    })

    const { wrapper } = await show()
    await flushPromises()

    expect(wrapper.findAll('[data-test^="stat-"]').map((row) => row.attributes('data-test'))).toEqual(['stat-grip', 'stat-accel'])
    expect(wrapper.get('[data-test="gearbox-limit"]').text()).toBe('Gearbox limit: 190 km/h')
  })
})
