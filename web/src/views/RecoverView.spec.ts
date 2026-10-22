import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { readToken } from '@/auth/token'
import { i18n } from '@/i18n'
import { mountView } from '@/test/mountView'

import RecoverView from './RecoverView.vue'

const profile = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: true }

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

async function open() {
  const mounted = await mountView(RecoverView, '/recover')

  async function submit(code: string) {
    await mounted.wrapper.get('[data-test="code"]').setValue(code)
    await mounted.wrapper.get('form').trigger('submit')
    await flushPromises()
  }

  return { ...mounted, submit, error: () => mounted.wrapper.get('[data-test="error"]').text() }
}

describe('RecoverView', () => {
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

  it('asks for a code and sends nothing when the field is empty', async () => {
    const { submit, error } = await open()

    await submit('   ')

    expect(error()).toBe('Enter your recovery code.')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('restores the driver and opens the garage', async () => {
    fetchMock.mockResolvedValue(respond(200, { data: profile, token: 'restored-token' }))
    const { submit, router } = await open()

    await submit(' 7k2m-q9xp-4htd ')

    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/account/recover')
    expect(JSON.parse((fetchMock.mock.calls[0] as [string, RequestInit])[1].body as string)).toEqual({ code: '7k2m-q9xp-4htd' })
    expect(readToken()).toBe('restored-token')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('garage'))
  })

  it('shows the message of the server for a wrong code and stays on the page', async () => {
    fetchMock.mockResolvedValue(
      respond(422, { message: 'This recovery code is not valid.', errors: { code: ['This recovery code is not valid.'] } }),
    )
    const { submit, error, router } = await open()

    await submit('ABCD-EFGH-JKLM')

    expect(error()).toBe('This recovery code is not valid.')
    expect(router.currentRoute.value.name).toBe('recover')
    expect(readToken()).toBeNull()
  })

  it('shows the lock message of the server after too many failures', async () => {
    fetchMock.mockResolvedValue(respond(429, { message: 'Too many failed attempts. Try again later.' }))
    const { submit, error } = await open()

    await submit('ABCD-EFGH-JKLM')

    expect(error()).toBe('Too many failed attempts. Try again later.')
  })

  it('clears the message when the driver edits the code', async () => {
    fetchMock.mockResolvedValue(respond(422, { message: 'bad', errors: { code: ['bad'] } }))
    const { submit, wrapper, error } = await open()
    await submit('ABCD-EFGH-JKLM')

    await wrapper.get('[data-test="code"]').setValue('ABCD-EFGH-JKLN')

    expect(error()).toBe('')
  })

  it('shows a general message when the server cannot be reached', async () => {
    fetchMock.mockRejectedValue(new TypeError('offline'))
    const { submit, error } = await open()

    await submit('ABCD-EFGH-JKLM')

    expect(error()).toBe('Something went wrong. Try again.')
  })

  it('links back to the landing page', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('[data-test="back"]').attributes('href')).toBe('/')
  })
})
