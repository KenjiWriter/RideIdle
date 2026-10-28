import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import { i18n, setLocale } from '@/i18n'

import SpeedupButton from './SpeedupButton.vue'

function mountButton(props: Partial<InstanceType<typeof SpeedupButton>['$props']> = {}) {
  return mount(SpeedupButton, {
    props: { coins: 3, balance: 10, label: 'Finish now', disabled: false, testId: 'finish-1', ...props },
    global: { plugins: [i18n] },
  })
}

describe('SpeedupButton', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('says what finishing costs in coins', () => {
    const wrapper = mountButton()

    expect(wrapper.get('[data-test="finish-1"]').text()).toBe('Finish now · 3 RideCoin')
    expect(wrapper.find('[data-test="finish-1-poor"]').exists()).toBe(false)
  })

  it('asks before it takes the coins, and only a yes is told to the parent', async () => {
    const wrapper = mountButton()

    await wrapper.get('[data-test="finish-1"]').trigger('click')

    expect(wrapper.get('[data-test="finish-1-sure"]').text()).toContain('Pay 3 RideCoin? You have 10.')
    expect(wrapper.emitted('confirm')).toBeUndefined()

    await wrapper.get('[data-test="finish-1-confirm"]').trigger('click')

    expect(wrapper.emitted('confirm')).toHaveLength(1)
    // Back to the button: a second tap asks again.
    expect(wrapper.find('[data-test="finish-1-sure"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="finish-1"]').exists()).toBe(true)
  })

  it('keeps the coins when the driver says not now', async () => {
    const wrapper = mountButton()

    await wrapper.get('[data-test="finish-1"]').trigger('click')
    await wrapper.get('[data-test="finish-1-keep"]').trigger('click')

    expect(wrapper.emitted('confirm')).toBeUndefined()
    expect(wrapper.find('[data-test="finish-1-sure"]').exists()).toBe(false)
  })

  it('is not offered to a driver who has too few coins, and says how many they have', async () => {
    const wrapper = mountButton({ coins: 6, balance: 5 })

    expect(wrapper.get('[data-test="finish-1"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="finish-1-poor"]').text()).toBe('Not enough RideCoin: you have 5.')

    await wrapper.get('[data-test="finish-1"]').trigger('click')

    expect(wrapper.find('[data-test="finish-1-sure"]').exists()).toBe(false)
  })

  it('is disabled while another request runs', () => {
    const wrapper = mountButton({ disabled: true })

    expect(wrapper.get('[data-test="finish-1"]').attributes('disabled')).toBeDefined()
  })

  it('does not take the coins while another request runs, even after a yes', async () => {
    const wrapper = mountButton()

    await wrapper.get('[data-test="finish-1"]').trigger('click')
    await wrapper.setProps({ disabled: true })

    expect(wrapper.get('[data-test="finish-1-confirm"]').attributes('disabled')).toBeDefined()
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const wrapper = mountButton({ label: 'Skończ od razu' })

    expect(wrapper.get('[data-test="finish-1"]').text()).toBe('Skończ od razu · 3 RideCoin')

    await wrapper.get('[data-test="finish-1"]').trigger('click')

    expect(wrapper.get('[data-test="finish-1-sure"]').text()).toContain('Zapłacić 3 RideCoin? Masz 10.')
  })
})
