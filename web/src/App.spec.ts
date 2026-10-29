import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { setLocale } from '@/i18n'
import { mountView } from '@/test/mountView'

describe('App', () => {
  const fetchMock = vi.fn()

  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    fetchMock.mockResolvedValue(new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
  })

  afterEach(async () => {
    vi.unstubAllGlobals()
    await setLocale('en')
  })

  it('has the terms and the privacy policy one click away on every page', async () => {
    const { wrapper } = await mountView(App, '/')
    await flushPromises()

    expect(wrapper.get('[data-test="footer-terms"]').text()).toBe('Terms of service')
    expect(wrapper.get('[data-test="footer-terms"]').attributes('href')).toBe('/terms')
    expect(wrapper.get('[data-test="footer-privacy"]').text()).toBe('Privacy policy')
    expect(wrapper.get('[data-test="footer-privacy"]').attributes('href')).toBe('/privacy')
  })

  it('names them in the language of the page', async () => {
    await setLocale('pl')
    const { wrapper } = await mountView(App, '/')
    await flushPromises()

    expect(wrapper.get('[data-test="footer-terms"]').text()).toBe('Regulamin')
    expect(wrapper.get('[data-test="footer-privacy"]').text()).toBe('Polityka prywatności')
  })

  it('has the header with the name of the game and the language on a page of the legal documents, and none on the landing page, which draws its own', async () => {
    const legal = await mountView(App, '/terms')
    await flushPromises()

    expect(legal.wrapper.find('[data-test="app-header"]').exists()).toBe(true)
    expect(legal.wrapper.find('[data-test="app-header"] select').exists()).toBe(true)

    const landing = await mountView(App, '/')
    await flushPromises()

    expect(landing.wrapper.find('[data-test="app-header"]').exists()).toBe(false)
  })

  it('is there on the terms page as well, so that the other document is at hand', async () => {
    const { wrapper } = await mountView(App, '/terms')
    await flushPromises()

    expect(wrapper.find('[data-test="footer"]').exists()).toBe(true)
  })
})
