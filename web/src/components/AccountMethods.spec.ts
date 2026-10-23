import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { readPending } from '@/features/account/oauth'
import { goTo } from '@/features/account/redirect'
import { i18n, setLocale } from '@/i18n'
import { respond, serveApi, type Call } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import AccountMethods from './AccountMethods.vue'

vi.mock('@/features/account/redirect', () => ({ goTo: vi.fn() }))

const fetchMock = vi.fn()

/** Mounts the methods with the providers the server has on. `extra` adds the calls the test makes. */
async function show(mode: 'link' | 'login', extra: Record<string, () => Response> = {}, providers: string[] = ['google', 'facebook', 'apple']) {
  const calls = serveApi(fetchMock, { 'GET /api/v1/auth/providers': () => respond(200, { data: providers }), ...extra })
  const mounted = await mountView(AccountMethods, '/', { props: { mode } })
  await flushPromises()

  return { ...mounted, calls }
}

function made(calls: Call[], method: string, url: string): Call[] {
  return calls.filter((call) => call.method === method && call.url === url)
}

describe('AccountMethods', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.mocked(goTo).mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('to link an account', () => {
    it('asks for the terms and the age, and offers the providers the server has on, in its order', async () => {
      const { wrapper } = await show('link')

      expect(wrapper.find('[data-test="terms"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="age"]').exists()).toBe(true)
      expect(wrapper.findAll('[data-test^="provider-"]').map((button) => button.text())).toEqual([
        'Continue with Google',
        'Continue with Facebook',
        'Continue with Apple',
      ])
    })

    it('offers only the e-mail when the server has no provider on', async () => {
      const { wrapper } = await show('link', {}, [])

      expect(wrapper.find('[data-test="providers"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="email"]').exists()).toBe(true)
    })

    it('offers only the e-mail when the providers cannot be loaded', async () => {
      fetchMock.mockRejectedValue(new TypeError('offline'))
      const { wrapper } = await mountView(AccountMethods, '/', { props: { mode: 'link' } })
      await flushPromises()

      expect(wrapper.find('[data-test="providers"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="send"]').exists()).toBe(true)
    })

    it('asks for the providers once, not every time it is shown', async () => {
      const { wrapper, pinia, router, calls } = await show('link')
      wrapper.unmount()

      // Shown again on the same page, as the window is when the driver opens it from the banner.
      const again = mount(AccountMethods, { props: { mode: 'link' }, global: { plugins: [pinia, router, i18n] } })
      await flushPromises()

      expect(made(calls, 'GET', '/api/v1/auth/providers')).toHaveLength(1)
      expect(again.findAll('[data-test^="provider-"]')).toHaveLength(3)
    })

    it.each([
      ['neither is given', false, false],
      ['only the terms are accepted', true, false],
      ['only the age is confirmed', false, true],
    ])('does not start a provider when %s', async (_name, terms, age) => {
      const { wrapper, calls } = await show('link')
      await wrapper.get('[data-test="terms"]').setValue(terms)
      await wrapper.get('[data-test="age"]').setValue(age)

      await wrapper.get('[data-test="provider-google"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('Accept the terms and confirm your age to continue.')
      expect(made(calls, 'POST', '/api/v1/account/identities/google/start')).toHaveLength(0)
      expect(goTo).not.toHaveBeenCalled()
    })

    it('sends the browser to the provider once the terms and the age are given, keeping the verifier', async () => {
      const { wrapper, calls } = await show('link', {
        'POST /api/v1/account/identities/google/start': () => respond(200, { url: 'https://accounts.google.test/auth?state=s', verifier: 'v-123' }),
      })
      await wrapper.get('[data-test="terms"]').setValue(true)
      await wrapper.get('[data-test="age"]').setValue(true)

      await wrapper.get('[data-test="provider-google"]').trigger('click')
      await flushPromises()

      expect(made(calls, 'POST', '/api/v1/account/identities/google/start')[0]?.body).toEqual({ accept_terms: true, confirm_age: true })
      expect(goTo).toHaveBeenCalledWith('https://accounts.google.test/auth?state=s')
      expect(readPending()).toMatchObject({ provider: 'google', verifier: 'v-123' })
      expect(wrapper.get('[data-test="provider-google"]').text()).toBe('Opening Google…')
      expect(wrapper.get('[data-test="provider-facebook"]').attributes('disabled')).toBeDefined()
    })

    it('says why, and lets the driver try again, when the provider cannot be started', async () => {
      const { wrapper } = await show('link', {
        'POST /api/v1/account/identities/apple/start': () => respond(404, { message: 'This way of signing in is not available.', code: 'provider_unavailable' }),
      })
      await wrapper.get('[data-test="terms"]').setValue(true)
      await wrapper.get('[data-test="age"]').setValue(true)

      await wrapper.get('[data-test="provider-apple"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('This way of signing in is not available.')
      expect(wrapper.get('[data-test="provider-apple"]').text()).toBe('Continue with Apple')
      expect(wrapper.get('[data-test="provider-apple"]').attributes('disabled')).toBeUndefined()
    })

    it('sends a link by e-mail, saying the terms were accepted and the age confirmed, and shows what the server says', async () => {
      const { wrapper, calls } = await show('link', {
        'POST /api/v1/account/email': () => respond(202, { message: 'We sent a link to turbo@example.com. It works once, for 30 minutes.', expires_in: 1800 }),
      })
      await wrapper.get('[data-test="terms"]').setValue(true)
      await wrapper.get('[data-test="age"]').setValue(true)
      await wrapper.get('[data-test="email"]').setValue('  Turbo@Example.com ')

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(made(calls, 'POST', '/api/v1/account/email')[0]?.body).toEqual({ email: 'Turbo@Example.com', accept_terms: true, confirm_age: true })
      expect(wrapper.get('[data-test="sent"]').text()).toContain('We sent a link to turbo@example.com.')
      expect(wrapper.find('[data-test="email"]').exists()).toBe(false)
    })

    it('does not send a link before the terms and the age are given', async () => {
      const { wrapper, calls } = await show('link')
      await wrapper.get('[data-test="email"]').setValue('turbo@example.com')

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('Accept the terms and confirm your age to continue.')
      expect(made(calls, 'POST', '/api/v1/account/email')).toHaveLength(0)
    })

    it('brings the form back to ask for another address', async () => {
      const { wrapper } = await show('link', { 'POST /api/v1/account/email': () => respond(202, { message: 'Sent.', expires_in: 1800 }) })
      await wrapper.get('[data-test="terms"]').setValue(true)
      await wrapper.get('[data-test="age"]').setValue(true)
      await wrapper.get('[data-test="email"]').setValue('turbo@example.com')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      await wrapper.get('[data-test="another"]').trigger('click')

      expect(wrapper.find('[data-test="sent"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="email"]').exists()).toBe(true)
    })

    it('says so when the account has an e-mail already', async () => {
      const { wrapper } = await show('link', {
        'POST /api/v1/account/email': () => respond(409, { message: 'This account already has an e-mail.', code: 'email_already_linked' }),
      })
      await wrapper.get('[data-test="terms"]').setValue(true)
      await wrapper.get('[data-test="age"]').setValue(true)
      await wrapper.get('[data-test="email"]').setValue('turbo@example.com')

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('This account already has an e-mail.')
    })
  })

  describe('to sign in', () => {
    it('asks for no terms and no age, because the account exists', async () => {
      const { wrapper } = await show('login')

      expect(wrapper.find('[data-test="terms"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="age"]').exists()).toBe(false)
    })

    it('sends the browser to the provider with nothing but the request', async () => {
      const { wrapper, calls } = await show('login', {
        'POST /api/v1/auth/facebook/start': () => respond(200, { url: 'https://facebook.test/dialog?state=s', verifier: 'v-456' }),
      })

      await wrapper.get('[data-test="provider-facebook"]').trigger('click')
      await flushPromises()

      expect(made(calls, 'POST', '/api/v1/auth/facebook/start')[0]?.body).toBeNull()
      expect(goTo).toHaveBeenCalledWith('https://facebook.test/dialog?state=s')
      expect(readPending()).toMatchObject({ provider: 'facebook', verifier: 'v-456' })
    })

    it('asks for a link that signs in, with the address only', async () => {
      const { wrapper, calls } = await show('login', {
        'POST /api/v1/account/login-link': () => respond(202, { message: 'If this e-mail belongs to a garage, a link is on its way.', expires_in: 1800 }),
      })
      await wrapper.get('[data-test="email"]').setValue('turbo@example.com')

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(made(calls, 'POST', '/api/v1/account/login-link')[0]?.body).toEqual({ email: 'turbo@example.com' })
      expect(wrapper.get('[data-test="sent"]').text()).toContain('a link is on its way')
    })
  })

  describe('the address', () => {
    it.each([
      ['empty', '', 'Enter your e-mail.'],
      ['blank', '   ', 'Enter your e-mail.'],
      ['not an address', 'turbo', 'This does not look like an e-mail address.'],
    ])('is checked before anything is sent: %s', async (_name, value, message) => {
      const { wrapper, calls } = await show('login')
      await wrapper.get('[data-test="email"]').setValue(value)

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe(message)
      expect(made(calls, 'POST', '/api/v1/account/login-link')).toHaveLength(0)
    })

    it('shows what the server finds wrong with it', async () => {
      const { wrapper } = await show('login', {
        'POST /api/v1/account/login-link': () => respond(422, { message: 'Invalid.', errors: { email: ['The email field must be a valid email address.'] } }),
      })
      await wrapper.get('[data-test="email"]').setValue('turbo@x')

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('The email field must be a valid email address.')
    })

    it('asks to wait when there were too many requests', async () => {
      const { wrapper } = await show('login', {
        'POST /api/v1/account/login-link': () => respond(429, { message: 'Too many attempts. Try again later.' }),
      })
      await wrapper.get('[data-test="email"]').setValue('turbo@example.com')

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('Too many attempts. Try again later.')
    })

    it('says something went wrong when the server cannot be reached', async () => {
      const { wrapper } = await show('login', {})
      fetchMock.mockRejectedValue(new TypeError('offline'))
      await wrapper.get('[data-test="email"]').setValue('turbo@example.com')

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('Something went wrong. Try again.')
      expect(wrapper.get('[data-test="send"]').attributes('disabled')).toBeUndefined()
    })
  })

  it('speaks the language of the driver', async () => {
    await setLocale('fr')
    const { wrapper } = await show('link')

    expect(wrapper.get('[data-test="provider-google"]').text()).toBe('Continuer avec Google')

    await setLocale('en')
  })
})
