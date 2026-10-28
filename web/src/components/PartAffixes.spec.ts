import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import type { PartAffix, PartUnique } from '@/api/types'
import { i18n, setLocale } from '@/i18n'

import PartAffixes from './PartAffixes.vue'

const affix = (overrides: Partial<PartAffix>): PartAffix => ({
  key: 'grip',
  name: 'Grip',
  effect: 'stat',
  stat: 'grip',
  surfaces: null,
  value_ppm: 35_000,
  percent: 3.5,
  ...overrides,
})

const show = (affixes: PartAffix[] = [], unique: PartUnique | null = null, compact = false) =>
  mount(PartAffixes, { props: { part: { affixes, unique }, compact }, global: { plugins: [i18n] } })

describe('PartAffixes', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('shows nothing for a part that has no affixes and no unique effect', () => {
    const wrapper = show()

    expect(wrapper.find('[data-test="part-affixes"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="part-unique"]').exists()).toBe(false)
  })

  it('says each affix in words, with the name of the rules and its amount, in the order they were drawn', () => {
    const wrapper = show([
      affix({}),
      affix({ key: 'wear', name: 'Durability', effect: 'wear', stat: null, value_ppm: 120_000, percent: 12 }),
      affix({ key: 'fuel', name: 'Efficiency', effect: 'fuel', stat: null, value_ppm: 30_000, percent: 3 }),
      affix({ key: 'reliability', name: 'Reliability', effect: 'reliability', stat: null, value_ppm: 80_000, percent: 8 }),
      affix({ key: 'wet', name: 'Wet grip', effect: 'surface', stat: null, surfaces: ['asphalt_wet', 'mud'], value_ppm: 50_000, percent: 5 }),
    ])

    expect(wrapper.findAll('[data-test="part-affixes"] li').map((li) => li.text())).toEqual([
      '✦ Grip +3.5%',
      '✦ Durability: wear −12%',
      '✦ Efficiency: fuel −3%',
      '✦ Reliability: breakdowns −8%',
      '✦ Wet grip +5% (Wet, Mud)',
    ])
  })

  it('speaks the language of the driver, with its decimals and its spaces', async () => {
    await setLocale('pl')

    expect(show([affix({ name: 'Przyczepność' })]).get('[data-test="affix-grip"]').text()).toBe('✦ Przyczepność +3,5%')

    await setLocale('fr')

    expect(show([affix({ key: 'wear', name: 'Durabilité', effect: 'wear', stat: null, percent: 12 })]).get('[data-test="affix-wear"]').text()).toBe('✦ Durabilité : usure −12 %')
  })

  it('names the unique effect of a legendary part and says what a special one does', () => {
    const wrapper = show([], { name: 'Adaptive compound', special: 'surface_loss', percent: 50, mods: null })

    expect(wrapper.get('[data-test="part-unique"]').text()).toContain('Adaptive compound')
    expect(wrapper.get('[data-test="unique-text"]').text()).toBe('Keeps 50% of the grip it would lose on a surface that does not suit the tires')
    // A screen reader gets that it is the effect of the part, and not another affix.
    expect(wrapper.get('[data-test="part-unique"] .sr-only').text()).toBe('Unique effect:')
  })

  it('says the modifiers of a unique effect like affixes, with a name of their own when the rules have none for them', () => {
    const wrapper = show([], {
      name: 'Free flow',
      special: null,
      percent: null,
      mods: [
        { effect: 'stat', stat: 'power', name: 'Power', percent: 2 },
        { effect: 'fuel', stat: null, name: null, percent: 3 },
      ],
    })

    expect(wrapper.get('[data-test="part-unique"]').text()).toContain('Power +2%')
    expect(wrapper.get('[data-test="part-unique"]').text()).toContain('Efficiency: fuel −3%')
    expect(wrapper.find('[data-test="unique-text"]').exists()).toBe(false)
  })

  it('keeps a compact list closer to the part it belongs to', () => {
    expect(show([affix({})], null, true).get('[data-test="part-affixes"]').classes()).toContain('mt-0.5')
    expect(show([affix({})]).get('[data-test="part-affixes"]').classes()).toContain('mt-1')
  })
})
