import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Me } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { readPending, storePending } from '@/features/account/oauth'
import { i18n, setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import AuthCallbackView from './AuthCallbackView.vue'

const claimed: Me = {
  id: 1,
  name: 'Maciek_Turbo',
  locale: 'en',
  is_guest: false,
  has_recovery_code: false,
  identities: [{ provider: 'google', email: 't***@example.com' }],
}

const ready = '#result=ready&provider=google&ticket=ticket-789'

async function open(fragment = ready) {
  const mounted = await mountView(AuthCallbackView, `/auth/callback${fragment}`)
  await flushPromises()

  return mounted
}

describe('AuthCallbackView', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    i18n.global.locale.value = 'en'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('when the browser comes back ready to link', () => {
    it('shows the server the verifier this browser kept, and says the provider is linked', async () => {
      storeToken('device-token')
      storePending('google', 'v-123')
      const calls = serveApi(fetchMock, { 'POST /api/v1/auth/complete': () => respond(200, { purpose: 'link', data: claimed }) })

      const { wrapper } = await open()

      expect(calls[0]?.body).toEqual({ ticket: 'ticket-789', verifier: 'v-123' })
      expect(wrapper.get('[data-test="title"]').text()).toBe('Google is linked')
      expect(wrapper.get('[data-test="body"]').text()).toContain('sign in with Google')
      expect(wrapper.get('[data-test="next"]').attributes('href')).toBe('/garage')
      expect(readPending()).toBeNull()
    })

    it('leaves no ticket in the address', async () => {
      storeToken('device-token')
      storePending('google', 'v-123')
      serveApi(fetchMock, { 'POST /api/v1/auth/complete': () => respond(200, { purpose: 'link', data: claimed }) })

      const { router } = await open()

      expect(router.currentRoute.value.fullPath).toBe('/auth/callback')
    })

    it('names the provider that was linked', async () => {
      storeToken('device-token')
      storePending('apple', 'v-123')
      serveApi(fetchMock, { 'POST /api/v1/auth/complete': () => respond(200, { purpose: 'link', data: claimed }) })

      const { wrapper } = await open('#result=ready&provider=apple&ticket=ticket-789')

      expect(wrapper.get('[data-test="title"]').text()).toBe('Apple is linked')
    })
  })

  describe('when the browser comes back ready to sign in', () => {
    it('signs in on this device and opens the garage', async () => {
      storePending('google', 'v-123')
      serveApi(fetchMock, {
        'POST /api/v1/auth/complete': () => respond(200, { purpose: 'login', data: claimed, token: 'new-device-token' }),
      })

      const { router } = await open()

      expect(readToken()).toBe('new-device-token')
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('garage'))
    })

    it('says there is no garage for this provider, in the words of the server, and sends a visitor back to signing in', async () => {
      storePending('google', 'v-123')
      serveApi(fetchMock, {
        'POST /api/v1/auth/complete': () =>
          respond(404, { message: 'No garage is linked to this Google account.', code: 'no_account' }),
      })

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="title"]').text()).toBe('The sign-in did not work')
      expect(wrapper.get('[data-test="body"]').text()).toBe('No garage is linked to this Google account.')
      expect(wrapper.get('[data-test="next"]').attributes('href')).toBe('/recover')
      expect(readToken()).toBeNull()
    })
  })

  describe('when the sign-in cannot be finished', () => {
    it('does not ask the server at all when this browser did not start it', async () => {
      const calls = serveApi(fetchMock, {})

      const { wrapper } = await open()

      expect(calls).toHaveLength(0)
      expect(wrapper.get('[data-test="body"]').text()).toContain('not started in this browser')
    })

    it('says the sign-in has expired when the server does not take the ticket', async () => {
      storePending('google', 'v-123')
      serveApi(fetchMock, { 'POST /api/v1/auth/complete': () => respond(422, { message: 'Invalid.', errors: { ticket: ['Invalid.'] } }) })

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="body"]').text()).toContain('expired or was already used')
    })

    it('says the session has ended when the driver is not signed in any more', async () => {
      storePending('google', 'v-123')
      serveApi(fetchMock, { 'POST /api/v1/auth/complete': () => respond(401, { message: 'Unauthenticated.' }) })

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="body"]').text()).toContain('session has ended')
    })

    it('passes on what the server says about a provider that belongs to another garage', async () => {
      storeToken('device-token')
      storePending('google', 'v-123')
      serveApi(fetchMock, {
        'POST /api/v1/auth/complete': () =>
          respond(409, { message: 'This sign-in already belongs to another garage.', code: 'identity_taken' }),
      })

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="body"]').text()).toBe('This sign-in already belongs to another garage.')
      expect(wrapper.get('[data-test="next"]').attributes('href')).toBe('/garage')
    })

    it('asks to try later when there were too many tries', async () => {
      storePending('google', 'v-123')
      serveApi(fetchMock, { 'POST /api/v1/auth/complete': () => respond(429, { message: 'Too many requests.' }) })

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="body"]').text()).toBe('Too many attempts. Try again later.')
    })

    it('keeps the verifier when the server cannot be reached', async () => {
      storePending('google', 'v-123')
      fetchMock.mockRejectedValue(new TypeError('offline'))

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="body"]').text()).toBe('Something went wrong. Try again.')
      expect(readPending()).toMatchObject({ verifier: 'v-123' })
    })
  })

  describe('when the provider did not give the person back', () => {
    it('says nothing was changed when the person said no there', async () => {
      const calls = serveApi(fetchMock, {})

      const { wrapper } = await open('#result=cancelled&provider=facebook')

      expect(calls).toHaveLength(0)
      expect(wrapper.get('[data-test="title"]').text()).toBe('Sign-in cancelled')
      expect(wrapper.get('[data-test="body"]').text()).toBe('You did not allow it at Facebook, so nothing was changed.')
    })

    it('says it did not work when the API reports an error', async () => {
      const calls = serveApi(fetchMock, {})

      const { wrapper } = await open('#result=error&provider=apple')

      expect(calls).toHaveLength(0)
      expect(wrapper.get('[data-test="title"]').text()).toBe('The sign-in did not work')
      expect(wrapper.get('[data-test="body"]').text()).toBe('Something went wrong on the way back from Apple. Try again.')
    })

    it.each([
      ['no fragment at all', ''],
      ['a ticket with no outcome', '#ticket=ticket-789&provider=google'],
      ['an outcome with no ticket', '#result=ready&provider=google'],
      ['a provider that is not one of the three', '#result=error&provider=github'],
    ])('does not ask the server for %s', async (_name, fragment) => {
      storePending('google', 'v-123')
      const calls = serveApi(fetchMock, {})

      const { wrapper } = await open(fragment)

      expect(calls).toHaveLength(0)
      expect(wrapper.get('[data-test="title"]').text()).toBe('The sign-in did not work')
    })
  })

  it('speaks the language of the driver', async () => {
    await setLocale('de')
    const { wrapper } = await open('#result=cancelled&provider=google')

    expect(wrapper.get('[data-test="title"]').text()).toBe('Anmeldung abgebrochen')

    await setLocale('en')
  })
})
