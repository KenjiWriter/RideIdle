import { computed } from 'vue'

import { useRunStore } from '@/stores/run'

/**
 * What state the car of the driver is in, for the screens that show it: a towed car is running, but its start is
 * still in the future (the wait of the tow), and nothing else puts the cursor of a run ahead of the clock of the server.
 */
export function useRunStatus() {
  const store = useRunStore()

  /** How long the tow still waits, by the clock of the server at the last answer. */
  const towMs = computed(() => (store.run && store.run.status === 'running' ? Math.max(0, store.run.cursor_ms - store.run.server_time_ms) : 0))
  const towing = computed(() => towMs.value > 0)

  /** Why the car stands, that it is being towed, or that it is racing: the key of the status in the messages. */
  const statusKey = computed(() => (towing.value ? 'towed' : (store.run?.stop_reason ?? 'running')))
  const stopped = computed(() => store.run?.status === 'stopped')

  /** The class of the chip that says it: red for a car that needs the driver, amber for one that waits, cyan for one that races. */
  const chipClass = computed(() => (stopped.value && store.run?.stop_reason !== 'workshop' ? 'chip-bad' : stopped.value || towing.value ? 'chip-am' : 'chip-cy'))

  return { towMs, towing, statusKey, stopped, chipClass }
}
