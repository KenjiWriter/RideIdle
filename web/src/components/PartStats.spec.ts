import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import { i18n, setLocale } from '@/i18n'

import PartStats from './PartStats.vue'

const show = (part: InstanceType<typeof PartStats>['$props']['part']) => mount(PartStats, { props: { part }, global: { plugins: [i18n] } })

const rainTires = {
  stats: { grip: 96, handling: 95, braking: 97 },
  surfaces: { asphalt_dry: 0.9, asphalt_wet: 1.05, gravel_dry: 0.55, mud: 0.5, snow: 0.55 },
}

describe('PartStats', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('says what the part does in words, with the power as a percent', () => {
    const wrapper = show({ stats: { power_mult: 1.02, clearance: 25 } })

    expect(wrapper.findAll('[data-test="part-stats"] li').map((line) => line.text())).toEqual(['Power +2%', 'Off-road 25'])
  })

  it('shows how a tire grips on every surface, and brightens the ones it is at least as good as dry asphalt on', () => {
    const wrapper = show(rainTires)

    expect(wrapper.findAll('[data-test="part-surfaces"] li').map((line) => line.text())).toEqual(['Dry ×0.90', 'Wet ×1.05', 'Gravel ×0.55', 'Mud ×0.50', 'Snow ×0.55'])
    expect(wrapper.get('[data-test="surface-asphalt_wet"]').classes()).toContain('text-cyan')
    expect(wrapper.get('[data-test="surface-asphalt_dry"]').classes()).not.toContain('text-cyan')
  })

  it('shows no surfaces for a part that is not a tire', () => {
    expect(show({ stats: { power_mult: 1.02 }, surfaces: null }).find('[data-test="part-surfaces"]').exists()).toBe(false)
    expect(show({ stats: { power_mult: 1.02 } }).find('[data-test="part-surfaces"]').exists()).toBe(false)
  })

  it('speaks the language of the driver, with the number as the language writes it', async () => {
    await setLocale('pl')

    expect(
      show(rainTires)
        .findAll('[data-test="part-surfaces"] li')
        .map((line) => line.text()),
    ).toEqual(['Sucho ×0,90', 'Mokro ×1,05', 'Żwir ×0,55', 'Błoto ×0,50', 'Śnieg ×0,55'])
  })

  it('shows the statistics of a rarer part to a tenth, as a rarity makes them fractions', () => {
    const wrapper = show({ stats: { grip: 138, handling: 135.24, braking: 138, power_mult: 1.0444 } })

    expect(wrapper.findAll('[data-test="part-stats"] li').map((line) => line.text())).toEqual(['Grip 138', 'Handling 135.2', 'Braking 138', 'Power +4.4%'])
  })
})
