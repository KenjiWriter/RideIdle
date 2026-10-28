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
const mounted: { unmount: () => void }[] = []

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
  const view = await mountView(TutorialOverlay, '/garage', { prepare: () => (useTutorialStore().tutorial = answer.data) })
  mounted.push(view.wrapper)
  await flushPromises()

  return view
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
    // An overlay that stays mounted keeps measuring the page, and would find the things that a later test puts on it.
    mounted.splice(0).forEach((view) => view.unmount())
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

  it('points the Start at the tab of the tournament while the driver is not there, and at the button when the page has it', async () => {
    const start = aStep({ key: 'start', number: 2, target: 'start' })
    putOnPage('race', { top: 700, left: 190, width: 60, height: 56 })
    const first = await show(aTutorial(start))

    // The button is not on this screen, so the tab that leads to it is ringed.
    expect(first.wrapper.get('[data-test="tutorial-ring"]').attributes('style')).toContain('top: 694px')

    putOnPage('start', { top: 400, left: 20, width: 340, height: 120 })
    const second = await show(aTutorial(start))

    // On the tournament screen the button is the one that is ringed.
    expect(second.wrapper.get('[data-test="tutorial-ring"]').attributes('style')).toContain('top: 394px')
  })

  it.each([
    ['mount', 'workshop', 7],
    ['crate', 'shop', 8],
    ['travel', 'map', 10],
  ] as const)('points the step %s at the thing itself when the screen has it, and at the tab %s that leads to the screen when it does not', async (target, tab, number) => {
    const step = aStep({ key: target, number, target })
    putOnPage(tab, { top: 700, left: 190, width: 60, height: 56 })
    const first = await show(aTutorial(step))

    // The thing is on another screen: the tab that leads to it is ringed.
    expect(first.wrapper.get('[data-test="tutorial-ring"]').attributes('style')).toContain('top: 694px')

    putOnPage(target, { top: 400, left: 20, width: 340, height: 120 })
    const second = await show(aTutorial(step))

    // On its own screen the thing is the one that is ringed.
    expect(second.wrapper.get('[data-test="tutorial-ring"]').attributes('style')).toContain('top: 394px')
  })

  it('points the old steps at the tab of the garage when the driver is on another screen, where the thing is not', async () => {
    putOnPage('garage', { top: 700, left: 80, width: 60, height: 56 })
    const { wrapper } = await show(aTutorial(aStep({ key: 'refuel', target: 'refuel' })))

    expect(wrapper.get('[data-test="tutorial-ring"]').attributes('style')).toContain('top: 694px')
  })

  it('rings nothing for the step that has no screen of its own, the one that explains the locked parts, and still takes the phone out', async () => {
    putOnPage('workshop')
    const { wrapper } = await show(aTutorial(aStep({ key: 'locked', number: 9, target: null })))

    expect(wrapper.find('[data-test="tutorial-ring"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(true)
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

  it('points the steps of the first day and week at the tab that leads to the thing, and at the thing when the page has it', async () => {
    const wanted: [string, string][] = [
      ['merge', 'workshop'],
      ['exchange', 'shop'],
      ['service', 'workshop'],
      ['upgrade', 'workshop'],
      ['equipment', 'workshop'],
      ['away', 'garage'],
      ['profile', 'garage'],
      ['garages', 'map'],
    ]

    for (const [target, tab] of wanted) {
      const nav = putOnPage(tab, { top: 700, left: 10, width: 60, height: 50 })
      const { wrapper } = await show(aTutorial(aStep({ target: target as never, acknowledged: true })))

      // The tab of the navigation, while the page has not the thing.
      expect(wrapper.get('[data-test="tutorial-ring"]').attributes('style'), target).toContain('top: 694px')
      nav.remove()

      const thing = putOnPage(target, { top: 120, left: 20, width: 300, height: 40 })
      await new Promise((resolve) => setTimeout(resolve, 300))
      await flushPromises()
      expect(wrapper.get('[data-test="tutorial-ring"]').attributes('style'), target).toContain('top: 114px')
      thing.remove()
      wrapper.unmount()
      mounted.pop()
    }
  })

  it('has a second button for a step that can be left for later, which leaves it', async () => {
    const calls = serveApi(fetchMock, {
      'POST /api/v1/tutorial/decline': () => respond(200, aTutorial(null, { done: 19 })),
    })
    const { wrapper } = await show(aTutorial(aStep({ optional: true, decline: 'Not now', target: 'profile' })))

    expect(wrapper.get('[data-test="tutorial-decline"]').text()).toBe('Not now')
    await wrapper.get('[data-test="tutorial-decline"]').trigger('click')
    await flushPromises()

    expect(calls.map((call) => `${call.method} ${call.url}`)).toEqual(['POST /api/v1/tutorial/decline'])
    expect(wrapper.find('[data-test="tutorial-phone"]').exists()).toBe(false)
  })

  it('has no second button for a step that cannot be left', async () => {
    const { wrapper } = await show()

    expect(wrapper.find('[data-test="tutorial-decline"]').exists()).toBe(false)
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
