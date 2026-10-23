import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/client'
import type { Me } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { readPending, storePending } from '@/features/account/oauth'
import { saveAsFile } from '@/features/account/download'
import { goTo } from '@/features/account/redirect'
import { currentLocale, setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'

import { MissingVerifierError, useAccountStore } from './account'
import { useSessionStore } from './session'

vi.mock('@/features/account/redirect', () => ({ goTo: vi.fn() }))
vi.mock('@/features/account/download', async (original) => ({ ...(await original<typeof import('@/features/account/download')>()), saveAsFile: vi.fn() }))

const guest: Me = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: false, identities: [] }
const claimed: Me = { ...guest, is_guest: false, identities: [{ provider: 'google', email: 't***@example.com' }] }

describe('account store', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.mocked(goTo).mockReset()
    vi.mocked(saveAsFile).mockReset()
    vi.stubGlobal('fetch', fetchMock)
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('the providers that are on', () => {
    it('offers none until they are loaded', () => {
      expect(useAccountStore().providers).toEqual([])
      expect(useAccountStore().providersLoaded).toBe(false)
    })

    it('loads the ones the server has keys for', async () => {
      const calls = serveApi(fetchMock, { 'GET /api/v1/auth/providers': () => respond(200, { data: ['google', 'apple'] }) })
      const account = useAccountStore()

      await account.loadProviders()

      expect(account.providers).toEqual(['google', 'apple'])
      expect(account.providersLoaded).toBe(true)
      expect(calls).toHaveLength(1)
    })

    it('offers none, and does not fail, when the server cannot be asked', async () => {
      fetchMock.mockRejectedValue(new TypeError('offline'))
      const account = useAccountStore()

      await expect(account.loadProviders()).resolves.toBeUndefined()

      expect(account.providers).toEqual([])
      // It was asked: the screen does not keep asking.
      expect(account.providersLoaded).toBe(true)
    })
  })

  describe('my data', () => {
    const copy = { exported_at: '2026-10-23T10:00:00+00:00', account: { id: 1, name: 'Maciek_Turbo' }, game: { runs: [] } }

    it('is taken from the server and handed to the browser as a file named after the driver', async () => {
      storeToken('device-token')
      vi.useFakeTimers({ toFake: ['Date'] })
      vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
      const calls = serveApi(fetchMock, { 'GET /api/v1/account/export': () => respond(200, copy) })
      useSessionStore().me = guest

      await useAccountStore().downloadData()
      vi.useRealTimers()

      expect(calls.map((call) => `${call.method} ${call.url}`)).toEqual(['GET /api/v1/account/export'])
      expect(saveAsFile).toHaveBeenCalledTimes(1)
      const [filename, text] = vi.mocked(saveAsFile).mock.calls[0] as [string, string]
      expect(filename).toBe('ride-idle-data-maciek_turbo-2026-10-23.json')
      expect(JSON.parse(text)).toEqual(copy)
      // Written so that a person can read it.
      expect(text).toContain('\n  "account": {')
    })

    it('has a name for the file even before the profile is known', async () => {
      storeToken('device-token')
      serveApi(fetchMock, { 'GET /api/v1/account/export': () => respond(200, copy) })

      await useAccountStore().downloadData()

      expect(vi.mocked(saveAsFile).mock.calls[0]?.[0]).toMatch(/^ride-idle-data-driver-\d{4}-\d{2}-\d{2}\.json$/)
    })

    it('saves no file when the server refuses, and passes the refusal on', async () => {
      storeToken('device-token')
      serveApi(fetchMock, { 'GET /api/v1/account/export': () => respond(429, { message: 'Too many attempts.' }) })

      await expect(useAccountStore().downloadData()).rejects.toMatchObject({ status: 429 })

      expect(saveAsFile).not.toHaveBeenCalled()
    })
  })

  describe('deleting my account', () => {
    it('asks the server with the name the driver wrote, and the device forgets the account', async () => {
      storeToken('device-token')
      const calls = serveApi(fetchMock, { 'DELETE /api/v1/account': () => respond(200, { deleted: true }) })
      const session = useSessionStore()
      session.me = guest

      await useAccountStore().deleteAccount('Maciek_Turbo')

      expect(calls[0]?.body).toEqual({ confirm_name: 'Maciek_Turbo' })
      expect(session.isAuthenticated).toBe(false)
      expect(session.me).toBeNull()
      expect(readToken()).toBeNull()
      expect(session.accountDeleted).toBe(true)
    })

    it('keeps the session when the server says it is not the name, and passes the refusal on', async () => {
      storeToken('device-token')
      serveApi(fetchMock, {
        'DELETE /api/v1/account': () => respond(422, { message: 'x', errors: { confirm_name: ['Type your driver name exactly as it is written to delete the account.'] } }),
      })
      const session = useSessionStore()
      session.me = guest

      await expect(useAccountStore().deleteAccount('nope')).rejects.toMatchObject({ status: 422 })

      expect(session.isAuthenticated).toBe(true)
      expect(session.me).toEqual(guest)
      expect(readToken()).toBe('device-token')
      expect(session.accountDeleted).toBe(false)
    })

    it('keeps the session when the server cannot be reached: nothing is known to be deleted', async () => {
      storeToken('device-token')
      fetchMock.mockRejectedValue(new TypeError('offline'))
      const session = useSessionStore()

      await expect(useAccountStore().deleteAccount('Maciek_Turbo')).rejects.toBeInstanceOf(TypeError)

      expect(session.isAuthenticated).toBe(true)
      expect(session.accountDeleted).toBe(false)
    })
  })

  describe('what the legal texts need', () => {
    const info = {
      min_age: 16,
      version: '2026-10-23',
      guest_expiry_days: 90,
      operator: { name: null, address: null, email: null },
    }

    it('is not known until it is asked for', () => {
      expect(useAccountStore().legal).toBeNull()
    })

    it('is asked for once, and kept', async () => {
      const calls = serveApi(fetchMock, { 'GET /api/v1/meta/legal': () => respond(200, { data: info }) })
      const account = useAccountStore()

      await account.loadLegal()
      await account.loadLegal()

      expect(account.legal).toEqual(info)
      expect(calls).toHaveLength(1)
    })

    it('passes a failure on, and asks again next time', async () => {
      fetchMock.mockRejectedValueOnce(new TypeError('offline'))
      const account = useAccountStore()

      await expect(account.loadLegal()).rejects.toBeInstanceOf(TypeError)
      expect(account.legal).toBeNull()

      serveApi(fetchMock, { 'GET /api/v1/meta/legal': () => respond(200, { data: info }) })
      await account.loadLegal()
      expect(account.legal).toEqual(info)
    })
  })

  describe('the links by e-mail', () => {
    it('asks for a link that secures the account, saying the terms were accepted and the age confirmed', async () => {
      storeToken('device-token')
      const calls = serveApi(fetchMock, {
        'POST /api/v1/account/email': () => respond(202, { message: 'We sent a link to t@example.com.', expires_in: 1800 }),
      })

      const message = await useAccountStore().requestEmailLink('t@example.com')

      expect(message).toBe('We sent a link to t@example.com.')
      expect(calls[0]?.body).toEqual({ email: 't@example.com', accept_terms: true, confirm_age: true })
    })

    it('asks for a link that adds an e-mail to an account that accepted the terms before, with the address only', async () => {
      storeToken('device-token')
      const calls = serveApi(fetchMock, {
        'POST /api/v1/account/email': () => respond(202, { message: 'We sent a link to t@example.com.', expires_in: 1800 }),
      })

      await useAccountStore().requestEmailLink('t@example.com', false)

      expect(calls[0]?.body).toEqual({ email: 't@example.com' })
    })

    it('asks for a link that signs in, without any consent', async () => {
      const calls = serveApi(fetchMock, {
        'POST /api/v1/account/login-link': () =>
          respond(202, { message: 'If this e-mail belongs to a garage, a link is on its way.', expires_in: 1800 }),
      })

      const message = await useAccountStore().requestLoginLink('t@example.com')

      expect(message).toContain('a link is on its way')
      expect(calls[0]?.body).toEqual({ email: 't@example.com' })
    })

    it('passes on a refusal, such as too many requests', async () => {
      serveApi(fetchMock, { 'POST /api/v1/account/login-link': () => respond(429, { message: 'Too many attempts.' }) })

      await expect(useAccountStore().requestLoginLink('t@example.com')).rejects.toMatchObject({ status: 429 })
    })
  })

  describe('starting a sign-in with a provider', () => {
    it('links with the account of the driver: terms in the request, verifier kept, browser sent to the provider', async () => {
      storeToken('device-token')
      const calls = serveApi(fetchMock, {
        'POST /api/v1/account/identities/google/start': () =>
          respond(200, { url: 'https://accounts.google.test/auth?state=s', verifier: 'v-123' }),
      })

      await useAccountStore().startProvider('google', true)

      expect(calls[0]?.body).toEqual({ accept_terms: true, confirm_age: true })
      expect(readPending()).toMatchObject({ provider: 'google', verifier: 'v-123' })
      expect(goTo).toHaveBeenCalledWith('https://accounts.google.test/auth?state=s')
    })

    it('adds a provider to an account that accepted the terms before, with no terms in the request', async () => {
      storeToken('device-token')
      const calls = serveApi(fetchMock, {
        'POST /api/v1/account/identities/apple/start': () => respond(200, { url: 'https://appleid.test/auth?state=s', verifier: 'v-789' }),
      })

      await useAccountStore().startProvider('apple', true, false)

      expect(calls[0]?.body).toEqual({})
      expect(readPending()).toMatchObject({ provider: 'apple', verifier: 'v-789' })
      expect(goTo).toHaveBeenCalledWith('https://appleid.test/auth?state=s')
    })

    it('signs in with no account on the device, and asks for no consent', async () => {
      const calls = serveApi(fetchMock, {
        'POST /api/v1/auth/facebook/start': () => respond(200, { url: 'https://facebook.test/dialog?state=s', verifier: 'v-456' }),
      })

      await useAccountStore().startProvider('facebook', false)

      expect(calls[0]?.body).toBeNull()
      expect(readPending()).toMatchObject({ provider: 'facebook', verifier: 'v-456' })
      expect(goTo).toHaveBeenCalledWith('https://facebook.test/dialog?state=s')
    })

    it('goes nowhere and keeps nothing when the server refuses', async () => {
      serveApi(fetchMock, {
        'POST /api/v1/auth/apple/start': () => respond(404, { message: 'Not available.', code: 'provider_unavailable' }),
      })

      await expect(useAccountStore().startProvider('apple', false)).rejects.toBeInstanceOf(ApiError)

      expect(readPending()).toBeNull()
      expect(goTo).not.toHaveBeenCalled()
    })
  })

  describe('finishing a sign-in with a provider', () => {
    it('shows the verifier kept since the start, and links: the profile changes and nobody is signed in again', async () => {
      storeToken('device-token')
      storePending('google', 'v-123')
      const calls = serveApi(fetchMock, { 'POST /api/v1/auth/complete': () => respond(200, { purpose: 'link', data: claimed }) })
      const session = useSessionStore()
      session.me = guest

      const purpose = await useAccountStore().finishProvider('ticket-789')

      expect(purpose).toBe('link')
      expect(calls[0]?.body).toEqual({ ticket: 'ticket-789', verifier: 'v-123' })
      expect(session.me).toEqual(claimed)
      expect(readToken()).toBe('device-token')
      expect(readPending()).toBeNull()
    })

    it('signs in: a token for this device, in the language of the account', async () => {
      storePending('google', 'v-123')
      serveApi(fetchMock, {
        'POST /api/v1/auth/complete': () =>
          respond(200, { purpose: 'login', data: { ...claimed, locale: 'de' }, token: 'new-device-token' }),
      })
      const session = useSessionStore()

      const purpose = await useAccountStore().finishProvider('ticket-789')

      expect(purpose).toBe('login')
      expect(session.isAuthenticated).toBe(true)
      expect(readToken()).toBe('new-device-token')
      expect(currentLocale()).toBe('de')

      await setLocale('en')
    })

    it('cannot finish what this browser did not start', async () => {
      const calls = serveApi(fetchMock, { 'POST /api/v1/auth/complete': () => respond(200, { purpose: 'link', data: claimed }) })

      await expect(useAccountStore().finishProvider('ticket-789')).rejects.toBeInstanceOf(MissingVerifierError)

      expect(calls).toHaveLength(0)
    })

    it('drops the verifier once the server has answered, even with a refusal, because a sign-in is finished once', async () => {
      storePending('google', 'v-123')
      serveApi(fetchMock, { 'POST /api/v1/auth/complete': () => respond(404, { message: 'No garage.', code: 'no_account' }) })

      await expect(useAccountStore().finishProvider('ticket-789')).rejects.toMatchObject({ status: 404, code: 'no_account' })

      expect(readPending()).toBeNull()
    })

    it('keeps the verifier when the server could not be reached, so the same address can be tried again', async () => {
      storePending('google', 'v-123')
      fetchMock.mockRejectedValue(new TypeError('offline'))

      await expect(useAccountStore().finishProvider('ticket-789')).rejects.toBeInstanceOf(TypeError)

      expect(readPending()).toMatchObject({ verifier: 'v-123' })
    })
  })

  describe('using the link of an e-mail', () => {
    it('signs in on this device when the link is for signing in', async () => {
      const calls = serveApi(fetchMock, {
        'POST /api/v1/account/email/confirm': () => respond(200, { purpose: 'login', data: claimed, token: 'new-device-token' }),
      })
      const session = useSessionStore()

      const purpose = await useAccountStore().confirmEmail('secret-from-the-mail')

      expect(purpose).toBe('login')
      expect(calls[0]?.body).toEqual({ token: 'secret-from-the-mail' })
      expect(session.isAuthenticated).toBe(true)
      expect(readToken()).toBe('new-device-token')
    })

    it('signs nobody in when the link secures an account, only asks this device for its profile again', async () => {
      storeToken('device-token')
      const calls = serveApi(fetchMock, {
        'POST /api/v1/account/email/confirm': () => respond(200, { purpose: 'claim', data: claimed }),
        'GET /api/v1/me': () => respond(200, { data: claimed }),
      })
      const session = useSessionStore()
      session.me = guest

      const purpose = await useAccountStore().confirmEmail('secret-from-the-mail')

      expect(purpose).toBe('claim')
      expect(readToken()).toBe('device-token')
      expect(calls.map((call) => `${call.method} ${call.url}`)).toEqual(['POST /api/v1/account/email/confirm', 'GET /api/v1/me'])
      expect(session.me).toEqual(claimed)
    })

    it('is fine in a browser where nobody plays: nothing to refresh', async () => {
      const calls = serveApi(fetchMock, { 'POST /api/v1/account/email/confirm': () => respond(200, { purpose: 'claim', data: claimed }) })

      await expect(useAccountStore().confirmEmail('secret-from-the-mail')).resolves.toBe('claim')

      expect(calls).toHaveLength(1)
      expect(useSessionStore().isAuthenticated).toBe(false)
    })

    it('passes on a link that is not valid', async () => {
      serveApi(fetchMock, {
        'POST /api/v1/account/email/confirm': () =>
          respond(422, { message: 'Invalid.', errors: { token: ['This link is not valid or has expired.'] } }),
      })

      await expect(useAccountStore().confirmEmail('old')).rejects.toMatchObject({ status: 422 })
    })
  })
})
