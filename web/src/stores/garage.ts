import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '@/api/client'
import type { CancelJobResponse, CrateKind, CrateOpenResponse, GarageResponse, JobKind, OpenedCrate, PurchaseResponse, ShopCrates, ShopOffer, ShopResponse, Wallet, WorkshopJob } from '@/api/types'
import { intentionIds } from '@/features/run/intentionIds'
import { newRequestId } from '@/features/run/requestId'
import { useRunStore } from '@/stores/run'

/** How long a request id is reused for the same purchase. Later, pressing the button again is a new intention. */
export const PURCHASE_ID_LIFETIME_MS = 60 * 1000

/**
 * The garage of the driver: the car with its parts, the inventory and the queue of the workshop, and the shop.
 * The server carries out the jobs of the workshop whenever it is asked, so the store only has to ask, and to
 * count the time of the jobs with the clock of the server (the offset of the last answer), not with its own.
 */
export const useGarageStore = defineStore('garage', () => {
  const garage = ref<GarageResponse['data'] | null>(null)
  /** The server was asked about the garage at least once. */
  const loaded = ref(false)
  /** The clock of the server minus the clock of this device, as it was at the last answer. */
  const clockOffsetMs = ref(0)
  /**
   * The car rose a level since the garage was last asked about, and the tier of parts that opened, if one did. The screen
   * that says it takes it away, so the driver is told once, and also when they come back to the garage later.
   */
  const levelUp = ref<{ level: number; tier: number | null } | null>(null)

  const offers = ref<ShopOffer[]>([])
  const shopCar = ref<ShopResponse['data']['car']>(null)
  const shopLoaded = ref(false)
  /** The crates of the shop: the odds, the guarantees and what each kind costs. */
  const crates = ref<ShopCrates | null>(null)
  const crateIds = intentionIds(PURCHASE_ID_LIFETIME_MS)
  /** The cash of the driver as the shop last saw it. */
  const cash = ref<Wallet | null>(null)

  /** What time it is for the server now. */
  function serverNow(): number {
    return Date.now() + clockOffsetMs.value
  }

  function applyGarage(response: GarageResponse): void {
    const before = garage.value?.car ?? null
    const after = response.data.car

    // The first answer is not a rise: only a car that was seen at a lower level is said to have risen.
    if (before && after && before.id === after.id && after.level > before.level) {
      const opened = before.tier_unlock && after.tier_unlock && after.tier_unlock.tier > before.tier_unlock.tier

      levelUp.value = { level: after.level, tier: opened ? after.tier_unlock!.tier - 1 : null }
    }

    garage.value = response.data
    clockOffsetMs.value = response.now_ms - Date.now()
    loaded.value = true
  }

  async function loadGarage(): Promise<void> {
    applyGarage(await api<GarageResponse>('/v1/garage'))
  }

  async function loadShop(): Promise<void> {
    applyShop(await api<ShopResponse>('/v1/shop'))
  }

  function applyShop(response: Pick<ShopResponse, 'data' | 'wallet'>): void {
    offers.value = response.data.parts
    shopCar.value = response.data.car
    crates.value = response.data.crates
    cash.value = response.wallet
    shopLoaded.value = true
  }

  /** The cash and the state of the run changed: the run is asked for again. A failed ask is not worth a message, the next one tries again. */
  function syncRun(): void {
    useRunStore()
      .refresh()
      .catch(() => undefined)
  }

  let pendingPurchase: { part: string; id: string; at: number } | null = null

  /** The request id of the purchase that is on its way: the same one for the same part, until it is answered. */
  function purchaseRequest(part: string): string {
    if (pendingPurchase === null || pendingPurchase.part !== part || Date.now() - pendingPurchase.at > PURCHASE_ID_LIFETIME_MS) {
      pendingPurchase = { part, id: newRequestId(), at: Date.now() }
    }

    return pendingPurchase.id
  }

  /**
   * Buys a part into the inventory. A request that was lost on its way and is sent again charges once: the server
   * answers with `purchase.repeated`. The cash of the run is asked for again, so the garage shows what is left.
   */
  async function buy(slug: string): Promise<PurchaseResponse> {
    const response = await api<PurchaseResponse>('/v1/shop/purchases', {
      method: 'POST',
      body: JSON.stringify({ part: slug, request_id: purchaseRequest(slug) }),
    })

    pendingPurchase = null
    cash.value = response.wallet
    syncRun()

    return response
  }

  /**
   * Opens a crate: the part that was drawn goes to the inventory. `slot` is the slot the driver chose, for the kinds that have a choice. A request
   * that was lost on its way and is sent again opens one crate: the server answers with `repeated`. Returns the part, null when the crate was
   * opened before.
   */
  async function openCrate(kind: CrateKind, slot: string | null): Promise<OpenedCrate | null> {
    const response = await api<CrateOpenResponse>(`/v1/shop/crates/${kind}/open`, {
      method: 'POST',
      body: JSON.stringify({ request_id: crateIds.forKey(`${kind}:${slot ?? ''}`), ...(slot === null ? {} : { slot }) }),
    })

    crateIds.answered()
    applyShop(response)
    syncRun()

    return response.opened
  }

  /** Queues a job of the workshop: puts a part on the car, takes it off, upgrades it, or merges it with a second part of the inventory (`withId`). */
  async function queue(kind: JobKind, partId: number, withId?: number): Promise<void> {
    applyGarage(await api<GarageResponse>('/v1/garage/jobs', { method: 'POST', body: JSON.stringify({ kind, part: partId, ...(withId === undefined ? {} : { with: withId }) }) }))
    syncRun()
  }

  /**
   * Cancels a job of the workshop, one that waits or one that the mechanic is at. Nothing is paid or given back. A job that goes
   * only with it (the putting on of the part that was to replace the one taken off) is cancelled too. Returns the jobs that were
   * cancelled, the asked one first, as they stood in the queue.
   */
  async function cancelJob(jobId: number): Promise<WorkshopJob[]> {
    const before = garage.value?.workshop.jobs ?? []
    const response = await api<CancelJobResponse>(`/v1/garage/jobs/${jobId}`, { method: 'DELETE' })

    applyGarage(response)
    syncRun()

    return response.cancelled.flatMap((id) => before.find((job) => job.id === id) ?? [])
  }

  function acknowledgeLevelUp(): void {
    levelUp.value = null
  }

  return { garage, loaded, offers, shopCar, shopLoaded, crates, cash, levelUp, serverNow, loadGarage, loadShop, buy, openCrate, queue, cancelJob, acknowledgeLevelUp }
})
