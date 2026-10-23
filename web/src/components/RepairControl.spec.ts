import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import type { Run } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { i18n, setLocale } from '@/i18n'
import { FLASH_MS } from '@/features/run/useFlash'
import { useRunStore } from '@/stores/run'
import { respond } from '@/test/fetchServer'
import { liveFields } from '@/test/runFixtures'

import RepairControl from './RepairControl.vue'

function aRun(overrides: Partial<Run> = {}): Run {
  return {
    id: 7,
    car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
    track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
    status: 'stopped',
    stop_reason: 'breakdown',
    laps_done: 10,
    fuel_ml: 30_000,
    tank_ml: 45_000,
    cursor_ms: 1_792_692_000_000,
    state_version: 2,
    prices: { fuel_cents_per_liter: 200, repair_cents: 3_000 },
    lap: { ms: { dry: 75_000 }, cash_cents: { dry: 1_550 }, xp: { dry: 25 }, fuel_ml: 1_500, car_xp: 10, night_ppm: 1_000_000 },
    server_time_ms: 1_792_692_000_000,
    ...liveFields(1_792_692_000_000),
    ...overrides,
  }
}

const fetchMock = vi.fn()

/** The control under a parent that gives it the live run, as the garage does, so it reacts to the repair. */
function show(run: Run, cashCents: number) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const store = useRunStore()
  store.run = run
  store.wallet = { cash_cents: cashCents, xp: 0 }

  const parent = defineComponent({
    setup() {
      const live = useRunStore()

      return () => (live.run ? h(RepairControl, { run: live.run }) : null)
    },
  })

  return { wrapper: mount(parent, { global: { plugins: [pinia, i18n] } }), store }
}

function answer(run: Run, cashCents: number, repair: { repaired: boolean; cost_cents: number; repeated: boolean }) {
  return respond(200, { data: run, wallet: { cash_cents: cashCents, xp: 0 }, repair })
}

describe('RepairControl', () => {
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

  it.each([
    ['a car that is racing', { status: 'running' as const, stop_reason: null }],
    ['a car that only ran out of fuel', { status: 'stopped' as const, stop_reason: 'fuel_empty' as const }],
  ])('offers nothing for %s', (_name, overrides) => {
    const { wrapper } = show(aRun(overrides), 100_000)

    expect(wrapper.find('[data-test="repair-button"]').exists()).toBe(false)
  })

  it('offers the repair of a car that broke down, with its price', () => {
    const { wrapper } = show(aRun(), 100_000)

    expect(wrapper.get('[data-test="repair-button"]').text()).toBe('Repair · $30.00')
    expect(wrapper.get('[data-test="repair-button"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="repair-no-cash"]').exists()).toBe(false)
  })

  it('says so when the driver cannot pay for the repair', () => {
    const { wrapper } = show(aRun(), 2_999)

    expect(wrapper.get('[data-test="repair-button"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="repair-no-cash"]').text()).toBe('Not enough cash to repair the car.')
  })

  it('repairs the car, and the result stays after the button is gone', async () => {
    const { wrapper, store } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(answer(aRun({ status: 'running', stop_reason: null, state_version: 3 }), 97_000, { repaired: true, cost_cents: 3_000, repeated: false }))

    await wrapper.get('[data-test="repair-button"]').trigger('click')
    await flushPromises()

    const body = JSON.parse((fetchMock.mock.calls[0] as [string, RequestInit])[1].body as string)
    expect(Object.keys(body)).toEqual(['request_id'])
    expect(store.run?.status).toBe('running')
    expect(store.wallet?.cash_cents).toBe(97_000)
    expect(wrapper.find('[data-test="repair-button"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="repair-done"]').text()).toBe('Repaired for $30.00. The car is back on the track.')
  })

  it('lets the result go after a while, so it does not mislead later', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

    try {
      const { wrapper } = show(aRun(), 100_000)
      fetchMock.mockResolvedValue(answer(aRun({ status: 'running', stop_reason: null, state_version: 3 }), 97_000, { repaired: true, cost_cents: 3_000, repeated: false }))

      await wrapper.get('[data-test="repair-button"]').trigger('click')
      await flushPromises()
      expect(wrapper.find('[data-test="repair-done"]').exists()).toBe(true)

      await vi.advanceTimersByTimeAsync(FLASH_MS)

      expect(wrapper.find('[data-test="repair-done"]').exists()).toBe(false)
    } finally {
      vi.useRealTimers()
    }
  })

  it('does not claim the car is back on the track when the tank is empty', async () => {
    const { wrapper } = show(aRun({ fuel_ml: 0 }), 100_000)
    fetchMock.mockResolvedValue(answer(aRun({ fuel_ml: 0, stop_reason: 'fuel_empty', state_version: 3 }), 97_000, { repaired: true, cost_cents: 3_000, repeated: false }))

    await wrapper.get('[data-test="repair-button"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="repair-done"]').text()).toBe('Repaired for $30.00.')
  })

  it('tells when the car did not need a repair after all', async () => {
    const { wrapper } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(answer(aRun({ status: 'running', stop_reason: null, state_version: 3 }), 100_000, { repaired: false, cost_cents: 0, repeated: false }))

    await wrapper.get('[data-test="repair-button"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="repair-done"]').text()).toBe('The car did not need a repair.')
  })

  it('tells when the repair had already been done', async () => {
    const { wrapper } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(answer(aRun({ status: 'running', stop_reason: null, state_version: 3 }), 97_000, { repaired: false, cost_cents: 0, repeated: true }))

    await wrapper.get('[data-test="repair-button"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="repair-done"]').text()).toBe('That repair was already done.')
  })

  it('shows the message of the server when the cash was not enough after all', async () => {
    const { wrapper } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(respond(422, { message: 'Za mało gotówki.', code: 'insufficient_funds' }))

    await wrapper.get('[data-test="repair-button"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="repair-error"]').text()).toBe('Za mało gotówki.')
    expect(wrapper.get('[data-test="repair-button"]').attributes('disabled')).toBeUndefined()
  })

  it('shows a general error when the server fails', async () => {
    const { wrapper } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(respond(500, { message: 'Server error' }))

    await wrapper.get('[data-test="repair-button"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="repair-error"]').text()).toBe('Something went wrong. Try again.')
  })

  it('waits for the answer and does not send a second request meanwhile', async () => {
    const { wrapper } = show(aRun(), 100_000)
    let finish: (response: Response) => void = () => undefined
    fetchMock.mockImplementation(() => new Promise<Response>((resolve) => (finish = resolve)))

    await wrapper.get('[data-test="repair-button"]').trigger('click')
    await wrapper.get('[data-test="repair-button"]').trigger('click')

    expect(wrapper.get('[data-test="repair-button"]').text()).toBe('Repairing…')
    expect(fetchMock).toHaveBeenCalledTimes(1)

    finish(answer(aRun({ status: 'running', stop_reason: null, state_version: 3 }), 97_000, { repaired: true, cost_cents: 3_000, repeated: false }))
    await flushPromises()
  })

  it('ends the session when the server does not know the token', async () => {
    const { wrapper } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(respond(401, { message: 'Unauthenticated.' }))

    await wrapper.get('[data-test="repair-button"]').trigger('click')
    await flushPromises()

    expect(readToken()).toBeNull()
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const { wrapper } = show(aRun(), 100_000)

    expect(wrapper.get('[data-test="repair-button"]').text()).toMatch(/^Napraw · 30,00/)
  })
})
