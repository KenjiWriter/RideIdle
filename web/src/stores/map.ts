import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '@/api/client'
import type { FinishTravelResponse, MapResponse, PoliceChoice, PoliceChoiceResponse } from '@/api/types'
import { intentionIds } from '@/features/run/intentionIds'
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
  /** The premium coins of the driver as the last answer said (0 until one did). */
  const premiumCoins = ref(0)
  /** When an answer last said it (the time of this device), 0 until one did. */
  const premiumSeenAt = ref(0)
  const finishIds = intentionIds(10 * 60_000)

  /** What time it is for the server now. */
  function serverNow(): number {
    return Date.now() + clockOffsetMs.value
  }

  function apply(response: MapResponse): void {
    map.value = response.data
    premiumCoins.value = response.premium_coins ?? premiumCoins.value
    premiumSeenAt.value = response.premium_coins === undefined ? premiumSeenAt.value : Date.now()
    clockOffsetMs.value = response.now_ms - Date.now()
    loaded.value = true
  }

  async function load(): Promise<void> {
    apply(await api<MapResponse>('/v1/map'))
  }

  /** Sends the car to a city, faster (`boost`: it burns more fuel and the police may stop it). The run is asked for again: it stands on the road now, and its fuel is lower. */
  async function travel(to: string, boost = false): Promise<void> {
    apply(await api<MapResponse>('/v1/travel', { method: 'POST', body: JSON.stringify({ to, ...(boost ? { boost: true } : {}) }) }))

    useRunStore()
      .refresh()
      .catch(() => undefined)
  }

  /** Brings the car to the city it is going to, now, for premium coins (`travel.speedup.coins`). A request sent again charges once. Returns the coins this call spent. */
  async function finishTravel(): Promise<number> {
    const response = await api<FinishTravelResponse>('/v1/travel/finish', { method: 'POST', body: JSON.stringify({ request_id: finishIds.forKey('travel') }) })

    finishIds.answered()
    apply(response)

    useRunStore()
      .refresh()
      .catch(() => undefined)

    return response.spent_coins
  }

  /** What the driver chooses when the police have stopped the car: pay the fine, give a bribe or have a lawyer. Returns what came of it. */
  async function decidePolice(choice: PoliceChoice): Promise<PoliceChoiceResponse['police']> {
    const response = await api<PoliceChoiceResponse>('/v1/travel/police', { method: 'POST', body: JSON.stringify({ choice }) })

    apply(response)

    return response.police
  }

  return { map, loaded, premiumCoins, premiumSeenAt, serverNow, load, travel, finishTravel, decidePolice }
})
