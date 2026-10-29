import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { Run } from '@/api/types'
import { storeToken } from '@/auth/token'
import { i18n, setLocale } from '@/i18n'
import { useGarageStore } from '@/stores/garage'
import { useMapStore } from '@/stores/map'
import { usePremiumStore } from '@/stores/premium'
import { useRunStore } from '@/stores/run'
import { respond, serveApi } from '@/test/fetchServer'
import { liveFields } from '@/test/runFixtures'

import GameHud from './GameHud.vue'

/** The clock of the server at the midnight of a day of the game, and a minute of it is 7.5 s of it. */
const MIDNIGHT = 1_792_692_000_000
const GAME_MINUTE_MS = 7_500

const at = (hour: number, minute: number): number => MIDNIGHT + (hour * 60 + minute) * GAME_MINUTE_MS

const fetchMock = vi.fn()

/** A run of the car that is played, with what the bar at the top says of it. */
function aRun(weather = 'dry', overrides: Partial<Run> = {}): Run {
  const fields = liveFields(Date.now())

  return {
    ...fields,
    weather: { ...fields.weather, now: weather },
    car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4', class: 'hatchback', tier: 2 },
    fuel_ml: 31_000,
    tank_ml: 45_000,
    ...overrides,
  } as unknown as Run
}

const aRouter = () => createRouter({ history: createMemoryHistory(), routes: [{ path: '/garage', name: 'garage', component: { template: '<div />' } }] })

/** The bar at the top, with a run in the store when it is given one. */
function show(run: Run | null = null) {
  const pinia = createPinia()
  setActivePinia(pinia)

  if (run) {
    useRunStore().run = run
  }

  return mount(GameHud, { global: { plugins: [pinia, i18n, aRouter()] } })
}

describe('GameHud', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    // The balance of the premium coins is asked for once when no answer has said it: here nothing answers.
    serveApi(fetchMock, {})
    storeToken('device-token')
    await setLocale('en')
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] })
    vi.setSystemTime(at(11, 20))
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('shows the time of the game, which is three real hours for a day, even before there is a car', () => {
    const wrapper = show()

    expect(wrapper.get('[data-test="clock"]').text()).toBe('11:20')
    expect(wrapper.get('[data-test="clock"]').attributes('title')).toBe('Game time: 11:20, Day')
  })

  it('adds the weather of the region where the car drives', () => {
    expect(show(aRun('rain')).get('[data-test="clock"]').text()).toBe('11:20 · rain')
  })

  it('moves on with the time of the game: a minute of it is seven and a half seconds', async () => {
    const wrapper = show(aRun('dry'))

    await vi.advanceTimersByTimeAsync(16_000)

    expect(wrapper.get('[data-test="clock"]').text()).toBe('11:22 · dry')
  })

  it('names the phase of the day in the language of the driver', async () => {
    await setLocale('pl')
    vi.setSystemTime(at(23, 10))
    const wrapper = show(aRun('snow'))

    expect(wrapper.get('[data-test="clock"]').text()).toBe('23:10 · śnieg')
    expect(wrapper.get('[data-test="clock"]').attributes('title')).toBe('Czas w grze: 23:10, Noc')
  })

  describe('the car that is played', () => {
    it('is in the strip under the bars, with its tier and its kind of body', () => {
      const wrapper = show(aRun())

      expect(wrapper.get('[data-test="hud-car-name"]').text()).toBe('Rdzak GT-4')
      expect(wrapper.get('[data-test="hud-car-tier"]').text()).toBe('T2')
      expect(wrapper.get('[data-test="hud-car-kind"]').text()).toBe('Hatchback')
    })

    it('has no name and no plate before there is a car, and no plate for a car that has no tier (an older server)', () => {
      const bare = show()

      expect(bare.find('[data-test="hud-car-name"]').exists()).toBe(false)
      expect(bare.find('[data-test="hud-car-tier"]').exists()).toBe(false)

      const older = show(aRun('dry', { car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' } }))

      expect(older.get('[data-test="hud-car-name"]').text()).toBe('Rdzak GT-4')
      expect(older.find('[data-test="hud-car-tier"]').exists()).toBe(false)
      expect(older.find('[data-test="hud-car-kind"]').exists()).toBe(false)
    })
  })

  describe('the fuel', () => {
    it('is a bar of ten cells beside the plus that leads to the tank, and says how full it is', () => {
      // 31 000 of 45 000 ml is 69 %: seven cells.
      const wrapper = show(aRun())
      const bar = wrapper.get('[data-test="hud-fuel-bar"]')

      expect(bar.attributes('aria-valuenow')).toBe('69')
      expect(bar.attributes('aria-label')).toBe('Fuel: 69%')
      expect(bar.findAll('i')).toHaveLength(10)
      expect(bar.findAll('i.on')).toHaveLength(7)
      expect(wrapper.get('[data-test="hud-fuel-plus"]').attributes('href')).toBe('/garage#fuel')
      expect(wrapper.get('[data-test="hud-fuel-plus"]').attributes('aria-label')).toBe('Refuel')
    })

    it('goes red when there is little left', () => {
      const bar = show(aRun('dry', { fuel_ml: 4_000 })).get('[data-test="hud-fuel-bar"]')

      expect(bar.findAll('i.lo')).toHaveLength(1)
      expect(bar.findAll('i.on')).toHaveLength(0)
    })

    it('is not there before there is a car, or when the tank is not known', () => {
      expect(show().find('[data-test="hud-fuel"]').exists()).toBe(false)
      expect(show(aRun('dry', { tank_ml: null })).find('[data-test="hud-fuel"]').exists()).toBe(false)
    })
  })

  it('has the gear that leads to the account, which is at the bottom of the garage', () => {
    const gear = show().get('[data-test="hud-gear"]')

    expect(gear.attributes('href')).toBe('/garage#account')
    expect(gear.attributes('aria-label')).toBe('Account and settings')
  })

  describe('the premium coins', () => {
    it('are not shown until an answer has said how many there are', async () => {
      const wrapper = show()
      await flushPromises()

      expect(wrapper.find('[data-test="coins-chip"]').exists()).toBe(false)
    })

    it('are a pill beside the cash, with the balance that the last answer of the garage gave', () => {
      const pinia = createPinia()
      setActivePinia(pinia)
      const garage = useGarageStore()
      garage.premiumCoins = 25
      garage.premiumSeenAt = 10

      const wrapper = mount(GameHud, { global: { plugins: [pinia, i18n, aRouter()] } })

      expect(wrapper.get('[data-test="coins-chip"]').text()).toBe('25')
      expect(wrapper.get('[data-test="coins-chip"]').attributes('aria-label')).toBe('RideCoin: 25')
    })

    it('are the ones of the newest answer, whichever screen it came from, and nought is a balance too', async () => {
      const pinia = createPinia()
      setActivePinia(pinia)
      const garage = useGarageStore()
      const map = useMapStore()
      garage.premiumCoins = 25
      garage.premiumSeenAt = 10
      map.premiumCoins = 0
      map.premiumSeenAt = 20

      const wrapper = mount(GameHud, { global: { plugins: [pinia, i18n, aRouter()] } })

      expect(wrapper.get('[data-test="coins-chip"]').text()).toBe('0')

      garage.premiumCoins = 7
      garage.premiumSeenAt = 30
      await wrapper.vm.$nextTick()

      expect(wrapper.get('[data-test="coins-chip"]').text()).toBe('7')
    })

    it('are asked for once when no answer has said how many there are, so the pill is there on a screen that was opened first', async () => {
      const calls = serveApi(fetchMock, { 'GET /api/v1/premium': () => respond(200, { data: { balance: 12 } }) })

      const wrapper = show()
      await flushPromises()

      expect(calls.filter((call) => call.url.endsWith('/v1/premium'))).toHaveLength(1)
      expect(wrapper.get('[data-test="coins-chip"]').text()).toBe('12')
      expect(usePremiumStore().balance).toBe(12)
    })

    it('are asked for once, not on every screen, when the server cannot say them', async () => {
      const calls = serveApi(fetchMock, {})
      const pinia = createPinia()
      setActivePinia(pinia)

      for (let screen = 0; screen < 3; screen++) {
        mount(GameHud, { global: { plugins: [pinia, i18n, aRouter()] } })
        await flushPromises()
      }

      expect(calls.filter((call) => call.url.endsWith('/v1/premium'))).toHaveLength(1)
    })

    it('are not asked for when an answer has said it', async () => {
      const calls = serveApi(fetchMock, {})
      const pinia = createPinia()
      setActivePinia(pinia)
      useMapStore().premiumSeenAt = 5

      mount(GameHud, { global: { plugins: [pinia, i18n, aRouter()] } })
      await flushPromises()

      expect(calls).toHaveLength(0)
    })
  })
})
