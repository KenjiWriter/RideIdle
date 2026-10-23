import { onBeforeUnmount, onMounted } from 'vue'

import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { useRunStore } from '@/stores/run'
import { useSessionStore } from '@/stores/session'

/** How often an open run screen asks the server for the state of the run. */
export const POLL_MS = 15_000

/**
 * Keeps the run on an open screen up to date. It asks while the page is visible, once more as soon as the driver
 * comes back to the tab, and never twice at the same time. A hidden tab asks nothing: the server settles
 * everything the moment somebody looks again.
 *
 * A guest is asked about as well: the account may have been secured from another tab or device, with the link of an
 * e-mail, and the window that asks for it should not stay open over a garage that is already saved.
 */
export function useRunPolling(): void {
  const run = useRunStore()
  const session = useSessionStore()
  let timer: ReturnType<typeof setInterval> | undefined
  let asking = false

  async function poll(): Promise<void> {
    if (document.hidden || asking) {
      return
    }

    asking = true

    try {
      await run.refresh()

      if (session.me?.is_guest) {
        await session.fetchMe()
      }
    } catch (failure) {
      // A failed poll is not worth a message: the next one tries again. Only an unknown device ends the session.
      endSessionIfUnauthorized(failure)
    } finally {
      asking = false
    }
  }

  function onVisibilityChange(): void {
    if (!document.hidden) {
      void poll()
    }
  }

  onMounted(() => {
    timer = setInterval(() => void poll(), POLL_MS)
    document.addEventListener('visibilitychange', onVisibilityChange)
  })

  onBeforeUnmount(() => {
    clearInterval(timer)
    document.removeEventListener('visibilitychange', onVisibilityChange)
  })
}
