import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { currentLocale, setLocale } from '@/i18n'

import { useSettingsStore } from './settings'

describe('settings store', () => {
  const fetchMock = vi.fn()

  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    fetchMock.mockResolvedValue(new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    setActivePinia(createPinia())
  })

  afterEach(async () => {
    vi.unstubAllGlobals()
    await setLocale('en')
  })

  it('changes the interface language and remembers it for the next visit', async () => {
    const settings = useSettingsStore()

    await settings.changeLocale('fr')

    expect(settings.locale).toBe('fr')
    expect(currentLocale()).toBe('fr')
    expect(document.documentElement.lang).toBe('fr')
    expect(localStorage.getItem('ride.locale')).toBe('fr')
  })

  it('does not call the API when nobody is signed in', async () => {
    await useSettingsStore().changeLocale('de')

    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('saves the language on the account of a signed-in driver', async () => {
    storeToken('device-token')

    await useSettingsStore().changeLocale('pt-BR')

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/v1/me/locale')
    expect(init.method).toBe('PATCH')
    expect(JSON.parse(init.body as string)).toEqual({ locale: 'pt-BR' })
    expect((init.headers as Headers).get('Authorization')).toBe('Bearer device-token')
  })

  it('keeps the new language when saving it fails', async () => {
    storeToken('device-token')
    fetchMock.mockRejectedValue(new TypeError('offline'))
    const settings = useSettingsStore()

    await expect(settings.changeLocale('pl')).resolves.toBeUndefined()

    expect(settings.locale).toBe('pl')
    expect(currentLocale()).toBe('pl')
  })

  it('applies a language without touching the account', async () => {
    storeToken('device-token')

    await useSettingsStore().applyLocale('de')

    expect(currentLocale()).toBe('de')
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
