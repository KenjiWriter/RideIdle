import { onBeforeUnmount, ref } from 'vue'

/** How long the result of an action stays on screen: long enough to read, short enough not to go stale. */
export const FLASH_MS = 8_000

/**
 * A message that goes away by itself, like "Added 30 l for $60.00": it is true for the moment of the action and
 * would mislead a driver who comes back to it an hour later. Showing a new one restarts the time.
 */
export function useFlash(visibleMs = FLASH_MS) {
  const message = ref<string | null>(null)
  let timer: ReturnType<typeof setTimeout> | undefined

  function clear(): void {
    clearTimeout(timer)
    message.value = null
  }

  function show(text: string): void {
    clearTimeout(timer)
    message.value = text
    timer = setTimeout(() => (message.value = null), visibleMs)
  }

  onBeforeUnmount(() => clearTimeout(timer))

  return { message, show, clear }
}
