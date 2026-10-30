import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import { i18n, setLocale } from '@/i18n'
import { aMainMission, aMission } from '@/test/missionsFixtures'

import MissionCard from './MissionCard.vue'

const mountCard = (mission = aMission()) => mount(MissionCard, { props: { mission }, global: { plugins: [i18n] } })

describe('MissionCard', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('says what a mission of a set asks, in words made of the goal and the number, with the progress and the pay', () => {
    const wrapper = mountCard(aMission({ reward: { cash_cents: 600, xp: 4, car_xp: 0, items: [{ type: 'crate', kind: 'slot' }] } }))

    expect(wrapper.get('[data-test="mission-title"]').text()).toBe('Drive 12 laps')
    expect(wrapper.get('[data-test="mission-progress"]').text()).toBe('3 / 12')
    expect(wrapper.get('[data-test="mission-bar"] i').attributes('style')).toContain('width: 25%')
    expect(wrapper.get('[data-test="mission-rewards"]').text()).toContain('$6')
    expect(wrapper.get('[data-test="mission-rewards"]').text()).toContain('4 XP')
    expect(wrapper.get('[data-test="mission-rewards"]').text()).toContain('Slot crate')
    expect(wrapper.find('[data-test="mission-claim"]').exists()).toBe(false)
  })

  it('counts money in dollars, and the plural by the number', () => {
    expect(mountCard(aMission({ objective: 'cash_earned', target: 27_900, progress: 1_000 })).get('[data-test="mission-title"]').text()).toBe('Earn $279 on the track')
    expect(mountCard(aMission({ objective: 'cash_earned', target: 27_900, progress: 1_000 })).get('[data-test="mission-progress"]').text()).toBe('$10 / $279')
    expect(mountCard(aMission({ objective: 'service', target: 1 })).get('[data-test="mission-title"]').text()).toBe('Have the car serviced 1 time')
    expect(mountCard(aMission({ objective: 'service', target: 5 })).get('[data-test="mission-title"]').text()).toBe('Have the car serviced 5 times')
  })

  it('follows the language: Polish has three forms of a lap', async () => {
    await setLocale('pl')

    expect(mountCard(aMission({ target: 1 })).get('[data-test="mission-title"]').text()).toBe('Przejedź 1 okrążenie')
    expect(mountCard(aMission({ target: 3 })).get('[data-test="mission-title"]').text()).toBe('Przejedź 3 okrążenia')
    expect(mountCard(aMission({ target: 12 })).get('[data-test="mission-title"]').text()).toBe('Przejedź 12 okrążeń')
  })

  it('has the button that takes the pay when the mission is done, and tells the parent', async () => {
    const mission = aMission({ status: 'completed', progress: 12 })
    const wrapper = mountCard(mission)

    expect(wrapper.get('[data-test="mission-done"]').text()).toBe('Done')
    expect(wrapper.get('[data-test="mission-bar"] i').attributes('style')).toContain('width: 100%')

    await wrapper.get('[data-test="mission-claim"]').trigger('click')

    expect(wrapper.emitted('claim')?.[0]).toEqual([mission])
  })

  it('shows a mission that was taken as taken, with no button', () => {
    const wrapper = mountCard(aMission({ status: 'claimed', progress: 12 }))

    expect(wrapper.get('[data-test="mission-claimed"]').text()).toBe('Taken')
    expect(wrapper.find('[data-test="mission-claim"]').exists()).toBe(false)
  })

  it('has the title and the sentence of its own for a main mission, and the XP of the car', () => {
    const wrapper = mountCard(aMainMission({ reward: { cash_cents: 12_000, xp: 40, car_xp: 300, items: [{ type: 'part', rarity: 'epic' }] } }))

    expect(wrapper.get('[data-test="mission-title"]').text()).toBe('The first 50 laps')
    expect(wrapper.get('[data-test="mission-text"]').text()).toBe('Drive 50 laps in all.')
    expect(wrapper.get('[data-test="mission-rewards"]').text()).toContain('300 car XP')
    expect(wrapper.get('[data-test="mission-rewards"]').text()).toContain('Part: Epic')
  })
})
