import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { useTutorialStore } from '@/stores/tutorial'
import { mountView } from '@/test/mountView'
import { aGoal, aStep, aTutorial } from '@/test/tutorialFixtures'

import TutorialGoal from './TutorialGoal.vue'

const mounted: { unmount: () => void }[] = []

async function show(answer = aTutorial(aStep({ acknowledged: true })), path = '/garage') {
  const view = await mountView(TutorialGoal, path, { prepare: () => (useTutorialStore().tutorial = answer.data) })
  mounted.push(view.wrapper)
  await flushPromises()

  return view
}

const rewardLines = (wrapper: { findAll: (selector: string) => { text: () => string }[] }): string[] => wrapper.findAll('[data-test="goal-reward"] b').map((chip) => chip.text())

describe('TutorialGoal', () => {
  beforeEach(async () => {
    localStorage.clear()
    storeToken('device-token')
    await setLocale('en')
  })

  afterEach(() => {
    mounted.splice(0).forEach((view) => view.unmount())
  })

  it('tells the driver what to do next, which step it is, and what it pays', async () => {
    const goal = aGoal({ number: 11, phase: 3, title: 'Fit the off-road tires', target: 'mount', reward: { cash_cents: 30_000, car_xp: 0, xp: 0, part: 'Street tires', garage: null } })
    const { wrapper } = await show(aTutorial(aStep({ acknowledged: true }), { goal, total: 20 }))

    expect(wrapper.get('[data-test="goal-step"]').text()).toBe('Step 11 of 20')
    expect(wrapper.get('[data-test="goal-title"]').text()).toBe('Goal Fit the off-road tires')
    expect(rewardLines(wrapper)).toEqual(['$300', 'Street tires'])
    expect(wrapper.findAll('.goal-dots i.on')).toHaveLength(3)
    expect(wrapper.find('[data-test="goal-waiting"]').exists()).toBe(false)
  })

  it('names the experience and the garage that the step pays, in the words of the driver', async () => {
    const { wrapper } = await show(aTutorial(null, { goal: aGoal({ reward: { cash_cents: 0, car_xp: 100, xp: 1_160, part: null, garage: 'Kruszyn' } }) }))

    expect(rewardLines(wrapper)).toEqual(['1,160 XP', '100 car XP', 'Garage in Kruszyn'])
  })

  it('says what a step that has not started waits for, and does not lead anywhere yet', async () => {
    const { wrapper } = await show(aTutorial(null, { goal: aGoal({ waiting: 'Waits until you have $450', target: 'exchange', title: 'Buy a cheap car from the exchange' }) }))

    expect(wrapper.get('[data-test="goal-title"]').text()).toBe('Next goal Buy a cheap car from the exchange')
    expect(wrapper.get('[data-test="goal-waiting"]').text()).toBe('Waits until you have $450')
    expect(wrapper.find('[data-test="goal-show"]').exists()).toBe(false)
    expect(wrapper.classes()).toContain('goal-wait')
  })

  it('leads to the screen of the thing, and does not when the driver is there already', async () => {
    const { wrapper, router } = await show(aTutorial(null, { goal: aGoal({ target: 'mount', title: 'Fit the tires' }) }), '/garage')

    await wrapper.get('[data-test="goal-show"]').trigger('click')
    // The screen is loaded when it is first wanted, so the navigation takes a moment.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('workshop'))
    await flushPromises()

    expect(wrapper.find('[data-test="goal-show"]').exists()).toBe(false)
  })

  it('says that a step can be left for later', async () => {
    const { wrapper } = await show(aTutorial(null, { goal: aGoal({ optional: true, target: 'profile' }) }))

    expect(wrapper.get('[data-test="goal-optional"]').text()).toBe('Optional')
  })

  it('gives way to the phone while it is out, and is gone with the tutorial', async () => {
    const out = await show(aTutorial(aStep({ acknowledged: false })))
    expect(out.wrapper.find('[data-test="goal"]').exists()).toBe(false)

    const over = await show(aTutorial(null, { status: 'finished', goal: null }))
    expect(over.wrapper.find('[data-test="goal"]').exists()).toBe(false)

    const skipped = await show(aTutorial(null, { status: 'skipped' }))
    expect(skipped.wrapper.find('[data-test="goal"]').exists()).toBe(false)
  })
})
