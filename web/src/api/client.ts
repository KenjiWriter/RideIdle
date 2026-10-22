import { readToken } from '@/auth/token'
import { currentLocale } from '@/i18n'

const API_URL: string = import.meta.env.VITE_API_URL ?? '/api'

/** Validation messages from the API, keyed by field name. */
export type FieldErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly code: string | undefined
  readonly errors: FieldErrors

  constructor(status: number, message: string, code?: string, errors: FieldErrors = {}) {
    super(message)
    this.status = status
    this.code = code
    this.errors = errors
  }

  /** The first validation message for a field, if the API sent one. */
  firstError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

/**
 * Thin wrapper around fetch: always asks for JSON, sends the language of the
 * driver in X-Locale (so server messages come back in that language) and the
 * device token when there is one.
 */
export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('X-Locale', currentLocale())

  const token = readToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  const response = await fetch(`${API_URL}${path}`, { ...init, headers })

  if (!response.ok) {
    const body = (await response.json().catch(() => null)) as {
      message?: string
      code?: string
      errors?: FieldErrors
    } | null

    throw new ApiError(response.status, body?.message ?? response.statusText, body?.code, body?.errors)
  }

  return (await response.json()) as T
}
