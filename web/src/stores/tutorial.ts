import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api } from '@/api/client'
import type { TutorialResponse } from '@/api/types'
import { useRunStore } from '@/stores/run'

/**
 * Where the driver is in the tutorial. The server decides: it brings the progress up to date whenever it is asked,
 * from the state of the game, so the store only has to ask, and to ask again when the run moves.
 */
export const useTutorialStore = defineStore('tutorial', () => {
  const tutorial = ref<TutorialResponse['data'] | null>(null)
  /** What the last answer paid, in cents, until the screen has said it. */
  const reward = ref<number | null>(null)
  /** The server was asked at least once, whatever it answered: a screen that waits for the phone waits for this. */
  const asked = ref(false)

  /** The step that waits, while the tutorial is on. */
  const current = computed(() => (tutorial.value?.status === 'in_progress' ? tutorial.value.current : null))
  /** The phone is out: the driver has not answered the messages yet. */
  const showing = computed(() => current.value !== null && !current.value.acknowledged)
  /** The messages are answered, and the step waits for the driver to do the thing it asked. */
  const waiting = computed(() => current.value !== null && current.value.acknowledged)

  function apply(response: TutorialResponse): void {
    tutorial.value = response.data

    if (response.data.rewarded.length > 0) {
      reward.value = response.data.rewarded.reduce((sum, paid) => sum + paid.cash_cents, 0)

      // The cash is in the HUD: ask for it again.
      useRunStore()
        .refresh()
        .catch(() => undefined)
    }
  }

  async function load(): Promise<void> {
    try {
      apply(await api<TutorialResponse>('/v1/tutorial'))
    } finally {
      asked.value = true
    }
  }

  async function reply(): Promise<void> {
    apply(await api<TutorialResponse>('/v1/tutorial/reply', { method: 'POST' }))
  }

  async function skip(): Promise<void> {
    apply(await api<TutorialResponse>('/v1/tutorial/skip', { method: 'POST' }))
  }

  function acknowledgeReward(): void {
    reward.value = null
  }

  /** Forgets everything, for example when the driver signs out. */
  function reset(): void {
    tutorial.value = null
    reward.value = null
    asked.value = false
  }

  return { tutorial, reward, asked, current, showing, waiting, load, reply, skip, acknowledgeReward, reset }
})
