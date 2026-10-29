import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'

import { storeToken } from '@/auth/token'
import { useRunStore } from '@/stores/run'
import { useTutorialStore } from '@/stores/tutorial'
import { respond, serveApi } from '@/test/fetchServer'
import { aStep, aTutorial } from '@/test/tutorialFixtures'

import { TUTORIAL_POLL_MS, useTutorialSync } from './useTutorialSync'

const fetchMock = vi.fn()

/** A screen that keeps the tutorial up to date, and nothing else. */
const Screen = defineComponent({
  setup() {
    useTutorialSync()

    return () => null
  },
})

function show(answer = aTutorial(aStep({ acknowledged: true }))) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const calls = serveApi(fetchMock, { 'GET /api/v1/tutorial': () => respond(200, answer) })
  const store = useTutorialStore()
  store.tutorial = answer.data

  return { wrapper: mount(Screen, { global: { plugins: [pinia] } }), calls, store }
}

const asked = (calls: { url: string }[]): number => calls.filter((call) => call.url.endsWith('/v1/tutorial')).length

describe('useTutorialSync', () => {
  beforeEach(() => {
    localStorage.clear()
    storeToken('device-token')
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] })
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('asks where the tutorial is every few seconds while a step is on, so that a step that was done is noticed without going to another tab', async () => {
    const { wrapper, calls } = show()

    expect(asked(calls)).toBe(0)

    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS)
    expect(asked(calls)).toBe(1)

    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS)
    expect(asked(calls)).toBe(2)

    wrapper.unmount()
  })

  it('takes the next step from the answer: the goal of the step that was done is replaced', async () => {
    const next = aTutorial(aStep({ key: 'next-step', number: 18, acknowledged: true }))
    const { wrapper, store } = show()
    serveApi(fetchMock, { 'GET /api/v1/tutorial': () => respond(200, next) })

    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS)
    await flushPromises()

    expect(store.current?.key).toBe('next-step')

    wrapper.unmount()
  })

  it('asks no more when no step is on (the tutorial is over or was skipped), or when it is not known yet', async () => {
    const over = show(aTutorial(null, { status: 'finished', goal: null }))
    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS * 3)
    expect(asked(over.calls)).toBe(0)
    over.wrapper.unmount()

    const skipped = show(aTutorial(aStep(), { status: 'skipped' }))
    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS * 3)
    expect(asked(skipped.calls)).toBe(0)
    skipped.wrapper.unmount()

    const pinia = createPinia()
    setActivePinia(pinia)
    const calls = serveApi(fetchMock, {})
    const unknown = mount(Screen, { global: { plugins: [pinia] } })
    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS * 3)
    expect(asked(calls)).toBe(0)
    unknown.unmount()
  })

  it('does not ask while the page is behind another one, and asks at once when it comes back', async () => {
    const { wrapper, calls } = show()

    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true })
    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS * 2)
    expect(asked(calls)).toBe(0)

    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false })
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()
    expect(asked(calls)).toBe(1)

    wrapper.unmount()
  })

  it('asks when the page gets the focus back, and when the run moves', async () => {
    const { wrapper, calls } = show()

    window.dispatchEvent(new Event('focus'))
    await flushPromises()
    expect(asked(calls)).toBe(1)

    // A run that has moved (a refuel, a lap that was paid) may have done what the step asked.
    await vi.advanceTimersByTimeAsync(3_000)
    const run = useRunStore()
    run.run = { state_version: 1 } as never
    await flushPromises()
    run.run = { state_version: 2 } as never
    await flushPromises()
    expect(asked(calls)).toBe(2)

    wrapper.unmount()
  })

  it('does not ask twice in a row: a question is a few seconds after the last one at the earliest, and one that is on its way is not asked again', async () => {
    const { wrapper, calls } = show()

    window.dispatchEvent(new Event('focus'))
    window.dispatchEvent(new Event('focus'))
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()

    expect(asked(calls)).toBe(1)

    wrapper.unmount()
  })

  it('stops asking when the screen is left', async () => {
    const { wrapper, calls } = show()

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS * 3)
    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    expect(asked(calls)).toBe(0)
  })

  it('goes on when a question fails: the next one is asked', async () => {
    const { wrapper, calls } = show()
    let first = true
    serveApi(fetchMock, { 'GET /api/v1/tutorial': () => (first ? ((first = false), respond(500, { message: 'Server error' })) : respond(200, aTutorial(aStep({ acknowledged: true })))) })

    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS)
    await vi.advanceTimersByTimeAsync(TUTORIAL_POLL_MS)

    expect(calls.length).toBe(0)
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/v1/tutorial')).length).toBe(2)

    wrapper.unmount()
  })
})
