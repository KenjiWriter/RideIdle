import { onBeforeUnmount, onMounted, watch } from 'vue'

import { useRunStore } from '@/stores/run'
import { useTutorialStore } from '@/stores/tutorial'

/** How often a screen of the game asks the server where the tutorial is, while a step is on: a step is done in the game, and the server brings the tutorial up to date only when it is asked. */
export const TUTORIAL_POLL_MS = 8_000

/** The tutorial is asked for at the earliest this long after the last time, so that a run that moves and a driver who comes back at once do not make a storm of questions. */
const MIN_GAP_MS = 2_000

/**
 * Keeps the goal on the screen true without the driver having to go to another tab for it: the tutorial is asked again every few seconds while a step is on and the page
 * is in front, when the page comes back to the front, and when the run moves (a refuel, a lap that was paid). Without it the goal of a step that was done (an upgrade that
 * the workshop has finished, a service that was paid) stayed on the screen until the driver went to another screen, which asked the server again.
 */
export function useTutorialSync() {
  const tutorial = useTutorialStore()
  const run = useRunStore()
  let timer: ReturnType<typeof setInterval> | undefined
  let lastAsk = 0
  let asking = false

  /** Whether a step is on: with none (the tutorial is over, skipped, or not yet known) there is nothing to keep up to date. */
  const stepIsOn = (): boolean => tutorial.current !== null || tutorial.goal !== null

  async function refresh(): Promise<void> {
    const now = Date.now()

    if (asking || document.hidden || !stepIsOn() || now - lastAsk < MIN_GAP_MS) {
      return
    }

    asking = true
    lastAsk = now

    try {
      await tutorial.load()
    } catch {
      // The next question is only a few seconds away.
    } finally {
      asking = false
    }
  }

  const onBack = (): void => {
    void refresh()
  }

  onMounted(() => {
    timer = setInterval(() => void refresh(), TUTORIAL_POLL_MS)
    document.addEventListener('visibilitychange', onBack)
    window.addEventListener('focus', onBack)
  })

  onBeforeUnmount(() => {
    clearInterval(timer)
    document.removeEventListener('visibilitychange', onBack)
    window.removeEventListener('focus', onBack)
  })

  // A run that moved may have done what the step asked (the tank is full, a lap was paid).
  watch(
    () => run.run?.state_version,
    (version, before) => {
      if (before !== undefined && version !== before) {
        void refresh()
      }
    },
  )

  return { refresh }
}
