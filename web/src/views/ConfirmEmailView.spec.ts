import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Me } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { i18n, setLocale } from '@/i18n'
import { useSessionStore } from '@/stores/session'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import ConfirmEmailView from './ConfirmEmailView.vue'

const claimed: Me = {
  id: 1,
  name: 'Maciek_Turbo',
  locale: 'en',
  is_guest: false,
  has_recovery_code: false,
  identities: [{ provider: 'email', email: 't***@example.com' }],
}

async function open(query = '?token=secret-from-the-mail') {
  const mounted = await mountView(ConfirmEmailView, `/account/confirm${query}`)
  await flushPromises()

  return mounted
}

describe('ConfirmEmailView', () => {
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

  it('uses the link and tells the driver the garage is saved', async () => {
    const calls = serveApi(fetchMock, { 'POST /api/v1/account/email/confirm': () => respond(200, { purpose: 'claim', data: claimed }) })

    const { wrapper } = await open()

    expect(calls[0]?.body).toEqual({ token: 'secret-from-the-mail' })
    expect(wrapper.get('[data-test="title"]').text()).toBe('Your garage is saved')
    expect(wrapper.get('[data-test="body"]').text()).toContain('sign in on any device')
  })

  it('leaves no secret in the address', async () => {
    serveApi(fetchMock, { 'POST /api/v1/account/email/confirm': () => respond(200, { purpose: 'claim', data: claimed }) })

    const { router } = await open()

    expect(router.currentRoute.value.fullPath).toBe('/account/confirm')
  })

  it('signs nobody in when it secures an account, and sends a driver back to the garage', async () => {
    storeToken('device-token')
    serveApi(fetchMock, {
      'POST /api/v1/account/email/confirm': () => respond(200, { purpose: 'claim', data: claimed }),
      'GET /api/v1/me': () => respond(200, { data: claimed }),
    })

    const { wrapper } = await open()

    expect(readToken()).toBe('device-token')
    expect(useSessionStore().me?.is_guest).toBe(false)
    expect(wrapper.get('[data-test="next"]').text()).toBe('Go to the garage')
    expect(wrapper.get('[data-test="next"]').attributes('href')).toBe('/garage')
  })

  it('offers the start to somebody who opened the link in a browser where nobody plays', async () => {
    serveApi(fetchMock, { 'POST /api/v1/account/email/confirm': () => respond(200, { purpose: 'claim', data: claimed }) })

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="next"]').text()).toBe('Back to the start')
    expect(wrapper.get('[data-test="next"]').attributes('href')).toBe('/')
  })

  it('signs in on this device when the link is for signing in, and opens the garage', async () => {
    serveApi(fetchMock, {
      'POST /api/v1/account/email/confirm': () => respond(200, { purpose: 'login', data: claimed, token: 'new-device-token' }),
    })

    const { router } = await open()

    expect(readToken()).toBe('new-device-token')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('garage'))
  })

  it('says the link does not work when it was used, expired or is not known, and offers to ask for a new one', async () => {
    serveApi(fetchMock, {
      'POST /api/v1/account/email/confirm': () =>
        respond(422, { message: 'Invalid.', errors: { token: ['This link is not valid or has expired.'] } }),
    })

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="title"]').text()).toBe('This link does not work')
    expect(wrapper.get('[data-test="body"]').text()).toContain('already used')
    expect(wrapper.get('[data-test="next"]').text()).toBe('Ask for a new link')
    expect(wrapper.get('[data-test="next"]').attributes('href')).toBe('/recover')
  })

  it('sends a signed-in driver whose link failed back to the garage, where a new one can be asked for', async () => {
    storeToken('device-token')
    serveApi(fetchMock, { 'POST /api/v1/account/email/confirm': () => respond(422, { message: 'Invalid.' }) })

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="next"]').attributes('href')).toBe('/garage')
    expect(wrapper.find('[data-test="home"]').exists()).toBe(false)
  })

  it('says why when the address belongs to another garage', async () => {
    serveApi(fetchMock, {
      'POST /api/v1/account/email/confirm': () =>
        respond(409, { message: 'This sign-in already belongs to another garage.', code: 'identity_taken' }),
    })

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="body"]').text()).toBe('This sign-in already belongs to another garage.')
  })

  it('asks to try later when there were too many tries', async () => {
    serveApi(fetchMock, { 'POST /api/v1/account/email/confirm': () => respond(429, { message: 'Too many requests.' }) })

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="body"]').text()).toBe('Too many attempts. Try again later.')
  })

  it('does not ask the server when the address has no link in it', async () => {
    const calls = serveApi(fetchMock, {})

    const { wrapper } = await open('')

    expect(calls).toHaveLength(0)
    expect(wrapper.get('[data-test="title"]').text()).toBe('This link does not work')
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    serveApi(fetchMock, { 'POST /api/v1/account/email/confirm': () => respond(200, { purpose: 'claim', data: claimed }) })

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="title"]').text()).toBe('Twój garaż jest zapisany')

    await setLocale('en')
  })
})
