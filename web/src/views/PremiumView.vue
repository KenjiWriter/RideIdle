<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import type { RefillPack } from '@/api/types'
import GameShell from '@/components/GameShell.vue'
import SpeedupButton from '@/components/SpeedupButton.vue'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatTimeLeft } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { usePremiumStore } from '@/stores/premium'

/**
 * The premium shop: the RideCoin the driver has, and what they buy with them or are given, the three paid extras. The auto-refill (charges, bought in packs, and the
 * wish to have the tank filled), the VIP account (more cash and XP for every lap, for a time) and the pass of the endless refill (sold for money, so it cannot be
 * bought here until a payment provider is connected). Every number is the server's: the rules of the game say what is sold and for how much.
 */
const { t } = useI18n()
const { number, fixed, price } = useFormat()
const premium = usePremiumStore()

useLeaveOnSignOut()

/** How often the time that is left of an account is worked out again. */
const TICK_MS = 30_000

const loadError = ref(false)
const loaded = ref(false)
const busy = ref(false)
const { message: done, show: showDone, clear: clearDone } = useFlash()
const error = ref<string | null>(null)
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | undefined

const view = computed(() => premium.view)
const refill = computed(() => premium.autorefill)
const vip = computed(() => premium.vip)
const pass = computed(() => premium.infinity)

/** What the driver has of an extra that the game has switched off (it is kept, and they can still see it), or the extra is on. */
const showRefill = computed(() => refill.value !== null && (refill.value.enabled || refill.value.stock > 0))
const showVip = computed(() => vip.value !== null && (vip.value.enabled || left(vip.value.until_ms) > 0))
const showPass = computed(() => pass.value !== null && (pass.value.enabled || left(pass.value.until_ms) > 0))
const nothing = computed(() => !showRefill.value && !showVip.value && !showPass.value)

/** The clock of the server now, worked out from the clock of this device, which the ticker reads again from time to time. */
const at = computed(() => premium.serverNow(now.value))
const vipOn = computed(() => premium.vipActiveAt(at.value))
const passOn = computed(() => premium.infinityActiveAt(at.value))

function left(untilMs: number | null | undefined): number {
  return premium.remainingMs(untilMs, at.value)
}

/** The time that is left, in words ("29 d 23 h"). */
function timeLeft(untilMs: number | null | undefined): string {
  return formatTimeLeft(left(untilMs))
}

function days(count: number): string {
  return t('premium.days', { n: count }, count)
}

function packText(charges: number): string {
  return t('premium.autorefill.pack', { n: charges }, charges)
}

/** What one refill costs in a pack, in RideCoin: whole when it is, with a decimal when it is not (a bigger pack is cheaper for each refill). */
function perRefill(pack: RefillPack): string {
  const each = pack.coins / pack.charges

  return Number.isInteger(each) ? number(each) : fixed(each, 1)
}

/** A pack that would take the stock over its cap is not sold: the server would refuse it whole. */
function tooBig(pack: RefillPack): boolean {
  return refill.value !== null && refill.value.stock + pack.charges > refill.value.stock_cap
}

async function open(): Promise<void> {
  loadError.value = false

  try {
    await premium.load()
    loaded.value = true
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = true
    }
  }
}

/** Runs one purchase or change: one at a time, and what the server refuses is said in the language of the driver. */
async function act(work: () => Promise<string | null>): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  clearDone()
  error.value = null

  try {
    const message = await work()

    if (message !== null) {
      showDone(message)
    }
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    error.value =
      failure instanceof ApiError && failure.status === 422
        ? failure.message
        : failure instanceof ApiError && failure.status === 429
          ? t('common.tooManyRequests')
          : t('common.error')

    // The server's word is the truth: what the screen shows is read again after a refusal (the stock or the coins may have changed).
    void premium.load().catch(() => undefined)
  } finally {
    busy.value = false
  }
}

function buyPack(pack: RefillPack): Promise<void> {
  return act(async () => {
    const bought = await premium.buyRefills(pack.slug)

    return bought.repeated ? t('premium.repeated') : t('premium.autorefill.bought', { pack: packText(bought.charges), coins: number(bought.coins) })
  })
}

function buyVip(): Promise<void> {
  return act(async () => {
    const bought = await premium.buyVip()

    return bought.repeated ? t('premium.repeated') : t('premium.vip.bought', { period: days(vip.value?.days ?? 0) })
  })
}

function switchRefill(event: Event): Promise<void> {
  const enabled = (event.target as HTMLInputElement).checked

  return act(async () => {
    await premium.setAutoRefill(enabled)

    return null
  })
}

onMounted(() => {
  void open()
  ticker = setInterval(() => (now.value = Date.now()), TICK_MS)
})

onBeforeUnmount(() => clearInterval(ticker))
</script>

<template>
  <GameShell>
    <header class="flex flex-col gap-1">
      <h1 class="ttl">{{ t('premium.title') }}</h1>
      <p class="sub">{{ t('premium.intro') }}</p>
    </header>

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('premium.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="open">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!loaded || view === null" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <section class="card card-cy flex flex-col gap-1 !p-3" data-test="balance">
        <p class="font-semibold text-ink" data-test="balance-line">{{ t('premium.balance', { count: number(view.balance) }) }}</p>
        <p class="sub">{{ t('premium.coinsNote') }}</p>
      </section>

      <div v-if="done" class="card card-cy !p-3" role="status" data-test="done">
        <p class="font-semibold">{{ done }}</p>
      </div>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>

      <p v-if="nothing" class="text-muted" data-test="nothing">{{ t('premium.nothing') }}</p>

      <!-- The auto-refill: the wish, the stock and the packs. -->
      <section v-if="showRefill && refill" class="card flex flex-col gap-3 !p-3" aria-labelledby="refill-title" data-test="autorefill">
        <div class="flex flex-col gap-1">
          <h2 id="refill-title" class="kick !mb-0">{{ t('premium.autorefill.title') }}</h2>
          <p class="sub">{{ t('premium.autorefill.text') }}</p>
        </div>

        <p class="font-semibold text-ink" data-test="refill-stock">{{ t('premium.autorefill.stock', { count: number(refill.stock), cap: number(refill.stock_cap) }) }}</p>
        <p v-if="passOn && refill.preference" class="text-sm text-ok" data-test="refill-pass">{{ t('premium.autorefill.passOn') }}</p>

        <p v-if="!refill.enabled" class="text-sm text-muted" data-test="refill-off-game">{{ t('premium.off') }}</p>

        <template v-else>
          <label class="flex items-center gap-3 text-ink" data-test="refill-switch-label">
            <input
              type="checkbox"
              role="switch"
              class="size-5 shrink-0 accent-amber"
              :checked="refill.preference"
              :disabled="busy"
              data-test="refill-switch"
              @change="switchRefill"
            />
            <span>{{ t('premium.autorefill.switch') }}</span>
          </label>
          <p class="sub" data-test="refill-state">{{ refill.preference ? t('premium.autorefill.on') : t('premium.autorefill.off') }}</p>

          <ul class="flex flex-col gap-2.5">
            <li v-for="pack in refill.packs" :key="pack.slug" class="flex flex-col gap-1.5 border-t border-line pt-2.5" :data-test="`pack-${pack.slug}`">
              <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <b class="text-base text-ink">{{ packText(pack.charges) }}</b>
                <span class="sub" data-test="pack-each">{{ t('premium.autorefill.each', { price: perRefill(pack) }) }}</span>
              </div>
              <p v-if="tooBig(pack)" class="sub" :data-test="`pack-${pack.slug}-full`">{{ t('premium.autorefill.full', { cap: number(refill.stock_cap) }) }}</p>
              <SpeedupButton
                v-else
                :coins="pack.coins"
                :balance="view.balance"
                :label="t('premium.autorefill.buy', { pack: packText(pack.charges) })"
                :disabled="busy"
                :test-id="`buy-${pack.slug}`"
                @confirm="buyPack(pack)"
              />
            </li>
          </ul>
        </template>
      </section>

      <!-- The VIP account. -->
      <section v-if="showVip && vip" class="card flex flex-col gap-2 !p-3" aria-labelledby="vip-title" data-test="vip">
        <div class="flex items-start justify-between gap-2">
          <h2 id="vip-title" class="kick !mb-0">{{ t('premium.vip.title') }}</h2>
          <span v-if="vipOn" class="tag tag-am" data-test="vip-status">{{ t('premium.vip.on', { left: timeLeft(vip.until_ms) }) }}</span>
          <span v-else class="tag" data-test="vip-status">{{ t('premium.vip.off') }}</span>
        </div>
        <p class="sub" data-test="vip-text">
          {{ t('premium.vip.text', { cash: number(vip.cash_bonus_ppm / 10_000), xp: number(vip.xp_bonus_ppm / 10_000) }) }}
        </p>
        <p v-if="!vip.enabled" class="text-sm text-muted" data-test="vip-off-game">{{ t('premium.off') }}</p>
        <SpeedupButton
          v-else
          :coins="vip.coins"
          :balance="view.balance"
          :label="vipOn ? t('premium.vip.extend', { period: days(vip.days) }) : t('premium.vip.buy', { period: days(vip.days) })"
          :disabled="busy"
          test-id="buy-vip"
          @confirm="buyVip"
        />
      </section>

      <!-- The pass of the endless refill: sold for money, so there is no button until a payment provider is connected. -->
      <section v-if="showPass && pass" class="card flex flex-col gap-2 !p-3" aria-labelledby="pass-title" data-test="pass">
        <div class="flex items-start justify-between gap-2">
          <h2 id="pass-title" class="kick !mb-0">{{ t('premium.pass.title') }}</h2>
          <span v-if="passOn" class="tag tag-ok" data-test="pass-status">{{ t('premium.pass.on', { left: timeLeft(pass.until_ms) }) }}</span>
          <span v-else class="tag" data-test="pass-status">{{ t('premium.pass.off') }}</span>
        </div>
        <p class="sub">{{ t('premium.pass.text', { period: days(pass.days) }) }}</p>
        <p v-if="view.price_currency" class="font-semibold text-ink" data-test="pass-price">
          {{ t('premium.pass.price', { price: price(pass.price_minor, view.price_currency), period: days(pass.days) }) }}
        </p>
        <p v-if="!pass.enabled" class="text-sm text-muted" data-test="pass-off-game">{{ t('premium.off') }}</p>
        <p v-else-if="!pass.purchasable" class="sub" data-test="pass-unavailable">{{ t('premium.pass.unavailable') }}</p>
      </section>
    </template>
  </GameShell>
</template>
