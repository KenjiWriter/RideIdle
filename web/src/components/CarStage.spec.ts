import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import { i18n, setLocale } from '@/i18n'

import CarStage from './CarStage.vue'

const show = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) =>
  mount(CarStage, { props: { name: 'Rdzak GT-4', ...props }, slots, global: { plugins: [i18n] } })

describe('CarStage', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('draws the name in outline behind the car, where a reader does not meet it twice', () => {
    const name = show({ src: '/api/v1/media/aaaaaaaa.png' }).get('.stage-name')

    expect(name.text()).toBe('Rdzak GT-4')
    expect(name.attributes('aria-hidden')).toBe('true')
  })

  it('shows the picture of the pack in place of the one the app brings', () => {
    const picture = show({ src: '/api/v1/media/aaaaaaaa.png', fallback: '/assets/rdzak.jpg' }).get('[data-test="picture"]')

    expect(picture.attributes('src')).toBe('/api/v1/media/aaaaaaaa.png')
    expect(picture.attributes('alt')).toBe('Rdzak GT-4')
    expect(picture.classes()).toContain('stage-car')
  })

  it('shows the picture of the app when the pack gives none', () => {
    expect(show({ src: null, fallback: '/assets/rdzak.jpg' }).get('[data-test="picture"]').attributes('src')).toBe('/assets/rdzak.jpg')
  })

  it('stands the outline of a body on the floor for a car that has no picture at all, which a reader hears of as the car', () => {
    const wrapper = show()

    expect(wrapper.find('[data-test="picture"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="picture-empty"]').attributes('role')).toBe('img')
    expect(wrapper.get('[data-test="picture-empty"]').attributes('aria-label')).toBe('Rdzak GT-4')
  })

  it('has the floor for what stands beside the car (the level, the state) and the corner for a price, only when they are given', () => {
    expect(show().find('.stage-tags').exists()).toBe(false)
    expect(show().find('.stage-price').exists()).toBe(false)

    const wrapper = show({}, { default: '<span data-test="tag">LV 3</span>', price: '<b data-test="cost">$1</b>' })

    expect(wrapper.get('.stage-tags [data-test="tag"]').text()).toBe('LV 3')
    expect(wrapper.get('.stage-price [data-test="cost"]').text()).toBe('$1')
  })

  it('is smaller in a list of cars', () => {
    expect(show().classes()).not.toContain('stage-sm')
    expect(show({ compact: true }).classes()).toContain('stage-sm')
  })
})
