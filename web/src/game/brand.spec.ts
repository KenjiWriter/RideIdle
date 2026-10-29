import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import BrandMark from '@/components/BrandMark.vue'
import { DEFAULT_GAME, setGame } from './index'
import { brandParts } from './brand'

afterEach(() => setGame())

describe('brandParts', () => {
  it('splits a name where a small letter is followed by a capital', () => {
    expect(brandParts('RideIdle')).toEqual({ first: 'Ride', second: 'Idle', spaced: false })
    expect(brandParts('StarShips')).toEqual({ first: 'Star', second: 'Ships', spaced: false })
    expect(brandParts('Żeglarz3Idle')).toEqual({ first: 'Żeglarz3', second: 'Idle', spaced: false })
  })

  it('splits a name at its first space, and keeps the rest together', () => {
    expect(brandParts('Star Ships')).toEqual({ first: 'Star', second: 'Ships', spaced: true })
    expect(brandParts('Star Ships Idle')).toEqual({ first: 'Star', second: 'Ships Idle', spaced: true })
    expect(brandParts('  Statki   Idle ')).toEqual({ first: 'Statki', second: 'Idle', spaced: true })
  })

  it('does not split a name of one word', () => {
    expect(brandParts('Ships')).toEqual({ first: 'Ships', second: null, spaced: false })
    expect(brandParts('SHIPS')).toEqual({ first: 'SHIPS', second: null, spaced: false })
    expect(brandParts('ships')).toEqual({ first: 'ships', second: null, spaced: false })
    expect(brandParts('')).toEqual({ first: '', second: null, spaced: false })
  })
})

describe('BrandMark', () => {
  it('draws the car game as it always was: Ride, and Idle in the part that is amber', () => {
    const wrapper = mount(BrandMark)

    expect(wrapper.get('[data-test="brand"]').text()).toBe('RideIdle')
    expect(wrapper.get('[data-test="brand"]').html()).toBe('<span class="brand" data-test="brand">Ride<i>Idle</i></span>')
    expect(wrapper.get('i').text()).toBe('Idle')
  })

  it('draws the name of the game that is in force, in two parts', () => {
    setGame({ ...DEFAULT_GAME, slug: 'ships', name: 'Star Ships', is_default: false })

    const wrapper = mount(BrandMark)

    expect(wrapper.get('[data-test="brand"]').text()).toBe('Star Ships')
    expect(wrapper.get('[data-test="brand"]').html()).toBe('<span class="brand" data-test="brand">Star <i>Ships</i></span>')
  })

  it('draws a name of one word all in the first colour, with no amber part', () => {
    setGame({ ...DEFAULT_GAME, slug: 'ships', name: 'Ships', is_default: false })

    const wrapper = mount(BrandMark)

    expect(wrapper.get('[data-test="brand"]').text()).toBe('Ships')
    expect(wrapper.find('i').exists()).toBe(false)
  })

  it('follows the game when another is put in force', async () => {
    const wrapper = mount(BrandMark)

    setGame({ ...DEFAULT_GAME, name: 'Port Idle' })
    await wrapper.vm.$nextTick()

    expect(wrapper.get('i').text()).toBe('Idle')
    expect(wrapper.get('[data-test="brand"]').text()).toBe('Port Idle')
  })
})
