import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'
import { aStep, aTutorial } from '@/test/tutorialFixtures'
import { useTutorialStore } from '@/stores/tutorial'

import TutorialOverlay from './TutorialOverlay.vue'

const fetchMock = vi.fn()
const targets: HTMLElement[] = []

/** Something on the page for the step to point at: jsdom has no layout, so the place is given. */
function putOnPage(target: string, box = { top: 300, left: 20, width: 340, height: 56 }): HTMLElement {
  const element = document.createElement('div')
  element.setAttribute('data-tutorial', target)
  element.getBoundingClientRect = () => ({ ...box, right: box.left + box.width, bottom: box.top + box.height, x: box.left, y: box.top, toJSON: () => box }) as DOMRect
  document.body.append(element)
  targets.push(element)

  return element
}

async function show(answer = aTutorial()) {
  const mounted = await mountView(TutorialOverlay, '/garage', { prepare: () => (useTutorialStore().tutorial = answer.data) })
  await flushPromises()

  return mounted
}

describe('TutorialOverlay', () => {
  beforeEach(async () => {
    localStorage.clear()
    storeToken('device-token')
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    targets.splice(0).forEach((element) => element.remove())
  })

  it('shows nothing when no step waits', async () => {
    const { wrapper } = await show(aTutorial(null))

    expect(wrapper.find('[data-test="tutorial"]').exists()).toBe(false)
  })

  it('shows nothing once the tutorial is skipped, whatever step was left', async () => {
    const { wrapper } = await show(aTutorial(aStep(), { status: 'skipped' }))

    expect(wrapper.find('[data-test="tutorial"]').exists()).toBe(false)
  })

  it('takes out a phone with the messages of the mentor, from his number, at the step of the tutorial', async () => {
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="tutorial-sender"]').text()).toBe('+48 555 010 247')
    expect(wrapper.get('[data-test="tutorial-step"]').text()).toBe('Step 4/5')
    expect(wrapper.findAll('[data-test="tutorial-sms"]').map((bubble) => bubble.text())).toEqual([
      'New number, same me. Don’t ask.',
      'The tank is almost empty. Tap “Fill up” so the car keeps driving, even when you’re away. — K.',
    ])
    expect(wrapper.get('[data-test="tutorial-reply"]').text()).toBe('OK, filling up')
    expect(wrapper.get('[data-test="tutorial-phone"]').attributes('aria-label')).toBe('Message from +48 555 010 247')
    expect(wrapper.find('[data-test="tutorial-dim"]').exists()).toBe(true)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="tutorial-step"]').text()).toBe('Krok 4/5')
    expect(wrapper.get('[data-test="tutorial-skip"]').text()).toBe('Pomiń')

    await setLocale('en')
  })

  it('rings the thing the step points at, with room round it', async () => {
    putOnPage('refuel')
    const { wrapper } = await show()

    const ring = wrapper.get('[data-test="tutorial-ring"]')
    expect(ring.attributes('style')).toContain('top: 294px')
    expect(ring.attributes('style')).toContain('left: 14px')
    expect(ring.attributes('style')).toContain('width: 352px')
    expect(ring.attributes('style')).toContain('height: 68px')
  })

  it('brings the thing into view when the page has it, once, even if the page was still loading when the step came', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const { wrapper } = await show()
    expect(wrapper.find('[data-test="tutorial-ring"]').exists()).toBe(false)

    // The page has loaded since.
    const scrollIntoView = vi.fn()
    putOnPage('refuel').scrollIntoView = scrollIntoView
    await vi.advanceTimersByTimeAsync(300)

    expect(scrollIntoView).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="tutorial-ring"]').exists()).toBe(true)

    // The driver may scroll away: it is not brought back.
    await vi.advanceTimersByTimeAsync(2_000)
    expect(scrollIntoView).toHaveBeenCalledTimes(1)

    vi.useRealTimers()
  })

  it('has no ring when the thing is not on the page', async () => {
    const { wrapper } = await show()

    expect(wrapper.find('[data-test="tutorial-ring"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(true)
  })

  it('answers the messages, and then puts the phone and the dim layer away but keeps the ring on the thing', async () => {
    putOnPage('refuel')
    const calls = serveApi(fetchMock, { 'POST /api/v1/tutorial/reply': () => respond(200, aTutorial(aStep({ acknowledged: true }))) })
    const { wrapper } = await show()

    await wrapper.get('[data-test="tutorial-reply"]').trigger('click')
    await flushPromises()

    expect(calls.map((call) => `${call.method} ${call.url}`)).toEqual(['POST /api/v1/tutorial/reply'])
    expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tutorial-dim"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tutorial-skip"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tutorial-ring"]').exists()).toBe(true)
  })

  it('does not take the same answer twice while the first is on its way', async () => {
    let finish: (response: Response) => void = () => undefined
    const calls = serveApi(fetchMock, { 'POST /api/v1/tutorial/reply': () => new Promise<Response>((resolve) => (finish = resolve)) })
    const { wrapper } = await show()

    await wrapper.get('[data-test="tutorial-reply"]').trigger('click')
    await wrapper.get('[data-test="tutorial-reply"]').trigger('click')

    expect(calls).toHaveLength(1)
    finish(respond(200, aTutorial(null)))
    await flushPromises()
  })

  it('says so on the phone when the answer did not get through, and the phone stays', async () => {
    serveApi(fetchMock, { 'POST /api/v1/tutorial/reply': () => respond(500, { message: 'Server error' }) })
    const { wrapper } = await show()

    await wrapper.get('[data-test="tutorial-reply"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="tutorial-error"]').text()).toBe('Something went wrong. Try again.')
    expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(true)
  })

  it('does not say who is writing, however it is asked', async () => {
    const { wrapper } = await show()

    await wrapper.get('[data-test="tutorial-who"]').trigger('click')

    expect(wrapper.get('[data-test="tutorial-deflect"]').text()).toBe('Don’t ask. — K.')
    expect(wrapper.find('[data-test="tutorial-who"]').exists()).toBe(false)
  })

  it('asks before it lets the driver skip, and keeps the tutorial when the driver changes their mind', async () => {
    const calls = serveApi(fetchMock, {})
    const { wrapper } = await show()

    await wrapper.get('[data-test="tutorial-skip"]').trigger('click')
    expect(wrapper.get('[data-test="tutorial-confirm"]').text()).toContain('You will lose the rewards of the steps still ahead')

    await wrapper.get('[data-test="tutorial-keep"]').trigger('click')

    expect(wrapper.find('[data-test="tutorial-confirm"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(true)
    expect(calls).toHaveLength(0)
  })

  it('leaves the tutorial when the driver confirms the skip', async () => {
    const calls = serveApi(fetchMock, { 'POST /api/v1/tutorial/skip': () => respond(200, aTutorial(null, { status: 'skipped', done: 3 })) })
    const { wrapper } = await show()

    await wrapper.get('[data-test="tutorial-skip"]').trigger('click')
    await wrapper.get('[data-test="tutorial-skip-confirm"]').trigger('click')
    await flushPromises()

    expect(calls.map((call) => `${call.method} ${call.url}`)).toEqual(['POST /api/v1/tutorial/skip'])
    expect(wrapper.find('[data-test="tutorial"]').exists()).toBe(false)
  })

  it('closes the question of the skip with the escape key', async () => {
    const { wrapper } = await show()
    await wrapper.get('[data-test="tutorial-skip"]').trigger('click')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()

    expect(wrapper.find('[data-test="tutorial-confirm"]').exists()).toBe(false)
  })
})
