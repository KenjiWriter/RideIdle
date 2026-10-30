import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { MissionsView } from '@/api/types'
import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { aChain, aMainMission, aMission, aPart, aSet, missionsView } from '@/test/missionsFixtures'
import { mountView } from '@/test/mountView'

import MissionsView_ from './MissionsView.vue'

const fetchMock = vi.fn()

function serve(view: () => MissionsView, routes: Record<string, () => Response | Promise<Response>> = {}) {
  return serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(200, { data: view() }), 'GET /api/v1/runs/current': () => respond(404, { message: 'No run' }), ...routes })
}

async function open() {
  const mounted = await mountView(MissionsView_, '/missions')
  await flushPromises()

  return mounted
}

describe('MissionsView', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('opens on the tab that has something to take, with the number of them on every tab', async () => {
    serve(() => missionsView())

    const { wrapper } = await open()

    expect(wrapper.get('h1').text()).toBe('Missions')
    expect(wrapper.get('[data-test="tab-daily"]').attributes('aria-selected')).toBe('true')
    expect(wrapper.get('[data-test="tab-daily-count"]').text()).toBe('1')
    expect(wrapper.find('[data-test="tab-weekly-count"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="set-count"]').text()).toBe('Done: 1 of 3')
    expect(wrapper.get('[data-test="set-reset"]').text()).toBe('Resets in 3 h 12 min')
    expect(wrapper.findAll('[data-test^="mission-"][data-status]')).toHaveLength(3)
  })

  it('shows the set of another period when its tab is pressed, and the main missions in chains', async () => {
    serve(() => missionsView())

    const { wrapper } = await open()
    await wrapper.get('[data-test="tab-weekly"]').trigger('click')

    expect(wrapper.get('[data-test="set-weekly"]').text()).toContain('Drive 120 laps')
    expect(wrapper.get('[data-test="bonus-coins"]').text()).toBe('+8')

    await wrapper.get('[data-test="tab-main"]').trigger('click')

    expect(wrapper.get('[data-test="chain-driver"] h2').text()).toBe('Driver')
    expect(wrapper.get('[data-test="chain-count"]').text()).toBe('0 of 8')
    expect(wrapper.get('[data-test="chain-driver"] [data-test="mission-title"]').text()).toBe('The first 50 laps')
  })

  it('takes a mission with its button, shows what it gave, and keeps the bars at the top true', async () => {
    let after = false
    const calls = serve(() => (after ? missionsView({ claimable: 0, periods: { ...missionsView().periods!, daily: aSet([aMission({ id: 1, status: 'claimed', progress: 12 })]) } }) : missionsView()), {
      'POST /api/v1/missions/1/claim': () => {
        after = true

        return respond(200, {
          data: missionsView({ claimable: 0, periods: { ...missionsView().periods!, daily: aSet([aMission({ id: 1, status: 'claimed', progress: 12 })]) } }),
          repeated: false,
          claimed: { missions: [], cash_cents: 600, xp: 4, car_xp: 0, crates: ['slot'], parts: [aPart()] },
        })
      },
      'GET /api/v1/premium': () => respond(200, { data: { balance: 3, currency: null, price_currency: null, now_ms: 1, autorefill: null, vip: null, infinity: null } }),
    })

    const { wrapper } = await open()
    await wrapper.get('[data-test="mission-1"] [data-test="mission-claim"]').trigger('click')
    await flushPromises()

    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(1)
    expect(wrapper.get('[data-test="gave"]').text()).toContain('$6')
    expect(wrapper.get('[data-test="gave-part-name"]').text()).toBe('Street tires')
    expect(wrapper.get('[data-test="mission-1"]').attributes('data-status')).toBe('claimed')
    expect(wrapper.find('[data-test="tab-daily-count"]').exists()).toBe(false)
    // The cash is in the bar at the top, and the coins: both are asked for again.
    expect(calls.some((call) => call.url === '/api/v1/runs/current')).toBe(true)
    expect(calls.some((call) => call.url === '/api/v1/premium')).toBe(true)

    await wrapper.get('[data-test="gave-close"]').trigger('click')
    expect(wrapper.find('[data-test="gave"]').exists()).toBe(false)
  })

  it('takes the bonus of a set that is done whole, and says how many coins', async () => {
    const done = aSet([aMission({ id: 1, status: 'claimed', progress: 12 })], { bonus: { coins: 2, state: 'ready' } })
    const view = missionsView({ claimable: 1, periods: { ...missionsView().periods!, daily: done } })
    serve(() => view, {
      'POST /api/v1/missions/daily/bonus': () => respond(200, { data: { ...view, periods: { ...view.periods!, daily: { ...done, bonus: { coins: 2, state: 'claimed' } } } }, repeated: false, bonus_coins: 2 }),
      'GET /api/v1/premium': () => respond(200, { data: { balance: 5, currency: null, price_currency: null, now_ms: 1, autorefill: null, vip: null, infinity: null } }),
    })

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="bonus-claim"]').text()).toBe('Take 2 RideCoin')

    await wrapper.get('[data-test="bonus-claim"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="told"]').text()).toBe('You took 2 RideCoin.')
    expect(wrapper.get('[data-test="bonus-claimed"]').text()).toBe('Taken')
    expect(wrapper.find('[data-test="bonus-claim"]').exists()).toBe(false)
  })

  it('takes all the missions of a tab with one request when there is more than one to take', async () => {
    const two = aSet([aMission({ id: 1, status: 'completed' }), aMission({ id: 2, slot: 2, status: 'completed' })])
    const calls = serve(() => missionsView({ claimable: 2, periods: { ...missionsView().periods!, daily: two } }), {
      'POST /api/v1/missions/claim': () => respond(200, { data: missionsView({ claimable: 0 }), repeated: false, claimed: { missions: [], cash_cents: 1_200, xp: 8, car_xp: 0, crates: [], parts: [] } }),
    })

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="claim-all"]').text()).toBe('Take all (2)')

    await wrapper.get('[data-test="claim-all"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.url === '/api/v1/missions/claim')?.body).toEqual({ kind: 'daily' })
    expect(wrapper.get('[data-test="gave"]').text()).toContain('$12')
  })

  it('says why a chain waits for a level, and when a chain is finished', async () => {
    serve(() =>
      missionsView({
        claimable: 0,
        periods: { ...missionsView().periods!, daily: aSet([]) },
        main: [aChain([], { key: 'driver', total: 8, done: 3, waiting: { min_player_level: 12 } }), aChain([], { key: 'mechanic', title: 'Mechanic', total: 8, done: 8 })],
      }),
    )

    const { wrapper } = await open()
    await wrapper.get('[data-test="tab-main"]').trigger('click')

    expect(wrapper.get('[data-test="chain-driver"] [data-test="chain-waiting"]').text()).toBe('The next mission opens at level 12.')
    expect(wrapper.get('[data-test="chain-mechanic"] [data-test="chain-finished"]').text()).toBe('This chain is done.')
  })

  it('says that the sets come after the tutorial, and that the main missions come when it is over', async () => {
    serve(() => ({ available: true, now_ms: 1, locked: 'tutorial', main_locked: 'tutorial', claimable: 0, periods: missionsView().periods, main: [] }))

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="locked"]').text()).toContain('after the first steps of the tutorial')

    await wrapper.get('[data-test="tab-main"]').trigger('click')

    expect(wrapper.get('[data-test="locked"]').text()).toContain('when you finish the tutorial')
  })

  it('says what the server took when a period had ended', async () => {
    serve(() =>
      missionsView({
        auto_collected: [
          { type: 'mission', kind: 'daily', period_key: '2026-10-29', mission: aMission({ id: 99, status: 'claimed', reward: { cash_cents: 2_000, xp: 5, car_xp: 0, items: [] } }) },
          { type: 'bonus', kind: 'daily', period_key: '2026-10-29', coins: 2 },
        ],
      }),
    )

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="collected"]').text()).toBe('When the period ended, the server took for you: $20 and 2 RideCoin.')
  })

  it('says that a game has no missions, and offers a retry when they cannot be loaded', async () => {
    serve(() => ({ available: false, now_ms: 1 }))

    const none = await open()
    expect(none.wrapper.get('[data-test="none"]').text()).toBe('This game has no missions.')

    fetchMock.mockReset()
    serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(500, { message: 'x' }) })
    const failed = await open()

    expect(failed.wrapper.get('[data-test="load-error"]').text()).toContain('could not be loaded')
    expect(failed.wrapper.find('[data-test="retry"]').exists()).toBe(true)
  })

  it('shows the main missions with their words and the missions that are done and wait', async () => {
    serve(() => missionsView({ main: [aChain([aMainMission({ id: 40, status: 'completed', progress: 50 }), aMainMission({ id: 41, key: 'driver-laps-250', title: 'Warming up', text: 'Drive 250 laps in all.', target: 250, progress: 50 })])] }))

    const { wrapper } = await open()
    await wrapper.get('[data-test="tab-main"]').trigger('click')

    expect(wrapper.get('[data-test="tab-main-count"]').text()).toBe('1')
    expect(wrapper.get('[data-test="mission-40"]').attributes('data-status')).toBe('completed')
    expect(wrapper.get('[data-test="mission-41"] [data-test="mission-title"]').text()).toBe('Warming up')
  })
})
