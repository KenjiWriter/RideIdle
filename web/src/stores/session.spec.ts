import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/client'
import type { Me } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { currentLocale, setLocale } from '@/i18n'

import { useSessionStore } from './session'

const profile: Me = { id: 1, name: 'Maciek_Turbo', locale: 'en', is_guest: true, has_recovery_code: false }

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('session store', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('starts signed out', () => {
    const session = useSessionStore()

    expect(session.isAuthenticated).toBe(false)
    expect(session.me).toBeNull()
  })

  it('restores the token of a previous visit', () => {
    storeToken('saved-token')

    expect(useSessionStore().isAuthenticated).toBe(true)
  })

  it('creates a driver, sends the interface language and remembers the token', async () => {
    fetchMock.mockResolvedValue(respond(201, { data: profile, token: 'new-token' }))
    const session = useSessionStore()

    await session.createDriver('Maciek_Turbo')

    const init = (fetchMock.mock.calls[0] as [string, RequestInit])[1]
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body as string)).toEqual({ name: 'Maciek_Turbo', locale: 'en' })
    expect(session.isAuthenticated).toBe(true)
    expect(session.me?.name).toBe('Maciek_Turbo')
    expect(readToken()).toBe('new-token')
  })

  it('does not sign in when the name is rejected', async () => {
    fetchMock.mockResolvedValue(respond(422, { message: 'taken', errors: { name: ['taken'] } }))
    const session = useSessionStore()

    await expect(session.createDriver('Turbo')).rejects.toBeInstanceOf(ApiError)

    expect(session.isAuthenticated).toBe(false)
    expect(readToken()).toBeNull()
  })

  it('restores a driver with a recovery code', async () => {
    fetchMock.mockResolvedValue(respond(200, { data: { ...profile, has_recovery_code: true }, token: 'restored' }))
    const session = useSessionStore()

    await session.recover('ABCD-EFGH-JKLM')

    expect(JSON.parse((fetchMock.mock.calls[0] as [string, RequestInit])[1].body as string)).toEqual({ code: 'ABCD-EFGH-JKLM' })
    expect(session.me?.has_recovery_code).toBe(true)
    expect(readToken()).toBe('restored')
  })

  it('switches the interface to the language of the restored account', async () => {
    fetchMock.mockResolvedValue(respond(200, { data: { ...profile, locale: 'de' }, token: 'restored' }))
    const session = useSessionStore()

    await session.recover('ABCD-EFGH-JKLM')

    expect(currentLocale()).toBe('de')
    expect(document.documentElement.lang).toBe('de')

    await setLocale('en')
  })

  it('keeps the interface language when the account has none or an unknown one', async () => {
    fetchMock.mockResolvedValue(respond(200, { data: { ...profile, locale: null }, token: 'restored' }))
    await useSessionStore().recover('ABCD-EFGH-JKLM')
    expect(currentLocale()).toBe('en')

    fetchMock.mockResolvedValue(respond(200, { data: { ...profile, locale: 'es' }, token: 'restored' }))
    await useSessionStore().recover('ABCD-EFGH-JKLM')
    expect(currentLocale()).toBe('en')
  })

  it('returns a new recovery code and updates the profile', async () => {
    storeToken('t')
    fetchMock.mockResolvedValue(respond(200, { data: { ...profile, has_recovery_code: true }, code: '7K2M-Q9XP-4HTD' }))
    const session = useSessionStore()

    const code = await session.issueRecoveryCode()

    expect(code).toBe('7K2M-Q9XP-4HTD')
    expect(session.me?.has_recovery_code).toBe(true)
  })

  it('signs out when the server no longer knows the token', async () => {
    storeToken('stale')
    fetchMock.mockResolvedValue(respond(401, { message: 'Unauthenticated.' }))
    const session = useSessionStore()

    await session.fetchMe()

    expect(session.isAuthenticated).toBe(false)
    expect(readToken()).toBeNull()
  })

  it('keeps the session on other errors', async () => {
    storeToken('t')
    fetchMock.mockResolvedValue(respond(500, { message: 'Server error' }))
    const session = useSessionStore()

    await expect(session.fetchMe()).rejects.toBeInstanceOf(ApiError)

    expect(session.isAuthenticated).toBe(true)
  })

  it('forgets everything on sign out', async () => {
    fetchMock.mockResolvedValue(respond(201, { data: profile, token: 'new-token' }))
    const session = useSessionStore()
    await session.createDriver('Maciek_Turbo')

    session.signOut()

    expect(session.isAuthenticated).toBe(false)
    expect(session.me).toBeNull()
    expect(readToken()).toBeNull()
  })
})
