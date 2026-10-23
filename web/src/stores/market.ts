import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '@/api/client'
import type { MarketResponse } from '@/api/types'
import { intentionIds } from '@/features/run/intentionIds'
import { useFleetStore } from '@/stores/fleet'
import { useRunStore } from '@/stores/run'

/** How long a request id is reused for the same purchase. Later, pressing the button again is a new intention. */
export const DEALER_ID_LIFETIME_MS = 60 * 1000

/**
 * The market of the city where the car the driver plays stands: the used-car exchange, the dealer of new cars and the wrecks of the yard.
 * The server makes the listings of the exchange again, the same, for as long as a refresh lasts, so the store only has to ask, and to count
 * the time until the next refresh with the clock of the server (the offset of the last answer), not with its own.
 */
export const useMarketStore = defineStore('market', () => {
  const market = ref<MarketResponse['data'] | null>(null)
  const cash = ref<MarketResponse['wallet'] | null>(null)
  const loaded = ref(false)
  /** The clock of the server minus the clock of this device, as it was at the last answer. */
  const clockOffsetMs = ref(0)
  const dealerIds = intentionIds(DEALER_ID_LIFETIME_MS)

  /** What time it is for the server now. */
  function serverNow(): number {
    return Date.now() + clockOffsetMs.value
  }

  function apply(response: MarketResponse): void {
    market.value = response.data
    cash.value = response.wallet
    clockOffsetMs.value = response.now_ms - Date.now()
    loaded.value = true
  }

  /** The cash changed (and the run is asked for again, as it shows it in the HUD). A failed ask is not worth a message, the next one tries again. */
  function syncRun(): void {
    useRunStore()
      .refresh()
      .catch(() => undefined)
  }

  async function load(): Promise<void> {
    apply(await api<MarketResponse>('/v1/market'))
  }

  /** Pays for the inspection of a car of the exchange: from then on its condition is shown. */
  async function inspect(key: string): Promise<void> {
    apply(await api<MarketResponse>(`/v1/market/exchange/${encodeURIComponent(key)}/inspect`, { method: 'POST' }))
    syncRun()
  }

  /** Buys a car of the exchange into the garage of the city. A car that was bought before is bought once: the answer says `repeated`. Returns the id of the car, null when it was bought before. */
  async function buyListing(key: string): Promise<number | null> {
    const response = await api<MarketResponse>(`/v1/market/exchange/${encodeURIComponent(key)}/buy`, { method: 'POST' })

    apply(response)
    syncRun()

    return response.bought ?? null
  }

  /**
   * Buys a new car from the dealer. A request that was lost on its way and is sent again charges once: the server answers with `repeated`.
   * Returns the id of the car, null when it was bought before.
   */
  async function buyNew(vehicle: string): Promise<number | null> {
    const response = await api<MarketResponse>('/v1/market/dealer', {
      method: 'POST',
      body: JSON.stringify({ vehicle, request_id: dealerIds.forKey(vehicle) }),
    })

    dealerIds.answered()
    apply(response)
    syncRun()

    return response.bought ?? null
  }

  /** Buys a wreck of the yard into the garage of the city of the market: the fleet buys it, and the market is asked for again. */
  async function buyWreck(vehicle: string): Promise<number | null> {
    const city = market.value?.city.slug

    if (!city) {
      throw new Error('The city of the market is not known: load the market first.')
    }

    const bought = await useFleetStore().buyCar(vehicle, city)

    await load()

    return bought
  }

  return { market, cash, loaded, serverNow, load, inspect, buyListing, buyNew, buyWreck }
})
