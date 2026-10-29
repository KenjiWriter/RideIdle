import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { PremiumView } from '@/api/types'
import { storeToken } from '@/auth/token'
import { i18n, setLocale } from '@/i18n'
import { usePremiumStore } from '@/stores/premium'
import { respond, serveApi } from '@/test/fetchServer'
import { passOffer, premiumView, refillOffer } from '@/test/premiumFixtures'

import AutoRefillSwitch from './AutoRefillSwitch.vue'

const fetchMock = vi.fn()

function show(view: PremiumView | null) {
  const pinia = createPinia()
  setActivePinia(pinia)

  if (view !== null) {
    usePremiumStore().apply(view)
  }

  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/premium', name: 'premium', component: { template: '<div />' } }] })

  return mount(AutoRefillSwitch, { global: { plugins: [pinia, i18n, router] } })
}

describe('AutoRefillSwitch', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    storeToken('device-token')
    await setLocale('en')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('is not there before the shop has been read, nor in a game that does not sell the auto-refill', () => {
    expect(show(null).find('[data-test="autorefill"]').exists()).toBe(false)
    expect(show(premiumView({ autorefill: null })).find('[data-test="autorefill"]').exists()).toBe(false)
    expect(show(premiumView({ autorefill: refillOffer({ enabled: false, stock: 4 }) })).find('[data-test="autorefill"]').exists()).toBe(false)
  })

  it('says how many refills are in stock, or that there are none left, and leads to the shop for more', () => {
    const stocked = show(premiumView({ autorefill: refillOffer({ stock: 12 }) }))

    expect(stocked.get('[data-test="autorefill-state"]').text()).toBe('12 refills in stock')
    expect(stocked.get('[data-test="autorefill-switch"]').element).toHaveProperty('checked', true)
    expect(stocked.get('[data-test="autorefill-buy"]').attributes('href')).toBe('/premium')

    expect(show(premiumView({ autorefill: refillOffer({ stock: 1 }) })).get('[data-test="autorefill-state"]').text()).toBe('1 refill in stock')
    expect(show(premiumView()).get('[data-test="autorefill-state"]').text()).toBe('No refills in stock: the car stops when the fuel runs out.')
  })

  it('says the refills are free while the pass is on, and for how long', () => {
    const now = Date.now()
    const wrapper = show(premiumView({ now_ms: now, infinity: passOffer({ active: true, until_ms: now + 5 * 3_600_000 + 600_000 }) }))

    expect(wrapper.get('[data-test="autorefill-state"]').text()).toBe('Pass: the refills are free (5 h 09 min left)')
  })

  it('says the car will stop when the driver has switched the wish off, whatever they have', () => {
    const wrapper = show(premiumView({ autorefill: refillOffer({ stock: 9, preference: false }) }))

    expect(wrapper.get('[data-test="autorefill-switch"]').element).toHaveProperty('checked', false)
    expect(wrapper.get('[data-test="autorefill-state"]').text()).toBe('Off: the car stops when the fuel runs out.')
  })

  it('tells the server when the driver flips the switch, and shows what the server says', async () => {
    const calls = serveApi(fetchMock, { 'PATCH /api/v1/premium/autorefill': () => respond(200, { data: premiumView({ autorefill: refillOffer({ stock: 9, preference: false }) }) }) })
    const wrapper = show(premiumView({ autorefill: refillOffer({ stock: 9 }) }))

    await wrapper.get('[data-test="autorefill-switch"]').setValue(false)
    await flushPromises()

    expect(calls[0]).toMatchObject({ method: 'PATCH', body: { enabled: false } })
    expect(wrapper.get('[data-test="autorefill-state"]').text()).toBe('Off: the car stops when the fuel runs out.')
  })

  it('puts the switch back and says why when the server refuses', async () => {
    serveApi(fetchMock, { 'PATCH /api/v1/premium/autorefill': () => respond(422, { message: 'Auto-refill is not for sale.', code: 'autorefill_unavailable' }) })
    const wrapper = show(premiumView({ autorefill: refillOffer({ stock: 9 }) }))

    await wrapper.get('[data-test="autorefill-switch"]').setValue(false)
    await flushPromises()

    expect(wrapper.get('[data-test="autorefill-error"]').text()).toBe('Auto-refill is not for sale.')
    expect(wrapper.get('[data-test="autorefill-switch"]').element).toHaveProperty('checked', true)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')

    expect(show(premiumView({ autorefill: refillOffer({ stock: 3 }) })).get('[data-test="autorefill-state"]').text()).toBe('3 tankowania w zapasie')
    expect(show(premiumView({ autorefill: refillOffer({ stock: 3 }) })).get('label').text()).toBe('Tankuj za mnie')
  })
})
