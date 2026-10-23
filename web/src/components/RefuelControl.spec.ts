import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Run } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { i18n, setLocale } from '@/i18n'
import { FLASH_MS } from '@/features/run/useFlash'
import { useRunStore } from '@/stores/run'
import { respond } from '@/test/fetchServer'
import { liveFields } from '@/test/runFixtures'

import RefuelControl from './RefuelControl.vue'

function aRun(overrides: Partial<Run> = {}): Run {
  return {
    id: 7,
    car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
    track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
    status: 'running',
    stop_reason: null,
    laps_done: 10,
    fuel_ml: 15_000,
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

/** Mounts the control with a run and the cash of the driver, the way the garage does. */
function show(run: Run, cashCents: number) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const store = useRunStore()
  store.run = run
  store.wallet = { cash_cents: cashCents, xp: 0 }

  const wrapper = mount(RefuelControl, { props: { run }, global: { plugins: [pinia, i18n] } })

  return { wrapper, store }
}

function answer(run: Run, cashCents: number, refuel: { added_ml: number; cost_cents: number; repeated: boolean }) {
  return respond(200, { data: run, wallet: { cash_cents: cashCents, xp: 0 }, refuel })
}

describe('RefuelControl', () => {
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

  it('says what filling up costs and what a liter costs', () => {
    const { wrapper } = show(aRun(), 100_000)

    expect(wrapper.get('[data-test="refuel-full"]').text()).toBe('Fill up · $60.00')
    expect(wrapper.get('[data-test="price"]').text()).toBe('Fuel costs $2.00 per liter.')
    expect(wrapper.find('[data-test="refuel-partial"]').exists()).toBe(false)
  })

  it('rounds the price of a fill-up up to the cent, like the server', () => {
    // 946 ml at $2.00 per liter is 189.2 cents, which the server charges as 190, not as 189.
    const { wrapper } = show(aRun({ fuel_ml: 44_054 }), 100_000)

    expect(wrapper.get('[data-test="refuel-full"]').text()).toBe('Fill up · $1.90')
  })

  it('fills the tank and tells what was added and paid', async () => {
    const { wrapper, store } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(answer(aRun({ fuel_ml: 45_000, state_version: 3 }), 94_000, { added_ml: 30_000, cost_cents: 6_000, repeated: false }))

    await wrapper.get('[data-test="refuel-full"]').trigger('click')
    await flushPromises()

    const body = JSON.parse((fetchMock.mock.calls[0] as [string, RequestInit])[1].body as string)
    expect(Object.keys(body)).toEqual(['request_id'])
    expect(wrapper.get('[data-test="refuel-done"]').text()).toBe('Added 30 l for $60.00.')
    expect(store.run?.fuel_ml).toBe(45_000)
    expect(store.wallet?.cash_cents).toBe(94_000)
    expect(wrapper.find('[data-test="refuel-error"]').exists()).toBe(false)
  })

  it('lets the result go after a while, so it does not mislead later', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

    try {
      const { wrapper } = show(aRun(), 100_000)
      fetchMock.mockResolvedValue(answer(aRun({ fuel_ml: 45_000, state_version: 3 }), 94_000, { added_ml: 30_000, cost_cents: 6_000, repeated: false }))

      await wrapper.get('[data-test="refuel-full"]').trigger('click')
      await flushPromises()
      expect(wrapper.get('[data-test="refuel-done"]').text()).toBe('Added 30 l for $60.00.')

      await vi.advanceTimersByTimeAsync(FLASH_MS)

      expect(wrapper.find('[data-test="refuel-done"]').exists()).toBe(false)
    } finally {
      vi.useRealTimers()
    }
  })

  it('offers what the driver can afford when a full tank is too dear', async () => {
    // $31.00 buys 15 whole liters, a full tank is 30 liters for $60.00.
    const { wrapper } = show(aRun(), 3_100)

    expect(wrapper.get('[data-test="refuel-full"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="refuel-partial"]').text()).toBe('Buy 15 l · $30.00')

    fetchMock.mockResolvedValue(answer(aRun({ fuel_ml: 30_000, state_version: 3 }), 100, { added_ml: 15_000, cost_cents: 3_000, repeated: false }))
    await wrapper.get('[data-test="refuel-partial"]').trigger('click')
    await flushPromises()

    const body = JSON.parse((fetchMock.mock.calls[0] as [string, RequestInit])[1].body as string)
    expect(body.liters).toBe(15)
    expect(wrapper.get('[data-test="refuel-done"]').text()).toBe('Added 15 l for $30.00.')
  })

  it('says so when the driver cannot buy even a liter', () => {
    const { wrapper } = show(aRun(), 150)

    expect(wrapper.get('[data-test="refuel-full"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="refuel-partial"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="no-cash"]').text()).toBe('Not enough cash to buy fuel.')
  })

  it('has nothing to buy when the tank is full', () => {
    const { wrapper } = show(aRun({ fuel_ml: 45_000 }), 100_000)

    expect(wrapper.get('[data-test="tank-full"]').text()).toBe('The tank is full.')
    expect(wrapper.find('[data-test="refuel-full"]').exists()).toBe(false)
  })

  it('shows the message of the server when the cash was not enough after all', async () => {
    // The wallet on screen is out of date: the server knows better.
    const { wrapper } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(respond(422, { message: 'Za mało gotówki.', code: 'insufficient_funds' }))

    await wrapper.get('[data-test="refuel-full"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="refuel-error"]').text()).toBe('Za mało gotówki.')
    expect(wrapper.find('[data-test="refuel-done"]').exists()).toBe(false)
  })

  it('shows a general error when the server fails, and can try again', async () => {
    const { wrapper } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(respond(500, { message: 'Server error' }))

    await wrapper.get('[data-test="refuel-full"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="refuel-error"]').text()).toBe('Something went wrong. Try again.')
    expect(wrapper.get('[data-test="refuel-full"]').attributes('disabled')).toBeUndefined()
  })

  it('tells when the refuel had already been done', async () => {
    const { wrapper } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(answer(aRun({ fuel_ml: 45_000 }), 94_000, { added_ml: 0, cost_cents: 0, repeated: true }))

    await wrapper.get('[data-test="refuel-full"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="refuel-done"]').text()).toBe('That refuel was already done.')
  })

  it('waits for the answer and does not send a second request meanwhile', async () => {
    const { wrapper } = show(aRun(), 100_000)
    let finish: (response: Response) => void = () => undefined
    fetchMock.mockImplementation(() => new Promise<Response>((resolve) => (finish = resolve)))

    await wrapper.get('[data-test="refuel-full"]').trigger('click')
    await wrapper.get('[data-test="refuel-full"]').trigger('click')

    expect(wrapper.get('[data-test="refuel-full"]').text()).toBe('Refuelling…')
    expect(wrapper.get('[data-test="refuel-full"]').attributes('disabled')).toBeDefined()
    expect(fetchMock).toHaveBeenCalledTimes(1)

    finish(answer(aRun({ fuel_ml: 45_000 }), 94_000, { added_ml: 30_000, cost_cents: 6_000, repeated: false }))
    await flushPromises()
  })

  it('ends the session when the server does not know the token', async () => {
    const { wrapper } = show(aRun(), 100_000)
    fetchMock.mockResolvedValue(respond(401, { message: 'Unauthenticated.' }))

    await wrapper.get('[data-test="refuel-full"]').trigger('click')
    await flushPromises()

    expect(readToken()).toBeNull()
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const { wrapper } = show(aRun(), 100_000)

    expect(wrapper.get('[data-test="refuel-full"]').text()).toMatch(/^Zatankuj do pełna · 60,00/)
    expect(wrapper.get('[data-test="price"]').text()).toMatch(/^Paliwo kosztuje 2,00/)
  })
})
