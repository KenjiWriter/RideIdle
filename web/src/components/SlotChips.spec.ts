import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import type { GarageSlot } from '@/api/types'
import { i18n, setLocale } from '@/i18n'

import SlotChips from './SlotChips.vue'

const slot = (key: string, role: GarageSlot['role'] = key): GarageSlot => ({ slot: key, role, part: null, built_in: false })
const show = (slots: GarageSlot[], picked: string | null = null) => mount(SlotChips, { props: { slots, picked }, global: { plugins: [i18n] } })

describe('SlotChips', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('is a row with a chip for every slot it is given, named in the language of the driver (a slot the game gives no word for is its key)', () => {
    const wrapper = show([slot('boost'), slot('flux-capacitor', null)])

    expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Forced induction', 'flux-capacitor'])
    expect(wrapper.get('[data-test="slots"]').attributes('aria-label')).toBe('Other parts')
  })

  it('says which slot is looked at, and tells of a tap', async () => {
    const wrapper = show([slot('boost'), slot('tank')], 'tank')

    expect(wrapper.get('[data-test="chip-tank"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-test="chip-tank"]').classes()).toContain('chip-am')
    expect(wrapper.get('[data-test="chip-boost"]').attributes('aria-pressed')).toBe('false')

    await wrapper.get('[data-test="chip-boost"]').trigger('click')

    expect(wrapper.emitted('pick')).toEqual([['boost']])
  })

  it('is not there when every slot has a place on the picture', () => {
    expect(show([]).find('[data-test="slots"]').exists()).toBe(false)
  })
})
