import type { SocialProvider } from '@/api/types'

const STORAGE_KEY = 'ride.oauth'

/** How long the driver has at the provider. The server forgets the sign-in after 10 minutes. */
const MAX_AGE_MS = 15 * 60 * 1000

/** A sign-in with a provider that this browser started and has not finished. */
export interface PendingSignIn {
  provider: SocialProvider
  /** Kept until the browser comes back from the provider: it proves this is the browser that started. */
  verifier: string
  at: number
}

/**
 * Remembers the verifier over the trip to the provider and back. It stays in this browser only; if storage is
 * blocked the sign-in cannot be finished, and finishing it fails with a message instead of linking anything.
 */
export function storePending(provider: SocialProvider, verifier: string): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ provider, verifier, at: Date.now() } satisfies PendingSignIn))
  } catch {
    // Without storage the way back from the provider has nothing to prove who started.
  }
}

export function readPending(): PendingSignIn | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    const pending = raw ? (JSON.parse(raw) as Partial<PendingSignIn>) : null

    if (
      pending &&
      typeof pending.verifier === 'string' &&
      typeof pending.provider === 'string' &&
      typeof pending.at === 'number' &&
      Date.now() - pending.at <= MAX_AGE_MS
    ) {
      return pending as PendingSignIn
    }

    return null
  } catch {
    return null
  }
}

export function clearPending(): void {
  try {
    localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Nothing to clear.
  }
}
