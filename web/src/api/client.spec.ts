import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'

import { ApiError, api } from './client'

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('api client', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('asks for JSON and sends the language of the driver', async () => {
    fetchMock.mockResolvedValue(respond(200, { ok: true }))

    await api('/v1/meta/locales')

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const headers = init.headers as Headers

    expect(url).toBe('/api/v1/meta/locales')
    expect(headers.get('Accept')).toBe('application/json')
    expect(headers.get('X-Locale')).toBe('en')
    expect(headers.has('Authorization')).toBe(false)
  })

  it('sends the device token when there is one', async () => {
    storeToken('abc123')
    fetchMock.mockResolvedValue(respond(200, {}))

    await api('/v1/me')

    const headers = (fetchMock.mock.calls[0] as [string, RequestInit])[1].headers as Headers
    expect(headers.get('Authorization')).toBe('Bearer abc123')
  })

  it('marks requests with a body as JSON', async () => {
    fetchMock.mockResolvedValue(respond(201, {}))

    await api('/v1/players', { method: 'POST', body: JSON.stringify({ name: 'Turbo' }) })

    const headers = (fetchMock.mock.calls[0] as [string, RequestInit])[1].headers as Headers
    expect(headers.get('Content-Type')).toBe('application/json')
  })

  it('turns validation failures into an ApiError with field messages', async () => {
    fetchMock.mockResolvedValue(
      respond(422, { message: 'The name has already been taken.', errors: { name: ['The name has already been taken.'] } }),
    )

    const error = await api('/v1/players', { method: 'POST', body: '{}' }).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(422)
    expect((error as ApiError).firstError('name')).toBe('The name has already been taken.')
    expect((error as ApiError).firstError('code')).toBeUndefined()
  })

  it('keeps the status when the error body is not JSON', async () => {
    fetchMock.mockResolvedValue(new Response('Bad gateway', { status: 502, statusText: 'Bad Gateway' }))

    const error = await api('/v1/me').catch((e: unknown) => e)

    expect((error as ApiError).status).toBe(502)
    expect((error as ApiError).message).toBe('Bad Gateway')
    expect((error as ApiError).errors).toEqual({})
  })
})
