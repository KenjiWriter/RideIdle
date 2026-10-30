import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import { i18n, setLocale } from '@/i18n'
import { useMissionsStore } from '@/stores/missions'
import { useTutorialStore } from '@/stores/tutorial'
import { aChain, aMainMission, missionsView } from '@/test/missionsFixtures'
import { aStep, aTutorial } from '@/test/tutorialFixtures'

import MissionGoal from './MissionGoal.vue'

async function show(prepare: (missions: ReturnType<typeof useMissionsStore>, tutorial: ReturnType<typeof useTutorialStore>) => void, at = '/garage') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/garage', name: 'garage', component: { template: '<div />' } }, { path: '/missions', name: 'missions', component: { template: '<div />' } }] })
  await router.push(at)
  await router.isReady()

  prepare(useMissionsStore(), useTutorialStore())

  return mount(MissionGoal, { global: { plugins: [pinia, i18n, router] } })
}

/** The tutorial is over: it was asked for and has no goal. */
const over = (tutorial: ReturnType<typeof useTutorialStore>) => {
  tutorial.asked = true
  tutorial.tutorial = aTutorial(null, { status: 'finished', goal: null }).data
}

describe('MissionGoal', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('shows the main mission the driver is nearest to, with how far they are and what it pays, once the tutorial is over', async () => {
    const wrapper = await show((missions, tutorial) => {
      over(tutorial)
      missions.apply(missionsView())
    })

    expect(wrapper.get('[data-test="mission-goal-title"]').text()).toContain('The first 50 laps')
    expect(wrapper.get('[data-test="mission-goal-title"]').text()).toContain('Next goal')
    expect(wrapper.get('[data-test="mission-goal-progress"]').text()).toBe('20 / 50')
    expect(wrapper.get('[data-test="mission-goal-chain"]').text()).toBe('Driver')
    expect(wrapper.get('[data-test="mission-goal-reward"]').text()).toContain('$6')
    expect(wrapper.get('[data-test="mission-goal-show"]').text()).toBe('Show')
  })

  it('says that a mission is done and waits to be taken, and leads to the missions', async () => {
    const wrapper = await show((missions, tutorial) => {
      over(tutorial)
      missions.apply(missionsView({ main: [aChain([aMainMission({ status: 'completed', progress: 50 })])] }))
    })

    expect(wrapper.get('[data-test="mission-goal-title"]').text()).toContain('Ready to take')
    expect(wrapper.find('[data-test="mission-goal-progress"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="mission-goal-show"]').text()).toBe('Take')
  })

  it('is not there while the tutorial has a goal or a phone to show, nor before the tutorial was asked for', async () => {
    const goal = await show((missions, tutorial) => {
      tutorial.asked = true
      tutorial.tutorial = aTutorial(null).data
      missions.apply(missionsView())
    })
    const phone = await show((missions, tutorial) => {
      tutorial.asked = true
      tutorial.tutorial = aTutorial(aStep(), { goal: null }).data
      missions.apply(missionsView())
    })
    const unknown = await show((missions) => missions.apply(missionsView()))

    expect(goal.find('[data-test="mission-goal"]').exists()).toBe(false)
    expect(phone.find('[data-test="mission-goal"]').exists()).toBe(false)
    expect(unknown.find('[data-test="mission-goal"]').exists()).toBe(false)
  })

  it('is not there when there is no main mission, and has no link on the missions screen itself', async () => {
    const none = await show((missions, tutorial) => {
      over(tutorial)
      missions.apply(missionsView({ main: [] }))
    })
    const here = await show((missions, tutorial) => {
      over(tutorial)
      missions.apply(missionsView())
    }, '/missions')

    expect(none.find('[data-test="mission-goal"]').exists()).toBe(false)
    expect(here.find('[data-test="mission-goal"]').exists()).toBe(true)
    expect(here.find('[data-test="mission-goal-show"]').exists()).toBe(false)
  })
})
