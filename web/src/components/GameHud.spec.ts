import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Run } from '@/api/types'
import { i18n, setLocale } from '@/i18n'
import { useRunStore } from '@/stores/run'
import { liveFields } from '@/test/runFixtures'

import GameHud from './GameHud.vue'

/** The clock of the server at the midnight of a day of the game, and a minute of the game is 7.5 s of it. */
const MIDNIGHT = 1_792_692_000_000
const GAME_MINUTE_MS = 7_500

const at = (hour: number, minute: number): number => MIDNIGHT + (hour * 60 + minute) * GAME_MINUTE_MS

function show(weather?: string) {
  const pinia = createPinia()
  setActivePinia(pinia)

  if (weather) {
    const store = useRunStore()
    const fields = liveFields(Date.now())

    store.run = { ...fields, weather: { ...fields.weather, now: weather } } as unknown as Run
  }

  return mount(GameHud, { global: { plugins: [pinia, i18n] } })
}

describe('GameHud', () => {
  beforeEach(async () => {
    await setLocale('en')
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] })
    vi.setSystemTime(at(11, 20))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows the time of the game, which is three real hours for a day, even before there is a car', () => {
    const wrapper = show()

    expect(wrapper.get('[data-test="clock"]').text()).toBe('11:20')
    expect(wrapper.get('[data-test="clock"]').attributes('title')).toBe('Game time: 11:20, Day')
  })

  it('adds the weather of the region where the car drives', () => {
    expect(show('rain').get('[data-test="clock"]').text()).toBe('11:20 · rain')
  })

  it('moves on with the time of the game: a minute of it is seven and a half seconds', async () => {
    const wrapper = show('dry')

    await vi.advanceTimersByTimeAsync(16_000)

    expect(wrapper.get('[data-test="clock"]').text()).toBe('11:22 · dry')
  })

  it('names the phase of the day in the language of the driver', async () => {
    await setLocale('pl')
    vi.setSystemTime(at(23, 10))
    const wrapper = show('snow')

    expect(wrapper.get('[data-test="clock"]').text()).toBe('23:10 · śnieg')
    expect(wrapper.get('[data-test="clock"]').attributes('title')).toBe('Czas w grze: 23:10, Noc')
  })
})
