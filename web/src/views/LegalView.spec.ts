import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { LegalInfo } from '@/api/types'
import { setLocale } from '@/i18n'
import { respond, serveApi, type Call } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import LegalView from './LegalView.vue'

const fetchMock = vi.fn()

const legal: LegalInfo = {
  min_age: 16,
  version: '2026-10-23',
  guest_expiry_days: 90,
  operator: { name: 'Ride Idle Studio', address: 'ul. Przykładowa 1, 00-001 Warszawa', email: 'legal@ride.example' },
}

/** Opens a document. The texts are loaded from their own file, so the page is waited for. */
async function open(document: 'terms' | 'privacy', info: LegalInfo = legal) {
  const calls = serveApi(fetchMock, { 'GET /api/v1/meta/legal': () => respond(200, { data: info }) })
  const mounted = await mountView(LegalView, `/${document}`, { props: { document } })
  await vi.waitFor(() => expect(mounted.wrapper.find('[data-test="title"]').exists()).toBe(true))

  return { ...mounted, calls }
}

function made(calls: Call[], url: string): Call[] {
  return calls.filter((call) => call.url === url)
}

describe('LegalView', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  describe('the terms', () => {
    it('shows the title, the version as a day, and the introduction', async () => {
      const { wrapper } = await open('terms')

      expect(wrapper.get('[data-test="title"]').text()).toBe('Terms of service')
      expect(wrapper.get('[data-test="version"]').text()).toBe('Version of October 23, 2026')
      expect(wrapper.get('[data-test="intro"]').text()).toContain('RideIdle')
    })

    it('numbers the sections and lists them in a contents that leads to each', async () => {
      const { wrapper } = await open('terms')

      const headings = wrapper.findAll('[data-test="section"] h2').map((heading) => heading.text())
      expect(headings).toHaveLength(11)
      expect(headings[0]).toBe('1. Who these terms are for')

      const links = wrapper.findAll('[data-test="contents"] a')
      expect(links).toHaveLength(11)
      for (const link of links) {
        const target = link.attributes('href')?.replace('#', '')
        expect(wrapper.find(`section[id="${target}"]`).exists(), `no section for ${target}`).toBe(true)
      }
    })

    it('puts the age and the days the server says into the text, and leaves no placeholder behind', async () => {
      const { wrapper } = await open('terms', { ...legal, min_age: 18, guest_expiry_days: 45 })
      const text = wrapper.text()

      expect(text).toContain('at least 18 years old')
      expect(text).toContain('after 45 days without activity')
      expect(text).not.toMatch(/\{[a-zA-Z]+\}/)
    })

    it('links to the privacy policy', async () => {
      const { wrapper } = await open('terms')

      expect(wrapper.get('[data-test="other"]').text()).toBe('Privacy policy')
      expect(wrapper.get('[data-test="other"]').attributes('href')).toBe('/privacy')
    })
  })

  describe('the privacy policy', () => {
    it('shows the policy with its own sections and links to the terms', async () => {
      const { wrapper } = await open('privacy')

      expect(wrapper.get('[data-test="title"]').text()).toBe('Privacy policy')
      expect(wrapper.findAll('[data-test="section"]')).toHaveLength(12)
      expect(wrapper.get('[data-test="other"]').text()).toBe('Terms of service')
      expect(wrapper.get('[data-test="other"]').attributes('href')).toBe('/terms')
    })

    it('says that other drivers see the driver name, as the form where it is chosen says too', async () => {
      const { wrapper } = await open('privacy')

      expect(wrapper.get('section[id="data"]').text()).toContain('the driver name you choose (other drivers can see it)')
    })

    it('says how long a guest account is kept, from the setting of the server', async () => {
      const { wrapper } = await open('privacy', { ...legal, guest_expiry_days: 30 })

      expect(wrapper.get('section[id="retention"]').text()).toContain('deleted after 30 days without activity')
    })
  })

  describe('the operator', () => {
    it('is named at the top, with a link to write to', async () => {
      const { wrapper } = await open('terms')

      expect(wrapper.get('[data-test="operator-name"]').text()).toBe('Ride Idle Studio')
      expect(wrapper.get('[data-test="operator-address"]').text()).toBe('ul. Przykładowa 1, 00-001 Warszawa')
      expect(wrapper.get('[data-test="operator-email"] a').attributes('href')).toBe('mailto:legal@ride.example')
    })

    it('is shown as missing, in red, for each detail the server does not have', async () => {
      const { wrapper } = await open('terms', { ...legal, operator: { name: null, address: null, email: 'legal@ride.example' } })

      expect(wrapper.get('[data-test="operator-name"]').text()).toBe('not set yet')
      expect(wrapper.get('[data-test="operator-name"] span').classes()).toContain('text-danger')
      expect(wrapper.get('[data-test="operator-address"]').text()).toBe('not set yet')
      expect(wrapper.get('[data-test="operator-email"]').text()).toBe('legal@ride.example')
    })

    it('does not make a link of an address that is missing', async () => {
      const { wrapper } = await open('terms', { ...legal, operator: { name: null, address: null, email: null } })

      expect(wrapper.find('[data-test="operator-email"] a').exists()).toBe(false)
    })
  })

  it('asks the server once for what the texts need', async () => {
    const { calls } = await open('terms')

    expect(made(calls, '/api/v1/meta/legal')).toHaveLength(1)
  })

  it('does not need a driver: it asks for no token', async () => {
    await open('terms')

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit
    expect(new Headers(init.headers).has('Authorization')).toBe(false)
  })

  describe('when the server cannot be asked', () => {
    it('says so and lets the reader try again', async () => {
      fetchMock.mockRejectedValue(new TypeError('offline'))
      const mounted = await mountView(LegalView, '/terms', { props: { document: 'terms' } })
      await flushPromises()

      expect(mounted.wrapper.get('[data-test="load-error"]').text()).toContain('The page could not be loaded.')
      expect(mounted.wrapper.find('[data-test="title"]').exists()).toBe(false)

      serveApi(fetchMock, { 'GET /api/v1/meta/legal': () => respond(200, { data: legal }) })
      await mounted.wrapper.get('[data-test="retry"]').trigger('click')
      await vi.waitFor(() => expect(mounted.wrapper.find('[data-test="title"]').exists()).toBe(true))

      expect(mounted.wrapper.find('[data-test="load-error"]').exists()).toBe(false)
    })
  })

  describe('in another language', () => {
    it.each([
      ['pl', 'Regulamin', 'Wersja z dnia 23 października 2026', 'co najmniej 16 lat'],
      ['fr', 'Conditions d’utilisation', 'Version du 23 octobre 2026', 'au moins 16 ans'],
      ['de', 'Nutzungsbedingungen', 'Version vom 23. Oktober 2026', 'mindestens 16 Jahre'],
      ['pt-BR', 'Termos de uso', 'Versão de 23 de outubro de 2026', 'pelo menos 16 anos'],
    ] as const)('shows the terms in %s', async (locale, title, version, age) => {
      await setLocale(locale)
      const { wrapper } = await open('terms')

      expect(wrapper.get('[data-test="title"]').text()).toBe(title)
      expect(wrapper.get('[data-test="version"]').text()).toBe(version)
      expect(wrapper.text()).toContain(age)

      await setLocale('en')
    })

    it('changes the language of the text when the driver switches the language of the page', async () => {
      const { wrapper } = await open('privacy')
      expect(wrapper.get('[data-test="title"]').text()).toBe('Privacy policy')

      await setLocale('de')
      await vi.waitFor(() => expect(wrapper.get('[data-test="title"]').text()).toBe('Datenschutzerklärung'))
      expect(wrapper.get('[data-test="version"]').text()).toBe('Version vom 23. Oktober 2026')

      await setLocale('en')
    })
  })

  it('leads back to the start', async () => {
    const { wrapper } = await open('terms')

    expect(wrapper.get('[data-test="back"]').attributes('href')).toBe('/')
  })
})
