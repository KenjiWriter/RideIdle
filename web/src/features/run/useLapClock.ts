import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ComputedRef } from 'vue'

import { formatCountdown, lapProgress } from '@/features/run/lapProgress'
import { useRunStore } from '@/stores/run'

/** How often the countdown is drawn again. */
const TICK_MS = 250

/** The time after the end of a lap at which the server is asked what the lap paid: a moment later, so the lap is surely over there. */
const LAP_END_GRACE_MS = 400

/** The lap in progress: which lap of the run it is, and how long it still lasts as "0:41". */
export interface LapNow {
  number: number
  time: string
}

/**
 * The lap in progress, counted by the clock of the server, for the navigation that every screen of the game has: null when no lap is
 * in progress (the car stands). It also asks the server what the lap paid as soon as the lap is over, so the cash, the laps and the
 * lap that follows are right at that moment on any screen, not at the next poll.
 *
 * A lap that has ended goes round again on its own until the answer says what really happened (see `lapProgress`).
 */
export function useLapClock(): ComputedRef<LapNow | null> {
  const run = useRunStore()
  const now = ref(run.serverNow())
  let ticker: ReturnType<typeof setInterval> | undefined
  let lapTimer: ReturnType<typeof setTimeout> | undefined

  onMounted(() => {
    now.value = run.serverNow()
    ticker = setInterval(() => (now.value = run.serverNow()), TICK_MS)
  })

  onBeforeUnmount(() => {
    clearInterval(ticker)
    clearTimeout(lapTimer)
  })

  watch(
    () => run.run?.current_lap?.finish_ms,
    (finish) => {
      clearTimeout(lapTimer)

      if (finish !== undefined) {
        lapTimer = setTimeout(() => void run.refresh().catch(() => undefined), Math.max(0, finish - run.serverNow()) + LAP_END_GRACE_MS)
      }
    },
    { immediate: true },
  )

  return computed(() => {
    const current = run.run?.current_lap

    if (!run.run || !current) {
      return null
    }

    const progress = lapProgress(current, now.value)

    return { number: run.run.laps_done + 1 + progress.lapsPassed, time: formatCountdown(progress.remainingMs) }
  })
}
