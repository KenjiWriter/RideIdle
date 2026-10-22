import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { i18n } from '@/i18n'
import { mountView } from '@/test/mountView'

import LandingView from './LandingView.vue'

describe('LandingView', () => {
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

  it('invites a new visitor to create a driver or restore one', async () => {
    const { wrapper } = await mountView(LandingView)

    expect(wrapper.get('h1').text()).toBe('Your workshop is waiting')
    expect(wrapper.get('[data-test="create"]').text()).toBe('Create your driver')
    expect(wrapper.get('[data-test="create"]').attributes('href')).toBe('/create')
    expect(wrapper.get('[data-test="have-driver"]').attributes('href')).toBe('/recover')
    expect(wrapper.find('[data-test="continue"]').exists()).toBe(false)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('greets a returning driver by name', async () => {
    storeToken('t')
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({ data: { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: false } }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    )

    const { wrapper } = await mountView(LandingView)
    await flushPromises()

    expect(wrapper.get('[data-test="continue"]').text()).toBe('Continue as Maciek_Turbo')
    expect(wrapper.get('[data-test="continue"]').attributes('href')).toBe('/garage')
    expect(wrapper.find('[data-test="create"]').exists()).toBe(false)
  })

  it('still lets the driver continue when the profile cannot be loaded', async () => {
    storeToken('t')
    fetchMock.mockRejectedValue(new TypeError('offline'))

    const { wrapper } = await mountView(LandingView)
    await flushPromises()

    expect(wrapper.get('[data-test="continue"]').text()).toBe('Continue')
  })

  it('speaks the language of the interface', async () => {
    i18n.global.locale.value = 'pl'
    i18n.global.setLocaleMessage('pl', (await import('@/i18n/locales/pl.json')).default)

    const { wrapper } = await mountView(LandingView)

    expect(wrapper.get('[data-test="create"]').text()).toBe('Utwórz kierowcę')
  })
})
