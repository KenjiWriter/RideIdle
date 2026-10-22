import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Me } from '@/api/types'
import { storeToken } from '@/auth/token'
import { i18n } from '@/i18n'
import { useSessionStore } from '@/stores/session'
import { mountView } from '@/test/mountView'

import RecoveryPanel from './RecoveryPanel.vue'

const guest: Me = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: false }

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

async function open(profile: Me = guest) {
  const mounted = await mountView(RecoveryPanel)
  useSessionStore().me = profile
  await mounted.wrapper.vm.$nextTick()

  return mounted
}

describe('RecoveryPanel', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    storeToken('device-token')
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    i18n.global.locale.value = 'en'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers to show a code when the driver has none', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('[data-test="issue"]').text()).toBe('Show my recovery code')
    expect(wrapper.find('[data-test="hint"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="code"]').exists()).toBe(false)
  })

  it('shows the new code once, with a warning', async () => {
    fetchMock.mockResolvedValue(respond(200, { data: { ...guest, has_recovery_code: true }, code: '7K2M-Q9XP-4HTD' }))
    const { wrapper } = await open()

    await wrapper.get('[data-test="issue"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="code"]').text()).toBe('7K2M-Q9XP-4HTD')
    expect(wrapper.text()).toContain('It is shown only once.')
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/account/recovery-code')
    expect((fetchMock.mock.calls[0] as [string, RequestInit])[1].method).toBe('POST')
  })

  it('hides the code after the driver confirms and offers a new one', async () => {
    fetchMock.mockResolvedValue(respond(200, { data: { ...guest, has_recovery_code: true }, code: '7K2M-Q9XP-4HTD' }))
    const { wrapper } = await open()
    await wrapper.get('[data-test="issue"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="done"]').trigger('click')

    expect(wrapper.find('[data-test="code"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="hint"]').text()).toContain('A new one makes the old one stop working.')
    expect(wrapper.get('[data-test="issue"]').text()).toBe('Create a new code')
  })

  it('warns that a new code replaces the old one', async () => {
    const { wrapper } = await open({ ...guest, has_recovery_code: true })

    expect(wrapper.find('[data-test="hint"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="issue"]').text()).toBe('Create a new code')
  })

  it('copies the code to the clipboard', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } })
    fetchMock.mockResolvedValue(respond(200, { data: { ...guest, has_recovery_code: true }, code: '7K2M-Q9XP-4HTD' }))
    const { wrapper } = await open()
    await wrapper.get('[data-test="issue"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith('7K2M-Q9XP-4HTD')
    expect(wrapper.get('[data-test="copy"]').text()).toBe('Copied')
  })

  it('keeps the code on screen when the clipboard is blocked', async () => {
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } })
    fetchMock.mockResolvedValue(respond(200, { data: { ...guest, has_recovery_code: true }, code: '7K2M-Q9XP-4HTD' }))
    const { wrapper } = await open()
    await wrapper.get('[data-test="issue"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="copy"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="code"]').text()).toBe('7K2M-Q9XP-4HTD')
    expect(wrapper.get('[data-test="copy"]').text()).toBe('Copy')
  })

  it.each([
    [429, 'Too many attempts. Try again later.'],
    [500, 'Something went wrong. Try again.'],
  ])('explains a %i response', async (status, message) => {
    fetchMock.mockResolvedValue(respond(status, { message: 'x' }))
    const { wrapper } = await open()

    await wrapper.get('[data-test="issue"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe(message)
    expect(wrapper.find('[data-test="code"]').exists()).toBe(false)
  })

  it('does not issue two codes at once', async () => {
    let finish: (response: Response) => void = () => undefined
    fetchMock.mockReturnValue(new Promise<Response>((resolve) => (finish = resolve)))
    const { wrapper } = await open()

    await wrapper.get('[data-test="issue"]').trigger('click')
    expect(wrapper.get('[data-test="issue"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="issue"]').trigger('click')

    expect(fetchMock).toHaveBeenCalledTimes(1)

    finish(respond(200, { data: { ...guest, has_recovery_code: true }, code: '7K2M-Q9XP-4HTD' }))
    await flushPromises()
  })
})
