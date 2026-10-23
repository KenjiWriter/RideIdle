import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import type { AccountIdentity, Me } from '@/api/types'
import { storeToken } from '@/auth/token'
import { goTo } from '@/features/account/redirect'
import { setLocale } from '@/i18n'
import { useSessionStore } from '@/stores/session'
import { respond, serveApi, type Call } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import LinkedAccounts from './LinkedAccounts.vue'

vi.mock('@/features/account/redirect', () => ({ goTo: vi.fn() }))

const fetchMock = vi.fn()

const email: AccountIdentity = { provider: 'email', email: 't***@example.com' }
const google: AccountIdentity = { provider: 'google', email: 'm***@gmail.com' }

function profile(identities: AccountIdentity[]): Me {
  return { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: identities.length === 0, has_recovery_code: false, identities }
}

/** Shows the card for a driver with these sign-ins, on a server that has these providers on. */
async function show(identities: AccountIdentity[], providers: string[] = ['google', 'facebook', 'apple'], extra: Record<string, () => Response> = {}) {
  const calls = serveApi(fetchMock, { 'GET /api/v1/auth/providers': () => respond(200, { data: providers }), ...extra })
  const mounted = await mountView(LinkedAccounts)
  useSessionStore().me = profile(identities)
  await nextTick()
  await flushPromises()

  return { ...mounted, calls }
}

function made(calls: Call[], method: string, url: string): Call[] {
  return calls.filter((call) => call.method === method && call.url === url)
}

describe('LinkedAccounts', () => {
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

  it('lists how the driver signs in, with the address masked by the server', async () => {
    const { wrapper } = await show([email, google])

    expect(wrapper.findAll('[data-test="identity-email"] span').map((part) => part.text())).toEqual(['E-mail', 't***@example.com'])
    expect(wrapper.findAll('[data-test="identity-google"] span').map((part) => part.text())).toEqual(['Google', 'm***@gmail.com'])
  })

  it('shows nothing for a guest, who has no sign-in, and does not ask which providers are on', async () => {
    const { wrapper, calls } = await show([])

    expect(wrapper.find('[data-test="linked"]').exists()).toBe(false)
    expect(made(calls, 'GET', '/api/v1/auth/providers')).toHaveLength(0)
  })

  it('asks which providers are on once a driver has an account', async () => {
    const { calls } = await show([google])

    expect(made(calls, 'GET', '/api/v1/auth/providers')).toHaveLength(1)
  })

  describe('adding another sign-in', () => {
    it('is offered when something is left to add', async () => {
      const { wrapper } = await show([google])

      expect(wrapper.get('[data-test="add-toggle"]').text()).toBe('Add another sign-in')
      expect(wrapper.find('[data-test="add"]').exists()).toBe(false)
    })

    it('is offered to an account that has an e-mail when the server has a provider it lacks', async () => {
      const { wrapper } = await show([email], ['google'])

      expect(wrapper.find('[data-test="add-toggle"]').exists()).toBe(true)
    })

    it('is not offered when the account has an e-mail and every provider that is on', async () => {
      const { wrapper } = await show([email, google], ['google'])

      expect(wrapper.find('[data-test="add-toggle"]').exists()).toBe(false)
    })

    it('is not offered to an account with an e-mail when no provider is on', async () => {
      const { wrapper } = await show([email], [])

      expect(wrapper.find('[data-test="add-toggle"]').exists()).toBe(false)
    })

    it('offers an e-mail to an account that has none, even when no provider is on', async () => {
      const { wrapper } = await show([google], [])

      expect(wrapper.find('[data-test="add-toggle"]').exists()).toBe(true)
    })

    it('opens and closes the ways that are left, and tells a screen reader which', async () => {
      const { wrapper } = await show([google])
      const toggle = wrapper.get('[data-test="add-toggle"]')
      expect(toggle.attributes('aria-expanded')).toBe('false')

      await toggle.trigger('click')

      expect(toggle.attributes('aria-expanded')).toBe('true')
      expect(toggle.text()).toBe('Close')
      expect(wrapper.get('[data-test="add"]').text()).toContain('Link one more way to get into this garage')
      expect(wrapper.findAll('[data-test^="provider-"]').map((button) => button.text())).toEqual(['Continue with Facebook', 'Continue with Apple'])
      expect(wrapper.find('[data-test="email"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="terms"]').exists()).toBe(false)

      await toggle.trigger('click')

      expect(wrapper.find('[data-test="add"]').exists()).toBe(false)
    })

    it('adds a provider, asking nothing of the driver but the click', async () => {
      const { wrapper, calls } = await show([email], ['google', 'facebook'], {
        'POST /api/v1/account/identities/facebook/start': () => respond(200, { url: 'https://facebook.test/dialog?state=s', verifier: 'v-789' }),
      })
      await wrapper.get('[data-test="add-toggle"]').trigger('click')

      await wrapper.get('[data-test="provider-facebook"]').trigger('click')
      await flushPromises()

      expect(made(calls, 'POST', '/api/v1/account/identities/facebook/start')[0]?.body).toEqual({})
      expect(goTo).toHaveBeenCalledWith('https://facebook.test/dialog?state=s')
    })

    it('adds an e-mail, and says the link is on its way', async () => {
      const { wrapper, calls } = await show([google], ['google'], {
        'POST /api/v1/account/email': () => respond(202, { message: 'We sent a link to turbo@example.com.', expires_in: 1800 }),
      })
      await wrapper.get('[data-test="add-toggle"]').trigger('click')
      await wrapper.get('[data-test="email"]').setValue('turbo@example.com')

      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(made(calls, 'POST', '/api/v1/account/email')[0]?.body).toEqual({ email: 'turbo@example.com' })
      expect(wrapper.get('[data-test="sent"]').text()).toContain('We sent a link to turbo@example.com.')
    })
  })

  it('speaks the language of the driver', async () => {
    await setLocale('de')
    const { wrapper } = await show([google])

    expect(wrapper.get('[data-test="add-toggle"]').text()).toBe('Weitere Anmeldemethode hinzufügen')

    await setLocale('en')
  })
})
