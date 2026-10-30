import { onBeforeUnmount, onMounted } from 'vue'

import { useMissionsStore } from '@/stores/missions'

/** How often a screen of the game asks the server for the missions: they are counted when somebody looks, and the number at the top of the screen should be true. */
export const MISSIONS_POLL_MS = 60_000

/** The missions are asked for at the earliest this long after the last time, so that a driver who comes back at once does not make a storm of questions. */
const MIN_GAP_MS = 5_000

/**
 * Keeps the number of missions that can be taken true on every screen of the game: the server counts the missions when it is asked, so it is asked every minute while
 * the page is in front, and when the page comes back to the front. A game with no missions (the first answer says so) is not asked again.
 */
export function useMissionsSync() {
  const missions = useMissionsStore()
  let timer: ReturnType<typeof setInterval> | undefined
  let lastAsk = 0

  async function refresh(): Promise<void> {
    const now = Date.now()

    if (document.hidden || now - lastAsk < MIN_GAP_MS || (missions.asked && missions.view !== null && !missions.available && missions.locked === null)) {
      return
    }

    lastAsk = now

    try {
      await missions.load()
    } catch {
      // The next question is a minute away.
    }
  }

  const onBack = (): void => {
    void refresh()
  }

  onMounted(() => {
    void refresh()
    timer = setInterval(() => void refresh(), MISSIONS_POLL_MS)
    document.addEventListener('visibilitychange', onBack)
    window.addEventListener('focus', onBack)
  })

  onBeforeUnmount(() => {
    clearInterval(timer)
    document.removeEventListener('visibilitychange', onBack)
    window.removeEventListener('focus', onBack)
  })

  return { refresh }
}
