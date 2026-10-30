import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import { storeToken } from '@/auth/token'
import { respond, serveApi } from '@/test/fetchServer'
import { missionsView } from '@/test/missionsFixtures'

import { MISSIONS_POLL_MS, useMissionsSync } from './useMissionsSync'

const fetchMock = vi.fn()

const Host = defineComponent({
  setup() {
    useMissionsSync()

    return () => h('p')
  },
})

const asked = (calls: { url: string; method: string }[]) => calls.filter((call) => call.method === 'GET' && call.url === '/api/v1/missions').length

describe('useMissionsSync', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    storeToken('device-token')
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('asks for the missions when the screen opens, and again every minute while the page is in front', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(200, { data: missionsView() }) })

    const wrapper = mount(Host)
    await flushPromises()
    expect(asked(calls)).toBe(1)

    await vi.advanceTimersByTimeAsync(MISSIONS_POLL_MS)
    expect(asked(calls)).toBe(2)

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(MISSIONS_POLL_MS * 3)
    expect(asked(calls)).toBe(2)
  })

  it('does not ask again once the game has said it has no missions', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(200, { data: { available: false, now_ms: 1 } }) })

    mount(Host)
    await flushPromises()
    await vi.advanceTimersByTimeAsync(MISSIONS_POLL_MS * 3)

    expect(asked(calls)).toBe(1)
  })

  it('keeps asking when the answer is that the driver is not far enough yet (the tutorial), because that changes', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(200, { data: missionsView({ locked: 'tutorial', main_locked: 'tutorial', claimable: 0 }) }) })

    mount(Host)
    await flushPromises()
    await vi.advanceTimersByTimeAsync(MISSIONS_POLL_MS)

    expect(asked(calls)).toBe(2)
  })

  it('survives a question that fails and asks again at the next time', async () => {
    let fail = true
    const calls = serveApi(fetchMock, { 'GET /api/v1/missions': () => (fail ? respond(500, { message: 'x' }) : respond(200, { data: missionsView() })) })

    mount(Host)
    await flushPromises()
    fail = false
    await vi.advanceTimersByTimeAsync(MISSIONS_POLL_MS)

    expect(asked(calls)).toBe(2)
  })
})
