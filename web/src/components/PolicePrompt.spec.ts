import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import type { PoliceStop } from '@/api/types'
import { i18n, setLocale } from '@/i18n'

import PolicePrompt from './PolicePrompt.vue'

const NOW = 1_792_692_000_000

function aStop(overrides: Partial<PoliceStop> = {}): PoliceStop {
  return {
    at_ms: NOW,
    decide_by_ms: NOW + 60_000,
    fine_cents: 1_250,
    status: 'pending',
    choice: null,
    outcome: null,
    paid_cents: null,
    options: {
      pay: { cents: 1_250, stop_ms: 120_000 },
      bribe: { cents: 1_875, risk_ppm: 250_000, fail_cents: 2_500, fail_stop_ms: 300_000 },
      lawyer: { coins: 5 },
    },
    ...overrides,
  }
}

function mountPrompt(police: PoliceStop, props: { coins?: number; disabled?: boolean; now?: number } = {}) {
  return mount(PolicePrompt, { props: { police, now: NOW, coins: 10, disabled: false, ...props }, global: { plugins: [i18n] } })
}

describe('PolicePrompt', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('says the fine, the time that is left and what each choice costs and risks', () => {
    const wrapper = mountPrompt(aStop(), { now: NOW + 15_000 })

    expect(wrapper.get('[data-test="police-title"]').text()).toBe('The police stopped you!')
    expect(wrapper.get('[data-test="police-fine"]').text()).toBe('Fine: $12.50.')
    expect(wrapper.get('[data-test="police-left"]').text()).toContain('45 s')
    expect(wrapper.get('[data-test="police-pay"]').text()).toBe('Pay the fine · $12.50')
    expect(wrapper.get('[data-test="police-bribe"]').text()).toBe('Offer a bribe · $18.75')
    expect(wrapper.text()).toContain('25% that it does not work: then $25.00')
    expect(wrapper.get('[data-test="police-lawyer"]').text()).toBe('Lawyer · 5 RideCoin')
  })

  it('tells the parent what the driver chose', async () => {
    const wrapper = mountPrompt(aStop())

    await wrapper.get('[data-test="police-pay"]').trigger('click')
    await wrapper.get('[data-test="police-bribe"]').trigger('click')
    await wrapper.get('[data-test="police-lawyer"]').trigger('click')

    expect(wrapper.emitted('choose')).toEqual([['pay'], ['bribe'], ['lawyer']])
  })

  it('does not offer the lawyer to a driver who has too few coins, and says how many they have', async () => {
    const wrapper = mountPrompt(aStop(), { coins: 3 })

    expect(wrapper.get('[data-test="police-lawyer"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="police-lawyer-poor"]').text()).toBe('Not enough RideCoin: you have 3.')

    await wrapper.get('[data-test="police-lawyer"]').trigger('click')

    expect(wrapper.emitted('choose')).toBeUndefined()
  })

  it('has no lawyer when the game sells none', () => {
    const options = aStop().options

    const wrapper = mountPrompt(aStop({ options: options && { ...options, lawyer: null } }))

    expect(wrapper.find('[data-test="police-lawyer"]').exists()).toBe(false)
  })

  it('cannot be answered while another request runs', () => {
    const wrapper = mountPrompt(aStop(), { disabled: true })

    expect(wrapper.get('[data-test="police-pay"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="police-bribe"]').attributes('disabled')).toBeDefined()
  })

  it.each([
    ['paid', 1_250, 'Fine paid: $12.50. The car stood 2 min'],
    ['bribed', 1_875, 'The bribe worked: $18.75. You drive on.'],
    ['bribe_failed', 2_500, 'The bribe did not work: $25.00 and the car stands 5 min'],
    ['lawyered', 0, 'The lawyer settled it: no fine.'],
    ['defaulted', 1_250, 'You did not choose in time: the fine of $12.50 was paid.'],
  ] as const)('says what came of it: %s', (outcome, paid, text) => {
    const wrapper = mountPrompt(aStop({ status: 'resolved', choice: outcome, outcome, paid_cents: paid, options: null }))

    expect(wrapper.find('[data-test="police"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="police-outcome"]').text()).toContain(text)
  })

  it('says nothing about a stop that was waived', () => {
    const wrapper = mountPrompt(aStop({ status: 'resolved', choice: 'waived', outcome: 'waived', paid_cents: null, options: null }))

    expect(wrapper.find('[data-test="police-outcome"]').exists()).toBe(false)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const wrapper = mountPrompt(aStop())

    expect(wrapper.get('[data-test="police-title"]').text()).toBe('Zatrzymała cię policja!')
    // The currency of a Polish driver is written with a no-break space.
    expect(wrapper.get('[data-test="police-pay"]').text().replace(/\s/g, ' ')).toBe('Zapłać mandat · 12,50 $')
  })
})
