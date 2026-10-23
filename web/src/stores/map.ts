import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '@/api/client'
import type { MapResponse } from '@/api/types'
import { useRunStore } from '@/stores/run'

/**
 * The map of the cities: where the car is, what each city asks and costs to get to, and the journey on the way. The server brings a
 * journey to its end whenever it is asked, so the store only has to ask, and to count the time of the journey with the clock of the
 * server (the offset of the last answer), not with its own.
 */
export const useMapStore = defineStore('map', () => {
  const map = ref<MapResponse['data'] | null>(null)
  const loaded = ref(false)
  /** The clock of the server minus the clock of this device, as it was at the last answer. */
  const clockOffsetMs = ref(0)

  /** What time it is for the server now. */
  function serverNow(): number {
    return Date.now() + clockOffsetMs.value
  }

  function apply(response: MapResponse): void {
    map.value = response.data
    clockOffsetMs.value = response.now_ms - Date.now()
    loaded.value = true
  }

  async function load(): Promise<void> {
    apply(await api<MapResponse>('/v1/map'))
  }

  /** Sends the car to a city. The run is asked for again: it stands on the road now, and its fuel is lower. */
  async function travel(to: string): Promise<void> {
    apply(await api<MapResponse>('/v1/travel', { method: 'POST', body: JSON.stringify({ to }) }))

    useRunStore()
      .refresh()
      .catch(() => undefined)
  }

  return { map, loaded, serverNow, load, travel }
})
