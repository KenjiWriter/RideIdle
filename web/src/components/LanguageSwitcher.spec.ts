import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { currentLocale, setLocale } from '@/i18n'
import { mountView } from '@/test/mountView'

import LanguageSwitcher from './LanguageSwitcher.vue'

describe('LanguageSwitcher', () => {
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

  it('lists the five languages by their own names', async () => {
    const { wrapper } = await mountView(LanguageSwitcher)

    const options = wrapper.findAll('option').map((option) => option.text())

    expect(options).toEqual(['Polski', 'English', 'Français', 'Deutsch', 'Português (Brasil)'])
    expect(wrapper.get('select').element.value).toBe('en')
  })

  it('switches the interface language and its label', async () => {
    const { wrapper } = await mountView(LanguageSwitcher)

    await wrapper.get('select').setValue('pl')
    await vi.waitFor(() => expect(currentLocale()).toBe('pl'))

    expect(wrapper.get('span').text()).toBe('Język')
  })

  it('saves the choice on the account when signed in', async () => {
    storeToken('device-token')
    const { wrapper } = await mountView(LanguageSwitcher)

    await wrapper.get('select').setValue('de')

    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    expect(JSON.parse((fetchMock.mock.calls[0] as [string, RequestInit])[1].body as string)).toEqual({ locale: 'de' })
  })
})
