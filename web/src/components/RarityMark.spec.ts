import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import type { PartRarity } from '@/api/types'
import { i18n, setLocale } from '@/i18n'

import RarityMark from './RarityMark.vue'

const show = (rarity: PartRarity) => mount(RarityMark, { props: { rarity }, global: { plugins: [i18n] } })

describe('RarityMark', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it.each([
    ['common', 'Common', 1],
    ['uncommon', 'Uncommon', 2],
    ['rare', 'Rare', 3],
    ['epic', 'Epic', 4],
    ['legendary', 'Legendary', 5],
  ] as const)('names the %s rarity and fills as many squares as its rank, so that the color is never the only sign', (rarity, name, rank) => {
    const wrapper = show(rarity)

    expect(wrapper.get('[data-test="rarity"]').text()).toBe(name)
    expect(wrapper.findAll('.pips b')).toHaveLength(5)
    expect(wrapper.findAll('.pips b.on')).toHaveLength(rank)
    expect(wrapper.get('[data-test="rarity"]').classes()).toContain(`r${rank}`)
    // The squares are for the eye: a screen reader gets the name.
    expect(wrapper.get('.pips').attributes('aria-hidden')).toBe('true')
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')

    expect(show('epic').get('[data-test="rarity"]').text()).toBe('Epicka')
    expect(show('uncommon').get('[data-test="rarity"]').text()).toBe('Niezwykła')
  })
})
