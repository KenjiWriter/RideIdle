const STORAGE_KEY = 'ride.token'

/**
 * The device token of the driver. It lives in local storage so the session
 * survives a reload; every access is guarded because private mode or blocked
 * site data can make storage throw.
 */
export function readToken(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

export function storeToken(token: string): void {
  try {
    localStorage.setItem(STORAGE_KEY, token)
  } catch {
    // The session works until the page is closed, it just cannot be remembered.
  }
}

export function clearToken(): void {
  try {
    localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Nothing to clear.
  }
}
