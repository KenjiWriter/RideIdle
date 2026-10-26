import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '@/api/client'
import type { FleetResponse } from '@/api/types'
import { newRequestId } from '@/features/run/requestId'
import { useGarageStore } from '@/stores/garage'
import { useMapStore } from '@/stores/map'
import { useRunStore } from '@/stores/run'

/** How long a request id is reused for the same purchase. Later, pressing the button again is a new intention. */
export const WRECK_ID_LIFETIME_MS = 60 * 1000

/**
 * The cars of the driver and the garages they stand in. Every car drives by itself on the track of its city; the driver plays one,
 * the selected one, which is the car of the garage, the workshop and the race. The server settles every car whenever it is asked for
 * the list, so the store only has to ask, and to count the time with the clock of the server (the offset of the last answer).
 */
export const useFleetStore = defineStore('fleet', () => {
  const fleet = ref<FleetResponse['data'] | null>(null)
  const cash = ref<FleetResponse['wallet'] | null>(null)
  const loaded = ref(false)
  /** The clock of the server minus the clock of this device, as it was at the last answer. */
  const clockOffsetMs = ref(0)

  /** What time it is for the server now. */
  function serverNow(): number {
    return Date.now() + clockOffsetMs.value
  }

  function apply(response: FleetResponse): void {
    fleet.value = response.data
    cash.value = response.wallet
    clockOffsetMs.value = response.now_ms - Date.now()
    loaded.value = true
  }

  /** The cash and the run of the selected car changed: they are asked for again. A failed ask is not worth a message, the next one tries again. */
  function syncRun(): void {
    useRunStore()
      .refresh()
      .catch(() => undefined)
  }

  /** Another car is the one the driver plays: the screens that show the car of the driver ask for it again. */
  function syncSelected(): void {
    syncRun()
    useGarageStore()
      .loadGarage()
      .catch(() => undefined)
    useMapStore()
      .load()
      .catch(() => undefined)
  }

  async function load(): Promise<void> {
    apply(await api<FleetResponse>('/v1/cars'))
  }

  /** The driver plays this car from now on. */
  async function select(carId: number): Promise<void> {
    apply(await api<FleetResponse>(`/v1/cars/${carId}/select`, { method: 'POST' }))
    syncSelected()
  }

  /**
   * Sells a car: the driver is paid and the car is gone from the garage. The car they play may be the one that went, so the screens that show
   * it ask for it again. Returns what the driver was paid, in cents.
   */
  async function sell(carId: number): Promise<number> {
    const response = await api<FleetResponse>(`/v1/cars/${carId}/sell`, { method: 'POST' })

    apply(response)
    syncSelected()

    return response.sold?.cents ?? 0
  }

  /** Buys a garage in a city. */
  async function buyGarage(city: string): Promise<void> {
    apply(await api<FleetResponse>('/v1/garages', { method: 'POST', body: JSON.stringify({ city }) }))
    syncRun()
  }

  let pendingWreck: { key: string; id: string; at: number } | null = null

  /** The request id of the purchase that is on its way: the same one for the same wreck into the same city, until it is answered. */
  function wreckRequest(key: string): string {
    if (pendingWreck === null || pendingWreck.key !== key || Date.now() - pendingWreck.at > WRECK_ID_LIFETIME_MS) {
      pendingWreck = { key, id: newRequestId(), at: Date.now() }
    }

    return pendingWreck.id
  }

  /**
   * Buys a wreck from the yard into the garage of a city. A request that was lost on its way and is sent again charges once: the server
   * answers with `repeated`. Returns the id of the car that was bought, null when it was bought before.
   */
  async function buyCar(vehicle: string, city: string): Promise<number | null> {
    const response = await api<FleetResponse>('/v1/cars', {
      method: 'POST',
      body: JSON.stringify({ vehicle, city, request_id: wreckRequest(`${vehicle}:${city}`) }),
    })

    pendingWreck = null
    apply(response)
    syncRun()

    return response.bought ?? null
  }

  return { fleet, cash, loaded, serverNow, load, select, sell, buyGarage, buyCar }
})
