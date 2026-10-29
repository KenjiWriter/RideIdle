import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import { i18n, setLocale } from '@/i18n'

import PartCompare from './PartCompare.vue'

const FITTED = { name: 'Turbo mini', stats: { power_mult: 1.38, lag: 4, fuel_mult: 1.28, engine_load: 14, mass_kg: 5 } }

const show = (offer: Record<string, number>, fitted: typeof FITTED | null = FITTED) => mount(PartCompare, { props: { offer: { stats: offer }, fitted }, global: { plugins: [i18n] } })

describe('PartCompare', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('names the part that is fitted and lists what differs, with the sign, the unit and the verdict of each change', () => {
    const wrapper = show({ power_mult: 1.45, lag: 3, fuel_mult: 1.3, engine_load: 14, mass_kg: 8 })

    expect(wrapper.get('[data-test="compare-title"]').text()).toBe('Against the fitted one: Turbo mini')
    expect(wrapper.findAll('li').map((item) => item.attributes('data-test'))).toEqual(['compare-power_mult', 'compare-lag', 'compare-fuel_mult', 'compare-mass_kg'])

    expect(wrapper.get('[data-test="compare-power_mult"]').text()).toBe('▲ Power +7.0% (better)')
    expect(wrapper.get('[data-test="compare-lag"]').text()).toBe('▼ Lag −1.0 (better)')
    expect(wrapper.get('[data-test="compare-fuel_mult"]').text()).toBe('▲ Fuel +0.02 (worse)')
    expect(wrapper.get('[data-test="compare-mass_kg"]').text()).toBe('▲ Mass +3.0 kg (worse)')
  })

  it('draws what is better in green and what is worse in red, and the arrow is the direction of the number', () => {
    const wrapper = show({ lag: 3, fuel_mult: 1.3 })

    // The lag goes down and is better; the fuel goes up and is worse: the colour is the verdict, not the arrow.
    expect(wrapper.get('[data-test="compare-lag"]').classes()).toContain('cmp-up')
    expect(wrapper.get('[data-test="compare-lag"]').text()).toContain('▼')
    expect(wrapper.get('[data-test="compare-fuel_mult"]').classes()).toContain('cmp-down')
    expect(wrapper.get('[data-test="compare-fuel_mult"]').text()).toContain('▲')
  })

  it('hides the arrow from a reader, who is told the verdict in words instead', () => {
    const wrapper = show({ lag: 3 })

    expect(wrapper.get('[data-test="compare-lag"] [aria-hidden="true"]').text()).toBe('▼')
    expect(wrapper.get('[data-test="compare-lag"] .sr-only').text()).toBe('(better)')
  })

  it('says that the two have the same statistics when nothing differs', () => {
    const wrapper = show({ power_mult: 1.38, lag: 4 })

    expect(wrapper.find('li').exists()).toBe(false)
    expect(wrapper.get('[data-test="compare-same"]').text()).toBe('The same statistics as the fitted part.')
  })

  it('is not there without a fitted part, or without a statistic that both have', () => {
    expect(show({ power_mult: 1.45 }, null).find('[data-test="part-compare"]').exists()).toBe(false)
    expect(show({ grip: 100 }).find('[data-test="part-compare"]').exists()).toBe(false)
  })

  it('shows the key of a statistic that the messages do not know, as the part does', () => {
    const wrapper = mount(PartCompare, { props: { offer: { stats: { flux: 5 } }, fitted: { name: 'Old', stats: { flux: 3 } } }, global: { plugins: [i18n] } })

    expect(wrapper.get('[data-test="compare-flux"]').text()).toContain('flux +2.0')
  })

  it('writes the numbers the way the language does, and names the statistics in it', async () => {
    await setLocale('pl')
    const wrapper = show({ power_mult: 1.45, lag: 3 })

    expect(wrapper.get('[data-test="compare-title"]').text()).toBe('Wobec założonej: Turbo mini')
    expect(wrapper.get('[data-test="compare-power_mult"]').text()).toBe('▲ Moc +7,0% (lepiej)')
    expect(wrapper.get('[data-test="compare-lag"]').text()).toBe('▼ Opóźnienie −1,0 (lepiej)')
  })

  it('has a name for every statistic that a part can have, in every language', async () => {
    const keys = ['grip', 'handling', 'braking', 'power_mult', 'fade_resistance', 'cooling', 'capacity_l', 'gears', 'top_speed_cap_kmh', 'clearance', 'lag', 'fuel_mult', 'engine_load', 'boost_gain', 'mass_kg', 'launch', 'power_kw', 'engine_mass_kg']

    for (const locale of ['pl', 'en', 'fr', 'de', 'pt-BR'] as const) {
      await setLocale(locale)

      for (const key of keys) {
        expect(i18n.global.te(`shop.compare.stat.${key}`), `${locale} ${key}`).toBe(true)
      }
    }
  })
})
