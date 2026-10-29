import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import { i18n, setLocale } from '@/i18n'

import SpecCard from './SpecCard.vue'

const show = (props: Record<string, unknown> = {}, slot = '') => mount(SpecCard, { props: { name: 'Rdzak GT-4', ...props }, slots: { default: slot }, global: { plugins: [i18n] } })

describe('SpecCard', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('has the name, the kind of the body, the tier and the score', () => {
    const wrapper = show({ kicker: 'Hatchback', tier: 2, score: 4_321 })

    expect(wrapper.get('[data-test="car-kind"]').text()).toBe('Hatchback')
    expect(wrapper.get('[data-test="car"]').text()).toBe('Rdzak GT-4')
    expect(wrapper.get('[data-test="car-tier"]').text()).toBe('T2')
    expect(wrapper.get('[data-test="car-tier"]').attributes('title')).toBe('Car tier: 2')
    expect(wrapper.get('[data-test="score"] small').text()).toBe('Overall score')
    expect(wrapper.get('[data-test="score"] b').text()).toBe('4,321')
  })

  it('leaves out what it has nothing to say of: the kind, the tier, and a score that is not there (a nought is a score)', () => {
    const wrapper = show()

    expect(wrapper.find('[data-test="car-kind"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="car-tier"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="score"]').exists()).toBe(false)
    expect(show({ score: 0 }).get('[data-test="score"] b').text()).toBe('0')
  })

  it('is a heading of the level it is given, and carries what is put in it under the name', () => {
    expect(show().get('[data-test="car"]').element.tagName).toBe('H2')

    const wrapper = show({ level: 'h1', headingId: 'run-title' }, '<p data-test="inside">bars</p>')

    expect(wrapper.get('h1').attributes('id')).toBe('run-title')
    expect(wrapper.get('[data-test="inside"]').text()).toBe('bars')
  })

  it('writes the tier in the language of the driver', async () => {
    await setLocale('pl')

    expect(show({ tier: 3 }).get('[data-test="car-tier"]').attributes('title')).toBe('Tier auta: 3')
    expect(show({ score: 1 }).get('[data-test="score"] small').text()).toBe('Wynik ogólny')
  })
})
