const STORAGE_KEY = 'ride.linkPrompt'

/**
 * Whether the driver told the window "Save your garage" to wait. After that only a quiet banner reminds them, so
 * the window is not thrown at them again on every visit. Guarded like the token: storage may throw.
 */
export function readLinkPromptDismissed(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) !== null
  } catch {
    return false
  }
}

export function storeLinkPromptDismissed(): void {
  try {
    localStorage.setItem(STORAGE_KEY, String(Date.now()))
  } catch {
    // The window may come back after a reload; nothing else is lost.
  }
}

export function clearLinkPromptDismissed(): void {
  try {
    localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Nothing to clear.
  }
}
