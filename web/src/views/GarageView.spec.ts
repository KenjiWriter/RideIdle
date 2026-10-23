import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Run, RunSummary } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { POLL_MS } from '@/features/run/useRunPolling'
import { readSeen, storeSeen } from '@/features/run/seen'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import RecoveryPanel from '@/components/RecoveryPanel.vue'

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
    expect(wrapper.get('[data-test="nav-shop"]').text()).toBe('Shop')
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
})
