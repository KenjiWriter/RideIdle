import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import type { Run, StopReason, TowState } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { FLASH_MS } from '@/features/run/useFlash'
import { i18n, setLocale } from '@/i18n'
import { useRunStore } from '@/stores/run'
import { respond } from '@/test/fetchServer'
import { liveFields } from '@/test/runFixtures'

import TowControl from './TowControl.vue'

const NOW = 1_792_692_000_000

function aRun(overrides: Partial<Run> = {}): Run {
  return {
    id: 7,
    car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
    track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
    status: 'stopped',
    stop_reason: 'breakdown',
    laps_done: 1,
    fuel_ml: 30_000,
    tank_ml: 45_000,
    cursor_ms: NOW,
    state_version: 2,
    prices: { fuel_cents_per_liter: 200, repair_cents: 3_000 },
    lap: { ms: { dry: 75_000 }, cash_cents: { dry: 1_550 }, xp: { dry: 25 }, fuel_ml: 1_500, car_xp: 10, night_ppm: 1_000_000 },
    server_time_ms: NOW,
    ...liveFields(NOW),
    ...overrides,
  }
}

const fetchMock = vi.fn()
const offer: TowState = { available: true, delay_ms: 300_000 }

/** The control under a parent that gives it the live run, as the garage does, so it reacts to the tow. */
function show(run: Run, tow: TowState | null = offer) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const store = useRunStore()
  store.run = run
  store.wallet = { cash_cents: 1_550, xp: 0 }
  store.tow = tow

  const parent = defineComponent({
    setup() {
      const live = useRunStore()

      return () => (live.run ? h(TowControl, { run: live.run }) : null)
    },
  })

  return { wrapper: mount(parent, { global: { plugins: [pinia, i18n] } }), store }
}

function towedAnswer(towed: boolean) {
  return respond(200, {
    data: aRun({ status: 'running', stop_reason: null as StopReason | null, cursor_ms: NOW + 300_000, state_version: 3 }),
    wallet: { cash_cents: 1_550, xp: 0 },
    tow: { available: false, delay_ms: 300_000 },
    towed,
  })
}

describe('TowControl', () => {
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

  it('offers nothing when the server does not offer the tow', () => {
    const { wrapper } = show(aRun(), { available: false, delay_ms: 300_000 })

    expect(wrapper.find('[data-test="tow-button"]').exists()).toBe(false)
  })

  it('offers nothing before the server has said anything', () => {
    const { wrapper } = show(aRun(), null)

    expect(wrapper.find('[data-test="tow-button"]').exists()).toBe(false)
  })

  it('tells a driver whose car broke down that the tow is free but takes time', () => {
    const { wrapper } = show(aRun())

    expect(wrapper.get('[data-test="tow-offer"]').text()).toBe(
      'You cannot afford the repair. The tow is free, but the car waits 5 min 00 s before it drives again.',
    )
    expect(wrapper.get('[data-test="tow-button"]').text()).toBe('Tow the car · free')
  })

  it('tells a driver whose car ran dry that it leaves with a little fuel', () => {
    const { wrapper } = show(aRun({ stop_reason: 'fuel_empty', fuel_ml: 0 }))

    expect(wrapper.get('[data-test="tow-offer"]').text()).toBe(
      'You cannot afford fuel for a lap. The tow is free: the car waits 5 min 00 s and leaves with a little fuel.',
    )
  })

  it('tows the car, and the offer goes away with the stop', async () => {
    const { wrapper, store } = show(aRun())
    fetchMock.mockResolvedValue(towedAnswer(true))

    await wrapper.get('[data-test="tow-button"]').trigger('click')
    await flushPromises()

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/v1/runs/7/tow')
    expect(init.method).toBe('POST')
    expect(store.run?.status).toBe('running')
    expect(wrapper.find('[data-test="tow-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tow-notice"]').exists()).toBe(false)
  })

  it('says so when the tow was not needed after all', async () => {
    const { wrapper } = show(aRun())
    fetchMock.mockResolvedValue(towedAnswer(false))

    await wrapper.get('[data-test="tow-button"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="tow-notice"]').text()).toBe('The tow was not needed.')
  })

  it('lets that notice go after a while', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

    try {
      const { wrapper } = show(aRun())
      fetchMock.mockResolvedValue(towedAnswer(false))

      await wrapper.get('[data-test="tow-button"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-test="tow-notice"]').exists()).toBe(true)

      await vi.advanceTimersByTimeAsync(FLASH_MS)

      expect(wrapper.find('[data-test="tow-notice"]').exists()).toBe(false)
    } finally {
      vi.useRealTimers()
    }
  })

  it('shows a general error when the server fails, and can try again', async () => {
    const { wrapper } = show(aRun())
    fetchMock.mockResolvedValue(respond(500, { message: 'Server error' }))

    await wrapper.get('[data-test="tow-button"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="tow-error"]').text()).toBe('Something went wrong. Try again.')
    expect(wrapper.get('[data-test="tow-button"]').attributes('disabled')).toBeUndefined()
  })

  it('waits for the answer and does not send a second request meanwhile', async () => {
    const { wrapper } = show(aRun())
    let finish: (response: Response) => void = () => undefined
    fetchMock.mockImplementation(() => new Promise<Response>((resolve) => (finish = resolve)))

    await wrapper.get('[data-test="tow-button"]').trigger('click')
    await wrapper.get('[data-test="tow-button"]').trigger('click')

    expect(wrapper.get('[data-test="tow-button"]').text()).toBe('Towing…')
    expect(fetchMock).toHaveBeenCalledTimes(1)

    finish(towedAnswer(true))
    await flushPromises()
  })

  it('ends the session when the server does not know the token', async () => {
    const { wrapper } = show(aRun())
    fetchMock.mockResolvedValue(respond(401, { message: 'Unauthenticated.' }))

    await wrapper.get('[data-test="tow-button"]').trigger('click')
    await flushPromises()

    expect(readToken()).toBeNull()
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const { wrapper } = show(aRun())

    expect(wrapper.get('[data-test="tow-button"]').text()).toBe('Holuj auto · za darmo')
    expect(wrapper.get('[data-test="tow-offer"]').text()).toContain('Nie stać Cię na naprawę.')
  })
})
