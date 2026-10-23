import { ApiError } from '@/api/client'
import { useSessionStore } from '@/stores/session'

/**
 * A 401 means the server no longer knows this device: the session ends, and a screen that watches it
 * (useLeaveOnSignOut) takes the driver back to the start. Returns true when the failure was that.
 */
export function endSessionIfUnauthorized(failure: unknown): boolean {
  if (failure instanceof ApiError && failure.status === 401) {
    useSessionStore().signOut()

    return true
  }

  return false
}
