import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import type { Me } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { saveAsFile } from '@/features/account/download'
import { setLocale } from '@/i18n'
import { useSessionStore } from '@/stores/session'
import { respond, serveApi, type Call } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import AccountData from './AccountData.vue'

vi.mock('@/features/account/download', async (original) => ({ ...(await original<typeof import('@/features/account/download')>()), saveAsFile: vi.fn() }))

const fetchMock = vi.fn()

const driver: Me = {
  id: 1,
  name: 'Maciek_Turbo',
  locale: 'en',
  is_guest: false,
  has_recovery_code: false,
  identities: [{ provider: 'email', email: 't***@example.com' }],
}

/** Shows the section for a driver. */
async function show(routes: Record<string, () => Response | Promise<Response>> = {}) {
  const calls = serveApi(fetchMock, routes)
  const mounted = await mountView(AccountData)
  useSessionStore().me = driver
  await nextTick()

  return { ...mounted, calls }
}

function made(calls: Call[], method: string, url: string): Call[] {
  return calls.filter((call) => call.method === method && call.url === url)
}

async function openConfirmation(wrapper: Awaited<ReturnType<typeof show>>['wrapper']) {
  await wrapper.get('[data-test="delete"]').trigger('click')
  await flushPromises()
}

describe('AccountData', () => {
  beforeEach(async () => {
    localStorage.clear()
    storeToken('device-token')
    fetchMock.mockReset()
    vi.mocked(saveAsFile).mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('has nothing to show until the profile of the driver is known', async () => {
    serveApi(fetchMock, {})
    const { wrapper } = await mountView(AccountData)

    expect(wrapper.find('[data-test="data"]').exists()).toBe(false)
  })

  it('offers a copy of the data and the deletion of the account, and does not ask for anything yet', async () => {
    const { wrapper } = await show()

    expect(wrapper.get('h2').text()).toBe('Your data')
    expect(wrapper.get('[data-test="download"]').text()).toBe('Download my data')
    expect(wrapper.get('[data-test="delete"]').text()).toBe('Delete my account')
    expect(wrapper.find('[data-test="delete-form"]').exists()).toBe(false)
  })

  describe('the copy of the data', () => {
    it('is fetched and saved as a file, and the driver is told what is in it', async () => {
      const { wrapper, calls } = await show({ 'GET /api/v1/account/export': () => respond(200, { account: { id: 1 } }) })

      await wrapper.get('[data-test="download"]').trigger('click')
      await flushPromises()

      expect(made(calls, 'GET', '/api/v1/account/export')).toHaveLength(1)
      expect(saveAsFile).toHaveBeenCalledTimes(1)
      expect(wrapper.get('[data-test="downloaded"]').text()).toBe('Saved. The file holds your data, but no recovery code or other secret.')
    })

    it('shows that it is being prepared, and takes one request at a time', async () => {
      let answer: (response: Response) => void = () => undefined
      const { wrapper, calls } = await show({ 'GET /api/v1/account/export': () => new Promise<Response>((resolve) => (answer = resolve)) })

      await wrapper.get('[data-test="download"]').trigger('click')
      await wrapper.get('[data-test="download"]').trigger('click')

      expect(wrapper.get('[data-test="download"]').text()).toBe('Preparing…')
      expect(wrapper.get('[data-test="download"]').attributes('disabled')).toBeDefined()
      expect(made(calls, 'GET', '/api/v1/account/export')).toHaveLength(1)

      answer(respond(200, {}))
      await flushPromises()

      expect(wrapper.get('[data-test="download"]').text()).toBe('Download my data')
    })

    it('says so, and saves nothing, when there were too many copies', async () => {
      const { wrapper } = await show({ 'GET /api/v1/account/export': () => respond(429, { message: 'Too many attempts. Try again later.' }) })

      await wrapper.get('[data-test="download"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="download-error"]').text()).toBe('Too many attempts. Try again later.')
      expect(saveAsFile).not.toHaveBeenCalled()
      expect(wrapper.find('[data-test="downloaded"]').exists()).toBe(false)
    })

    it('says something went wrong when the server cannot be reached', async () => {
      const { wrapper } = await show()
      fetchMock.mockRejectedValue(new TypeError('offline'))

      await wrapper.get('[data-test="download"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="download-error"]').text()).toBe('Something went wrong. Try again.')
    })

    it('ends the session when the server no longer knows the device', async () => {
      const { wrapper } = await show({ 'GET /api/v1/account/export': () => respond(401, { message: 'Unauthenticated.' }) })

      await wrapper.get('[data-test="download"]').trigger('click')
      await flushPromises()

      expect(useSessionStore().isAuthenticated).toBe(false)
      expect(wrapper.find('[data-test="download-error"]').exists()).toBe(false)
    })
  })

  describe('deleting the account', () => {
    it('says what goes and asks for the name of the driver, and deletes nothing yet', async () => {
      const { wrapper, calls } = await show()

      await openConfirmation(wrapper)

      expect(wrapper.get('[data-test="warning"]').text()).toContain('It cannot be undone')
      expect(wrapper.get('label[for="confirm-name"]').text()).toBe('To confirm, type the name of your driver: Maciek_Turbo')
      expect(wrapper.find('[data-test="delete"]').exists()).toBe(false)
      expect(made(calls, 'DELETE', '/api/v1/account')).toHaveLength(0)
    })

    it('puts the cursor in the field for the name', async () => {
      const host = document.createElement('div')
      document.body.appendChild(host)
      serveApi(fetchMock, {})
      const mounted = await mountView(AccountData, '/', { attachTo: host })
      useSessionStore().me = driver
      await nextTick()

      await mounted.wrapper.get('[data-test="delete"]').trigger('click')
      await flushPromises()

      expect(document.activeElement).toBe(mounted.wrapper.get('[data-test="confirm-name"]').element)

      mounted.wrapper.unmount()
      host.remove()
    })

    it.each([
      ['nothing', '', true],
      ['a part of the name', 'Maciek', true],
      ['another name', 'Somebody_Else', true],
      ['the name', 'Maciek_Turbo', false],
      ['the name in other letters, with spaces around', '  maciek_TURBO  ', false],
    ])('keeps the button for good disabled until the name is there: %s', async (_name, typed, disabled) => {
      const { wrapper } = await show()
      await openConfirmation(wrapper)

      await wrapper.get('[data-test="confirm-name"]').setValue(typed)

      expect(wrapper.get('[data-test="confirm-delete"]').attributes('disabled') !== undefined).toBe(disabled)
    })

    it('sends nothing when the form is submitted with the wrong name', async () => {
      const { wrapper, calls } = await show()
      await openConfirmation(wrapper)
      await wrapper.get('[data-test="confirm-name"]').setValue('nope')

      await wrapper.get('[data-test="delete-form"]').trigger('submit')
      await flushPromises()

      expect(made(calls, 'DELETE', '/api/v1/account')).toHaveLength(0)
    })

    it('deletes the account with the name written, and the device forgets it', async () => {
      const { wrapper, calls } = await show({ 'DELETE /api/v1/account': () => respond(200, { deleted: true }) })
      await openConfirmation(wrapper)
      await wrapper.get('[data-test="confirm-name"]').setValue(' Maciek_Turbo ')

      await wrapper.get('[data-test="delete-form"]').trigger('submit')
      await flushPromises()

      expect(made(calls, 'DELETE', '/api/v1/account')[0]?.body).toEqual({ confirm_name: 'Maciek_Turbo' })
      expect(useSessionStore().isAuthenticated).toBe(false)
      expect(readToken()).toBeNull()
      expect(useSessionStore().accountDeleted).toBe(true)
    })

    it('shows that it is deleting, and takes it once', async () => {
      let answer: (response: Response) => void = () => undefined
      const { wrapper, calls } = await show({ 'DELETE /api/v1/account': () => new Promise<Response>((resolve) => (answer = resolve)) })
      await openConfirmation(wrapper)
      await wrapper.get('[data-test="confirm-name"]').setValue('Maciek_Turbo')

      await wrapper.get('[data-test="delete-form"]').trigger('submit')
      await wrapper.get('[data-test="delete-form"]').trigger('submit')

      expect(wrapper.get('[data-test="confirm-delete"]').text()).toBe('Deleting…')
      expect(wrapper.get('[data-test="cancel"]').attributes('disabled')).toBeDefined()
      expect(made(calls, 'DELETE', '/api/v1/account')).toHaveLength(1)

      answer(respond(200, { deleted: true }))
      await flushPromises()
    })

    it('shows what the server says when the name is refused, and keeps everything as it was', async () => {
      const { wrapper } = await show({
        'DELETE /api/v1/account': () =>
          respond(422, { message: 'x', errors: { confirm_name: ['Type your driver name exactly as it is written to delete the account.'] } }),
      })
      await openConfirmation(wrapper)
      await wrapper.get('[data-test="confirm-name"]').setValue('Maciek_Turbo')

      await wrapper.get('[data-test="delete-form"]').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-test="delete-error"]').text()).toBe('Type your driver name exactly as it is written to delete the account.')
      expect(useSessionStore().isAuthenticated).toBe(true)
      // The driver can try again.
      expect(wrapper.get('[data-test="confirm-delete"]').attributes('disabled')).toBeUndefined()
    })

    it('says something went wrong when the server cannot be reached, and nothing is deleted', async () => {
      const { wrapper } = await show()
      fetchMock.mockRejectedValue(new TypeError('offline'))
      await openConfirmation(wrapper)
      await wrapper.get('[data-test="confirm-name"]').setValue('Maciek_Turbo')

      await wrapper.get('[data-test="delete-form"]').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-test="delete-error"]').text()).toBe('Something went wrong. Try again.')
      expect(useSessionStore().isAuthenticated).toBe(true)
      expect(useSessionStore().accountDeleted).toBe(false)
    })

    it('asks to wait when there were too many tries', async () => {
      const { wrapper } = await show({ 'DELETE /api/v1/account': () => respond(429, { message: 'Too many attempts. Try again later.' }) })
      await openConfirmation(wrapper)
      await wrapper.get('[data-test="confirm-name"]').setValue('Maciek_Turbo')

      await wrapper.get('[data-test="delete-form"]').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[data-test="delete-error"]').text()).toBe('Too many attempts. Try again later.')
    })

    it('lets the driver change their mind: cancel closes it and forgets what was written', async () => {
      const { wrapper, calls } = await show()
      await openConfirmation(wrapper)
      await wrapper.get('[data-test="confirm-name"]').setValue('Maciek_Turbo')

      await wrapper.get('[data-test="cancel"]').trigger('click')

      expect(wrapper.find('[data-test="delete-form"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="delete"]').exists()).toBe(true)
      expect(made(calls, 'DELETE', '/api/v1/account')).toHaveLength(0)

      await openConfirmation(wrapper)
      expect((wrapper.get('[data-test="confirm-name"]').element as HTMLInputElement).value).toBe('')
    })
  })

  it.each([
    ['pl', 'Twoje dane', 'Usuń moje konto'],
    ['fr', 'Vos données', 'Supprimer mon compte'],
    ['de', 'Deine Daten', 'Mein Konto löschen'],
    ['pt-BR', 'Seus dados', 'Excluir minha conta'],
  ] as const)('speaks %s', async (locale, title, deleteLabel) => {
    await setLocale(locale)
    const { wrapper } = await show()

    expect(wrapper.get('h2').text()).toBe(title)
    expect(wrapper.get('[data-test="delete"]').text()).toBe(deleteLabel)

    await setLocale('en')
  })
})
