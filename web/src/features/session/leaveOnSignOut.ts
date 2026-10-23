import { watch } from 'vue'
import { useRouter } from 'vue-router'

import { useSessionStore } from '@/stores/session'

/**
 * Sends the driver back to the start when the session ends while a screen is open, whether they signed out or
 * the server no longer knows the token.
 */
export function useLeaveOnSignOut(): void {
  const router = useRouter()
  const session = useSessionStore()

  watch(
    () => session.isAuthenticated,
    (signedIn) => {
      if (!signedIn) {
        void router.push({ name: 'landing' })
      }
    },
  )
}
