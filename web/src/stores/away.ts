import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api } from '@/api/client'
import type { AwayAccount, AwayResponse } from '@/api/types'

/**
 * What happened to the whole account while the driver was away: what the other cars earned, the jobs of the workshop that were done, the journeys that ended.
 * The card of the garage tells the same of the car that is played; this is the rest, so it is shown only when there is something beyond that car. A break that
 * has nothing to tell of is over at once, so that the next one starts a summary of its own.
 */
export const useAwayStore = defineStore('away', () => {
  const summary = ref<AwayAccount | null>(null)

  /** Whether there is anything beyond the car that is played. */
  const visible = computed(() => {
    const away = summary.value

    return away !== null && ((away.cars ?? []).some((car) => !car.played) || (away.jobs ?? []).length > 0 || (away.journeys ?? []).length > 0 || (away.missions ?? null) !== null)
  })

  async function load(): Promise<void> {
    const response = await api<AwayResponse>('/v1/away')

    summary.value = response.data.pending ? response.data : null

    if (summary.value !== null && !visible.value) {
      await dismiss()
    }
  }

  /** The driver has seen it: the break is over. */
  async function dismiss(): Promise<void> {
    summary.value = null

    await api<AwayResponse>('/v1/away/seen', { method: 'POST' })
  }

  return { summary, visible, load, dismiss }
})
