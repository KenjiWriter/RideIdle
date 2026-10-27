import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { useTutorialStore } from '@/stores/tutorial'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'
import { aStep, aTutorial } from '@/test/tutorialFixtures'

import GameShell from './GameShell.vue'

const fetchMock = vi.fn()
const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

/** A screen of the game with nothing on it but the shell, which is what the tutorial has to live in. */
const screen = (props: { loadTutorial?: boolean } = {}) => defineComponent({ render: () => h(GameShell, { loadRun: false, ...props }, () => h('p', { 'data-test': 'inside' }, 'inside')) })

const tutorialCalls = (calls: { method: string; url: string }[]) => calls.filter((call) => call.method === 'GET' && call.url === '/api/v1/tutorial').length

describe('GameShell', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('has the phone of the mentor on any screen of the game, once, not only on the garage and the race screen', async () => {
    serveApi(fetchMock, {
      'GET /api/v1/tutorial': () => respond(200, aTutorial(aStep({ key: 'mount', number: 7, target: 'mount', sender: '+48 555 010 400', sms: ['Open the Workshop. — K.'] }))),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(screen(), '/workshop')
    await flushPromises()

    expect(wrapper.get('[data-test="inside"]').text()).toBe('inside')
    expect(wrapper.findAll('[data-test="tutorial"]')).toHaveLength(1)
    expect(wrapper.get('[data-test="tutorial-sender"]').text()).toBe('+48 555 010 400')
  })

  it('asks for the tutorial once when the screen does not, and does not ask again when it is known', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/tutorial': () => respond(200, aTutorial(null)), 'GET /api/v1/runs/current': noRun })

    await mountView(screen(), '/shop')
    await flushPromises()
    expect(tutorialCalls(calls)).toBe(1)

    // Another screen of the game, with the tutorial known already, asks for nothing.
    await mountView(screen(), '/map', { prepare: () => (useTutorialStore().asked = true) })
    await flushPromises()
    expect(tutorialCalls(calls)).toBe(1)
  })

  it('leaves the tutorial to the screen that asks for it in its own order', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/tutorial': () => respond(200, aTutorial(null)), 'GET /api/v1/runs/current': noRun })

    await mountView(screen({ loadTutorial: false }), '/garage')
    await flushPromises()

    expect(tutorialCalls(calls)).toBe(0)
  })

  it('says what the mentor paid on whichever screen the driver is on: the experience, the part and the garage of a city', async () => {
    await setLocale('pl')
    serveApi(fetchMock, {
      'GET /api/v1/tutorial': () => respond(200, aTutorial(null, { rewarded: [{ key: 'locked', cash_cents: 0, car_xp: 0, xp: 1_160, part: null, garage: 'kruszyn' }, { key: 'repair', cash_cents: 0, car_xp: 0, xp: 0, part: 'street-tires-t2', garage: null }] })),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(screen(), '/map')
    await flushPromises()

    const text = wrapper.get('[data-test="tutorial-reward"]').text()

    expect(text).toContain('K. dorzucił Ci')
    expect(text).toContain('XP.')
    expect(text).toContain('K. zostawił Ci część w inwentarzu: zamontujesz ją w Warsztacie.')
    // The map has not been asked for the name of the city, so the slug stands in for it.
    expect(text).toContain('K. załatwił Ci garaż w mieście Kruszyn.')
    expect(useTutorialStore().reward).toBeNull()

    await setLocale('en')
  })

  it('says the same in English for the experience that went to the driver', async () => {
    serveApi(fetchMock, {
      'GET /api/v1/tutorial': () => respond(200, aTutorial(null, { rewarded: [{ key: 'travel', cash_cents: 30_000, car_xp: 0, xp: 1_960, part: null, garage: null }] })),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(screen(), '/map')
    await flushPromises()

    expect(wrapper.get('[data-test="tutorial-reward"]').text()).toBe('K. sent you $300.00. K. gave you 1,960 XP.')
  })
})
