import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api } from '@/api/client'
import type { PremiumPurchase, PremiumView } from '@/api/types'
import { intentionIds } from '@/features/run/intentionIds'

/** GET /api/v1/premium: the balance for the bar at the top, and the paid extras (what the game sells of them and what the driver has) for the screen that sells them. */
interface PremiumResponse {
  data: PremiumView
}

/** How long a request id of a purchase is kept: a request whose answer was lost is sent again within it and charges once. */
const PURCHASE_ID_LIFETIME_MS = 60_000

/** The premium coins of the driver, and the three paid extras they can have: the auto-refill, the VIP account and the pass of the endless refill. */
export const usePremiumStore = defineStore('premium', () => {
  const balance = ref(0)
  /** When the balance was read (the time of this device), 0 until it was. */
  const seenAt = ref(0)
  /** Whether the balance was asked for: once is enough, so a server that has no such call is not asked on every screen. */
  const asked = ref(false)
  /** What the server said of the extras the last time it was asked, or null until then. */
  const view = ref<PremiumView | null>(null)
  /** The clock of this device when the view was read, to tell how much of a time is left now. */
  const viewAt = ref(0)

  const refillIds = intentionIds(PURCHASE_ID_LIFETIME_MS)
  const vipIds = intentionIds(PURCHASE_ID_LIFETIME_MS)

  const autorefill = computed(() => view.value?.autorefill ?? null)
  const vip = computed(() => view.value?.vip ?? null)
  const infinity = computed(() => view.value?.infinity ?? null)

  /** The clock of the server now, worked from the one it told and the time that has passed on this device since. */
  function serverNow(at: number = Date.now()): number {
    return view.value?.now_ms === undefined ? at : view.value.now_ms + (at - viewAt.value)
  }

  /** How much of an account is left at a moment of the clock of the server (now when left out), in ms: 0 when it has ended, or was never bought. */
  function remainingMs(untilMs: number | null | undefined, at: number = serverNow()): number {
    return untilMs === null || untilMs === undefined ? 0 : Math.max(0, untilMs - at)
  }

  /** Whether the VIP account is on at a moment of the clock of the server: the game honours it, and it has not ended. A screen with a clock of its own asks with it, so a badge goes out at the end without a call. */
  function vipActiveAt(at: number): boolean {
    return vip.value !== null && vip.value.enabled && remainingMs(vip.value.until_ms, at) > 0
  }

  function infinityActiveAt(at: number): boolean {
    return infinity.value !== null && infinity.value.enabled && remainingMs(infinity.value.until_ms, at) > 0
  }

  const vipActive = computed(() => vipActiveAt(serverNow()))
  const infinityActive = computed(() => infinityActiveAt(serverNow()))

  /** The tank is filled for the driver: the game does it, they want it, and there is a charge or a pass to pay for it. */
  const autoRefillOn = computed(() => autorefill.value !== null && autorefill.value.enabled && autorefill.value.preference && (autorefill.value.stock > 0 || infinityActive.value))

  /** Takes an answer of the server as the state of the shop: the balance of the bar at the top and the extras. */
  function apply(next: PremiumView): void {
    view.value = next
    balance.value = next.balance
    viewAt.value = Date.now()
    seenAt.value = viewAt.value
  }

  /** The call that is on its way, so that two screens that ask together (the bar at the top and the shop) make one. */
  let pending: Promise<void> | null = null

  function load(): Promise<void> {
    asked.value = true
    pending ??= api<PremiumResponse>('/v1/premium')
      .then((response) => apply(response.data))
      .finally(() => {
        pending = null
      })

    return pending
  }

  /** Reads the extras once: the bar at the top shows their badges on every screen, and a server that has no such call is not asked again. */
  async function ensure(): Promise<void> {
    if (view.value === null && !asked.value) {
      await load().catch(() => undefined)
    }
  }

  /** Buys a pack of charges for coins. Returns the coins this call spent (0 when the same request had been done). */
  async function buyRefills(pack: string): Promise<{ coins: number; charges: number; repeated: boolean }> {
    const response = await api<PremiumPurchase>('/v1/premium/autorefill', {
      method: 'POST',
      body: JSON.stringify({ pack, request_id: refillIds.forKey(pack) }),
    })

    refillIds.answered()
    apply(response.data)

    return { coins: response.spent_coins ?? 0, charges: response.bought_charges ?? 0, repeated: response.repeated ?? false }
  }

  /** Buys the VIP account for coins, or extends it. Returns the coins this call spent (0 when the same request had been done). */
  async function buyVip(): Promise<{ coins: number; repeated: boolean }> {
    const response = await api<PremiumPurchase>('/v1/premium/vip', {
      method: 'POST',
      body: JSON.stringify({ request_id: vipIds.forKey('vip') }),
    })

    vipIds.answered()
    apply(response.data)

    return { coins: response.spent_coins ?? 0, repeated: response.repeated ?? false }
  }

  /** The driver's wish to have the tank filled for them. */
  async function setAutoRefill(enabled: boolean): Promise<void> {
    const response = await api<PremiumPurchase>('/v1/premium/autorefill', { method: 'PATCH', body: JSON.stringify({ enabled }) })

    apply(response.data)
  }

  return {
    balance,
    seenAt,
    asked,
    view,
    autorefill,
    vip,
    infinity,
    vipActive,
    infinityActive,
    autoRefillOn,
    serverNow,
    remainingMs,
    vipActiveAt,
    infinityActiveAt,
    apply,
    load,
    ensure,
    buyRefills,
    buyVip,
    setAutoRefill,
  }
})
