import type { Router } from 'vue-router'

import { useSessionStore } from '@/stores/session'

/**
 * Keeps visitors out of the game and drivers out of the sign-up screens.
 * Pinia must be installed before the first navigation.
 */
export function installGuards(router: Router): Router {
  router.beforeEach((to) => {
    const session = useSessionStore()

    if (to.meta.requiresAuth && !session.isAuthenticated) {
      return { name: 'landing' }
    }

    if (to.meta.guestOnly && session.isAuthenticated) {
      return { name: 'garage' }
    }

    return true
  })

  return router
}
