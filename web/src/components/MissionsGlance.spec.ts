import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import { i18n, setLocale } from '@/i18n'
import { useMissionsStore } from '@/stores/missions'
import { aMission, aSet, missionsView } from '@/test/missionsFixtures'

import MissionsGlance from './MissionsGlance.vue'

function show(view = missionsView()) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/missions', name: 'missions', component: { template: '<div />' } }] })
  useMissionsStore().apply(view)

  return mount(MissionsGlance, { global: { plugins: [pinia, i18n, router] } })
}

describe('MissionsGlance', () => {
  beforeEach(async () => {
    // The time that is left is worked out from the clock of this device, so the test stops it.
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    await setLocale('en')
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows the missions of the day in a line: how many are done and what waits to be taken', () => {
    const wrapper = show()

    expect(wrapper.get('[data-test="missions-glance-count"]').text()).toBe('1 / 3')
    expect(wrapper.get('[data-test="missions-glance-take"]').text()).toBe('1 to take')
    expect(wrapper.get('[data-test="missions-glance"]').attributes('href')).toBe('/missions')
  })

  it('tells when the day resets when nothing waits to be taken', () => {
    const wrapper = show(missionsView({ claimable: 0, periods: { ...missionsView().periods!, daily: aSet([aMission({ id: 1 })]) } }))

    expect(wrapper.find('[data-test="missions-glance-take"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="missions-glance-reset"]').text()).toBe('Resets in 3 h 12 min')
  })

  it('is not there when the game has no missions or the set was not drawn yet', () => {
    expect(show(missionsView({ available: false })).find('[data-test="missions-glance"]').exists()).toBe(false)
    expect(show(missionsView({ periods: { ...missionsView().periods!, daily: aSet([], { drawn: false }) } })).find('[data-test="missions-glance"]').exists()).toBe(false)
  })
})
