import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import { carCutout, carPicture } from '@/features/art'
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

  it('shows the picture of the pack in place of the one the app brings, on the dark stage that fades a picture with a ground of its own', () => {
    const wrapper = show({ src: '/api/v1/media/aaaaaaaa.png', slug: 'rdzak-gt-4' })
    const picture = wrapper.get('[data-test="picture"]')

    expect(picture.attributes('src')).toBe('/api/v1/media/aaaaaaaa.png')
    expect(picture.attributes('alt')).toBe('Rdzak GT-4')
    expect(picture.classes()).toContain('stage-car')
    expect(wrapper.classes()).not.toContain('stage-light')
  })

  it('stands the car the app has cut out of its ground on the light stage when the pack gives none', () => {
    const wrapper = show({ src: null, slug: 'rdzak-gt-4' })

    expect(wrapper.get('[data-test="picture"]').attributes('src')).toBe(carCutout('rdzak-gt-4'))
    expect(wrapper.get('[data-test="picture"]').attributes('src')).not.toBe(carPicture('rdzak-gt-4'))
    expect(wrapper.classes()).toContain('stage-light')
  })

  it('keeps the dark stage for a car that the app has only with its ground, or not at all', () => {
    expect(show({ src: null, slug: 'a-car-the-app-does-not-know' }).classes()).not.toContain('stage-light')
    expect(show({ src: null, slug: null }).classes()).not.toContain('stage-light')
    expect(show({ src: null, slug: null }).find('[data-test="picture-empty"]').exists()).toBe(true)
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
