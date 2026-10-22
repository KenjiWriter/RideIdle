import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { readToken, storeToken } from '@/auth/token'
import { i18n } from '@/i18n'
import { mountView } from '@/test/mountView'

import GarageView from './GarageView.vue'

const profile = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: false }

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('GarageView', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    i18n.global.locale.value = 'en'
    storeToken('device-token')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('welcomes the driver by name', async () => {
    fetchMock.mockResolvedValue(respond(200, { data: profile }))

    const { wrapper } = await mountView(GarageView, '/garage')
    expect(wrapper.get('[data-test="title"]').text()).toBe('Loading…')

    await flushPromises()

    expect(wrapper.get('[data-test="title"]').text()).toBe('Welcome, Maciek_Turbo')
  })

  it('returns to the landing page when the server no longer knows the token', async () => {
    fetchMock.mockResolvedValue(respond(401, { message: 'Unauthenticated.' }))

    const { router } = await mountView(GarageView, '/garage')
    await flushPromises()

    expect(readToken()).toBeNull()
    // Navigation loads the landing view lazily, so wait for it.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('landing'))
  })

  it('signs out and returns to the landing page', async () => {
    fetchMock.mockResolvedValue(respond(200, { data: profile }))
    const { wrapper, router } = await mountView(GarageView, '/garage')
    await flushPromises()

    await wrapper.get('[data-test="sign-out"]').trigger('click')
    await flushPromises()

    expect(readToken()).toBeNull()
    // Navigation loads the landing view lazily, so wait for it.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('landing'))
  })
})
