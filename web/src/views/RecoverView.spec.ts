import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { readToken } from '@/auth/token'
import { readPending } from '@/features/account/oauth'
import { goTo } from '@/features/account/redirect'
import { setLocale } from '@/i18n'
import { respond, serveApi, type Call } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import RecoverView from './RecoverView.vue'

vi.mock('@/features/account/redirect', () => ({ goTo: vi.fn() }))

const profile = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: true, identities: [] }

const fetchMock = vi.fn()

/** Opens the screen. The server has Google on and answers what `extra` says. */
async function open(extra: Record<string, () => Response | Promise<Response>> = {}, providers: string[] = ['google']) {
  const calls = serveApi(fetchMock, { 'GET /api/v1/auth/providers': () => respond(200, { data: providers }), ...extra })
  const mounted = await mountView(RecoverView, '/recover')
  await flushPromises()

  async function submit(code: string) {
    await mounted.wrapper.get('[data-test="code"]').setValue(code)
    await mounted.wrapper.get('form[aria-labelledby="recover-code-title"]').trigger('submit')
    await flushPromises()
  }

  /** The message under the field of the recovery code. */
  const error = () => mounted.wrapper.get('form[aria-labelledby="recover-code-title"] [data-test="error"]').text()

  return { ...mounted, calls, submit, error }
}

function made(calls: Call[], method: string, url: string): Call[] {
  return calls.filter((call) => call.method === method && call.url === url)
}

describe('RecoverView', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.mocked(goTo).mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('with a recovery code', () => {
    it('asks for a code and sends nothing when the field is empty', async () => {
      const { submit, error, calls } = await open()

      await submit('   ')

      expect(error()).toBe('Enter your recovery code.')
      expect(made(calls, 'POST', '/api/v1/account/recover')).toHaveLength(0)
    })

    it('restores the driver and opens the garage', async () => {
      const { submit, router, calls } = await open({ 'POST /api/v1/account/recover': () => respond(200, { data: profile, token: 'restored-token' }) })

      await submit(' 7k2m-q9xp-4htd ')

      expect(made(calls, 'POST', '/api/v1/account/recover')[0]?.body).toEqual({ code: '7k2m-q9xp-4htd' })
      expect(readToken()).toBe('restored-token')
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('garage'))
    })

    it('shows the message of the server for a wrong code and stays on the page', async () => {
      const { submit, error, router } = await open({
        'POST /api/v1/account/recover': () =>
          respond(422, { message: 'This recovery code is not valid.', errors: { code: ['This recovery code is not valid.'] } }),
      })

      await submit('ABCD-EFGH-JKLM')

      expect(error()).toBe('This recovery code is not valid.')
      expect(router.currentRoute.value.name).toBe('recover')
      expect(readToken()).toBeNull()
    })

    it('shows the lock message of the server after too many failures', async () => {
      const { submit, error } = await open({
        'POST /api/v1/account/recover': () => respond(429, { message: 'Too many failed attempts. Try again later.' }),
      })

      await submit('ABCD-EFGH-JKLM')

      expect(error()).toBe('Too many failed attempts. Try again later.')
    })

    it('clears the message when the driver edits the code', async () => {
      const { submit, wrapper, error } = await open({
        'POST /api/v1/account/recover': () => respond(422, { message: 'bad', errors: { code: ['bad'] } }),
      })
      await submit('ABCD-EFGH-JKLM')

      await wrapper.get('[data-test="code"]').setValue('ABCD-EFGH-JKLN')

      expect(error()).toBe('')
    })

    it('shows a general message when the server cannot be reached', async () => {
      const { submit, error } = await open()
      fetchMock.mockRejectedValue(new TypeError('offline'))

      await submit('ABCD-EFGH-JKLM')

      expect(error()).toBe('Something went wrong. Try again.')
    })
  })

  describe('with an account', () => {
    it('offers the providers the server has on and an e-mail link, before the recovery code', async () => {
      const { wrapper } = await open({}, ['google', 'apple'])
      const account = wrapper.get('[data-test="account"]')

      expect(account.get('h2').text()).toBe('Sign in with your account')
      expect(account.findAll('[data-test^="provider-"]').map((button) => button.text())).toEqual(['Continue with Google', 'Continue with Apple'])
      expect(account.find('[data-test="email"]').exists()).toBe(true)
      expect(wrapper.html().indexOf('data-test="account"')).toBeLessThan(wrapper.html().indexOf('data-test="code"'))
    })

    it('asks for no terms or age: the account exists already', async () => {
      const { wrapper } = await open()

      expect(wrapper.find('[data-test="terms"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="age"]').exists()).toBe(false)
    })

    it('sends the browser to the provider to sign in', async () => {
      const { wrapper, calls } = await open({
        'POST /api/v1/auth/google/start': () => respond(200, { url: 'https://accounts.google.test/auth?state=s', verifier: 'v-123' }),
      })

      await wrapper.get('[data-test="provider-google"]').trigger('click')
      await flushPromises()

      expect(made(calls, 'POST', '/api/v1/auth/google/start')).toHaveLength(1)
      expect(goTo).toHaveBeenCalledWith('https://accounts.google.test/auth?state=s')
      expect(readPending()).toMatchObject({ provider: 'google', verifier: 'v-123' })
    })

    it('asks for a link that signs in, and says it is on its way without saying whether the address has a garage', async () => {
      const { wrapper, calls } = await open({
        'POST /api/v1/account/login-link': () =>
          respond(202, { message: 'If this e-mail belongs to a garage, a link is on its way. It works once, for 30 minutes.', expires_in: 1800 }),
      })
      await wrapper.get('[data-test="email"]').setValue('turbo@example.com')

      await wrapper.get('[data-test="account"] form').trigger('submit')
      await flushPromises()

      expect(made(calls, 'POST', '/api/v1/account/login-link')[0]?.body).toEqual({ email: 'turbo@example.com' })
      expect(wrapper.get('[data-test="sent"]').text()).toContain('If this e-mail belongs to a garage')
    })

    it('still lets a driver in with the code when the providers cannot be loaded', async () => {
      fetchMock.mockRejectedValue(new TypeError('offline'))
      const mounted = await mountView(RecoverView, '/recover')
      await flushPromises()

      expect(mounted.wrapper.find('[data-test="providers"]').exists()).toBe(false)
      expect(mounted.wrapper.find('[data-test="code"]').exists()).toBe(true)
    })
  })

  it('links back to the landing page', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('[data-test="back"]').attributes('href')).toBe('/')
  })

  it('speaks the language of the driver', async () => {
    await setLocale('de')
    const { wrapper } = await open()

    expect(wrapper.get('[data-test="account"] h2').text()).toBe('Mit deinem Konto anmelden')

    await setLocale('en')
  })
})
