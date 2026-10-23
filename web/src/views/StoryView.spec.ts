import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import StoryView from './StoryView.vue'

const fetchMock = vi.fn()
const profile = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: false, identities: [] }

async function show() {
  const mounted = await mountView(StoryView, '/story')
  await flushPromises()

  return mounted
}

/** The driver asks the system for less motion. */
function reduceMotion(): void {
  vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: true }))
  window.matchMedia = vi.fn().mockReturnValue({ matches: true })
}

describe('StoryView', () => {
  beforeEach(async () => {
    localStorage.clear()
    storeToken('device-token')
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    serveApi(fetchMock, { 'GET /api/v1/me': () => respond(200, { data: profile }) })
    vi.useFakeTimers()
    await setLocale('en')
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    Reflect.deleteProperty(window, 'matchMedia')
  })

  it('starts with the words of the first scene, and says it is the first of four', async () => {
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="story-text"]').text()).toBe('“Listen, I have to disappear for a while.”')
    expect(wrapper.text()).toContain('Scene 1 of 4')
    expect(wrapper.find('[data-test="story-sign"]').exists()).toBe(false)
  })

  it('moves on by itself when the time of the scene is up', async () => {
    const { wrapper } = await show()

    await vi.advanceTimersByTimeAsync(6_000)
    expect(wrapper.get('[data-test="story-text"]').text()).toContain('I’m leaving you the workshop')

    await vi.advanceTimersByTimeAsync(6_000)
    expect(wrapper.get('[data-test="story-text"]').text()).toContain('Złomowo lives on racing')
  })

  it('moves on at a tap, without waiting', async () => {
    const { wrapper } = await show()

    await wrapper.get('[data-test="story-stage"]').trigger('click')

    expect(wrapper.get('[data-test="story-text"]').text()).toContain('I’m leaving you the workshop')
  })

  it('lets the driver skip after three seconds, and not before', async () => {
    const { wrapper, router } = await show()

    expect(wrapper.find('[data-test="story-skip"]').exists()).toBe(false)
    await vi.advanceTimersByTimeAsync(3_000)
    expect(wrapper.find('[data-test="story-skip"]').exists()).toBe(true)

    await wrapper.get('[data-test="story-skip"]').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('choose'))
  })

  it('puts the name of the driver on the sign in the last scene, with the first message of the mentor', async () => {
    const { wrapper } = await show()
    for (let scene = 0; scene < 3; scene += 1) {
      await wrapper.get('[data-test="story-stage"]').trigger('click')
    }

    expect(wrapper.get('[data-test="story-name"]').text()).toBe('Maciek_Turbo')
    expect(wrapper.get('[data-test="story-text"]').text()).toBe('“Manager of the workshop: Maciek_Turbo.”')
    expect(wrapper.get('[data-test="story-sms"]').text()).toBe('+48 555 010 112: It’s me. Don’t ask. Pick a car, I’ll text you the rest. — K.')
  })

  it('does not leave the last scene by itself, and offers the choice of a wreck after two seconds', async () => {
    const { wrapper, router } = await show()
    for (let scene = 0; scene < 3; scene += 1) {
      await wrapper.get('[data-test="story-stage"]').trigger('click')
    }

    expect(wrapper.find('[data-test="story-choose"]').exists()).toBe(false)

    await vi.advanceTimersByTimeAsync(60_000)
    expect(router.currentRoute.value.name).toBe('story')
    expect(wrapper.get('[data-test="story-choose"]').text()).toBe('Choose a wreck')

    await wrapper.get('[data-test="story-choose"]').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('choose'))
  })

  it('keeps the camera and the clock still for a driver who asks for less motion, and offers everything at once', async () => {
    reduceMotion()
    const { wrapper } = await show()

    // Nothing moves on by itself, however long the driver reads.
    await vi.advanceTimersByTimeAsync(60_000)
    expect(wrapper.get('[data-test="story-text"]').text()).toBe('“Listen, I have to disappear for a while.”')
    expect(wrapper.find('[data-test="story-skip"]').exists()).toBe(true)

    for (let scene = 0; scene < 3; scene += 1) {
      await wrapper.get('[data-test="story-stage"]').trigger('click')
    }
    await vi.advanceTimersByTimeAsync(0)
    expect(wrapper.find('[data-test="story-choose"]').exists()).toBe(true)
  })

  it('speaks the language of the driver, with the French spaces in the right places', async () => {
    await setLocale('fr')
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="story-text"]').text()).toBe('« Écoute, je dois disparaître un moment. »')

    await setLocale('en')
  })
})
