import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import { i18n, setLocale } from '@/i18n'
import { useMissionsStore } from '@/stores/missions'
import { aMission, aSet, missionsView } from '@/test/missionsFixtures'

import MissionToast from './MissionToast.vue'

async function show(at = '/garage') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/garage', name: 'garage', component: { template: '<div />' } }, { path: '/missions', name: 'missions', component: { template: '<div />' } }] })
  await router.push(at)
  await router.isReady()

  return { wrapper: mount(MissionToast, { global: { plugins: [pinia, i18n, router] } }), missions: useMissionsStore() }
}

const viewWith = (...missions: ReturnType<typeof aMission>[]) => missionsView({ periods: { ...missionsView().periods!, daily: aSet(missions) } })

describe('MissionToast', () => {
  beforeEach(async () => {
    vi.useFakeTimers()
    await setLocale('en')
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('says on any screen that a mission turned done, for a few seconds, and leads to the missions', async () => {
    const { wrapper, missions } = await show()

    missions.apply(viewWith(aMission({ id: 1 })))
    missions.apply(viewWith(aMission({ id: 1, status: 'completed', progress: 12 })))
    await flushPromises()

    expect(wrapper.get('[data-test="mission-toast"]').text()).toBe('Mission done: Drive 12 laps')
    expect(missions.justDone).toEqual([])

    await vi.advanceTimersByTimeAsync(8_500)

    expect(wrapper.find('[data-test="mission-toast"]').exists()).toBe(false)
  })

  it('counts them when there are more', async () => {
    const { wrapper, missions } = await show()

    missions.apply(viewWith(aMission({ id: 1 }), aMission({ id: 2, slot: 2 })))
    missions.apply(viewWith(aMission({ id: 1, status: 'completed' }), aMission({ id: 2, slot: 2, status: 'completed' })))
    await flushPromises()

    expect(wrapper.get('[data-test="mission-toast"]').text()).toBe('Missions done: 2')
  })

  it('does not speak on the screen of the missions, where the driver sees it', async () => {
    const { wrapper, missions } = await show('/missions')

    missions.apply(viewWith(aMission({ id: 1 })))
    missions.apply(viewWith(aMission({ id: 1, status: 'completed' })))
    await flushPromises()

    expect(wrapper.find('[data-test="mission-toast"]').exists()).toBe(false)
  })
})
