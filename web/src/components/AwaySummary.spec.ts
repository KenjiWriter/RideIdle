import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import type { RunSummary } from '@/api/types'
import { i18n, setLocale } from '@/i18n'

import AwaySummary from './AwaySummary.vue'

function aSummary(overrides: Partial<RunSummary> = {}): RunSummary {
  return { laps: 120, cash_cents: 186_000, xp: 3_600, from_ms: 0, to_ms: 7_200_000, stop_reason: null, ...overrides }
}

function show(summary: RunSummary) {
  return mount(AwaySummary, { props: { summary, nowMs: 7_200_000 }, global: { plugins: [i18n] } })
}

describe('AwaySummary', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('says what the car earned while the driver was away', () => {
    const wrapper = show(aSummary())

    expect(wrapper.get('[data-test="away-laps"]').text()).toBe('120')
    expect(wrapper.get('[data-test="away-cash"]').text()).toBe('+$1,860.00')
    expect(wrapper.find('[data-test="away-refills"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="away-bonus"]').exists()).toBe(false)
  })

  it('says how many times the tank was filled for the driver and how many refills that took, and the bonus of the VIP account', () => {
    const wrapper = show(aSummary({ auto_refills: 3, refill_charges: 2, bonus_cash_cents: 31_000, bonus_xp: 600 }))

    expect(wrapper.get('[data-test="away-refills"]').text()).toBe('The tank was filled for you 3 times (stock −2)')
    expect(wrapper.get('[data-test="away-bonus"]').text()).toBe('VIP bonus: +$310.00')
  })

  it('says the refills were free when the pass paid for them', () => {
    const wrapper = show(aSummary({ auto_refills: 1, refill_charges: 0 }))

    expect(wrapper.get('[data-test="away-refills"]').text()).toBe('The tank was filled for you 1 time, free with the pass')
  })

  it('says it in the other languages of the game', async () => {
    await setLocale('pl')
    expect(show(aSummary({ auto_refills: 5, refill_charges: 5 })).get('[data-test="away-refills"]').text()).toBe('Bak napełniono za Ciebie 5 razy (zapas −5)')
    expect(show(aSummary({ auto_refills: 2, refill_charges: 0 })).get('[data-test="away-refills"]').text()).toBe('Bak napełniono za Ciebie 2 razy, za darmo dzięki przepustce')

    await setLocale('fr')
    expect(show(aSummary({ auto_refills: 2, refill_charges: 1, bonus_cash_cents: 1_000 })).get('[data-test="away-bonus"]').text()).toBe('Bonus VIP : +10,00 $')
  })
})
