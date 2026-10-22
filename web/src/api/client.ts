import { currentLocale } from '@/i18n'

const API_URL: string = import.meta.env.VITE_API_URL ?? '/api'

export class ApiError extends Error {
  readonly status: number
  readonly code: string | undefined

  constructor(status: number, message: string, code?: string) {
    super(message)
    this.status = status
    this.code = code
  }
}

/**
 * Cienka nakładka na fetch: zawsze prosi o JSON i przekazuje język gracza
 * w nagłówku X-Locale, więc komunikaty z serwera wracają w jego języku.
 */
export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('X-Locale', currentLocale())

  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  const response = await fetch(`${API_URL}${path}`, { ...init, headers })

  if (!response.ok) {
    const body = (await response.json().catch(() => null)) as { message?: string; code?: string } | null
    throw new ApiError(response.status, body?.message ?? response.statusText, body?.code)
  }

  return (await response.json()) as T
}
