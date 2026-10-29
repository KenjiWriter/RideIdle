import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import type { GarageSlot } from '@/api/types'
import { i18n, setLocale } from '@/i18n'
import { DEFAULT_LAYOUT, type PlacedSlot } from '@/features/car/hotspots'
import { aMountedPart } from '@/test/garageFixtures'

import CarHotspots from './CarHotspots.vue'

const slot = (key: string, part: GarageSlot['part'] = null): GarageSlot => ({ slot: key, role: key, part, built_in: false })
const placed = (slots: GarageSlot[]): PlacedSlot[] => slots.map((entry) => ({ slot: entry, key: entry.role ?? entry.slot, spot: DEFAULT_LAYOUT[entry.role ?? entry.slot]! }))

const show = (props: Record<string, unknown> = {}) =>
  mount(CarHotspots, {
    props: { placed: placed([slot('tires', aMountedPart({ slot: 'tires', name: 'Street tires T2' })), slot('brakes'), { ...slot('engine'), built_in: true }]), picked: null, engine: 'R4 1.8', ...props },
    global: { plugins: [i18n] },
  })

describe('CarHotspots', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('puts a button on the car for every slot, where the layout says, saying what the slot is and what is on it', () => {
    const wrapper = show()

    expect(wrapper.findAll('.spot')).toHaveLength(3)
    expect(wrapper.get('[data-test="spot-tires"]').attributes('style')).toContain(`left: ${DEFAULT_LAYOUT.tires!.x}%`)
    expect(wrapper.get('[data-test="spot-tires"]').attributes('aria-label')).toBe('Tires: Street tires T2')
    expect(wrapper.get('[data-test="spot-engine"]').attributes('aria-label')).toBe('Engine: R4 1.8')
  })

  it('says which slot is looked at, and tells of a tap', async () => {
    const wrapper = show({ picked: 'brakes' })

    expect(wrapper.get('[data-test="spot-brakes"]').classes()).toContain('spot-on')
    expect(wrapper.get('[data-test="spot-brakes"] .spot-tag').text()).toBe('Brakes')
    expect(wrapper.get('[data-test="spot-tires"]').attributes('aria-pressed')).toBe('false')

    await wrapper.get('[data-test="spot-tires"]').trigger('click')

    expect(wrapper.emitted('pick')).toEqual([['tires']])
  })

  describe('the jobs of the workshop', () => {
    it('are a ring with the progress round the spot of the slot the mechanic is at, and a clock on the spot of a job that waits', () => {
      const wrapper = show({ marks: { tires: 40, brakes: null } })

      const ring = wrapper.get('[data-test="spot-tires"] [data-test="spot-working"]')
      expect(ring.attributes('style')).toContain('--p: 40')
      expect(wrapper.find('[data-test="spot-tires"] [data-test="spot-queued"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="spot-brakes"] [data-test="spot-working"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="spot-brakes"] [data-test="spot-queued"]').exists()).toBe(true)
    })

    it('are told to a reader on the spot, which says what is being done on it', () => {
      const wrapper = show({ marks: { tires: 40, brakes: null } })

      expect(wrapper.get('[data-test="spot-tires"]').attributes('aria-label')).toBe('Tires: Street tires T2 (being worked on)')
      expect(wrapper.get('[data-test="spot-brakes"]').attributes('aria-label')).toBe('Brakes: Factory (waiting in the queue)')
      expect(wrapper.get('[data-test="spot-engine"]').attributes('aria-label')).toBe('Engine: R4 1.8')
    })

    it('are not drawn for a slot that has none, or when there are none', () => {
      expect(show({ marks: { tires: 40 } }).find('[data-test="spot-engine"] .spot-ring').exists()).toBe(false)
      expect(show().find('.spot-ring').exists()).toBe(false)
      expect(show().find('.spot-queued').exists()).toBe(false)
    })
  })
})
