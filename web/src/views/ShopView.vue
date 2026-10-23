<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { ApiError } from '@/api/client'
import type { ShopOffer } from '@/api/types'
import GameShell from '@/components/GameShell.vue'
import PartStats from '@/components/PartStats.vue'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { useFormat } from '@/i18n/useFormat'
import { useGarageStore } from '@/stores/garage'

const { t } = useI18n()
const { money } = useFormat()
const store = useGarageStore()

useLeaveOnSignOut()

const loadError = ref(false)
/** The part that is being bought: one purchase at a time. */
const buying = ref<string | null>(null)
const { message: done, show: showDone, clear: clearDone } = useFlash()
const error = ref<string | null>(null)

async function open(): Promise<void> {
  loadError.value = false

  try {
    await store.loadShop()
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = true
    }
  }
}

onMounted(open)

function affordable(offer: ShopOffer): boolean {
  return (store.cash?.cash_cents ?? 0) >= (offer.price_cents ?? 0)
}

async function buy(offer: ShopOffer): Promise<void> {
  if (buying.value !== null) {
    return
  }

  buying.value = offer.slug
  clearDone()
  error.value = null

  try {
    const result = await store.buy(offer.slug)

    showDone(result.purchase.repeated ? t('shop.repeated') : t('shop.bought', { name: offer.name }))
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    // The server says what is wrong in the language of the driver: not enough cash, a part that is not for sale.
    error.value =
      failure instanceof ApiError && failure.status === 429
        ? t('common.tooManyRequests')
        : failure instanceof ApiError && failure.status === 422
          ? failure.message
          : t('common.error')
  } finally {
    buying.value = null
  }
}
</script>

<template>
  <GameShell>
    <header class="flex flex-col gap-1">
      <h1 class="ttl">{{ t('shop.title') }}</h1>
      <p class="sub">{{ t('shop.intro') }}</p>
      <p v-if="store.cash" class="sr-only" data-test="cash">{{ t('shop.cash', { money: money(store.cash.cash_cents, 2) }) }}</p>
    </header>

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('shop.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="open">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!store.shopLoaded" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <div v-if="done" class="card card-cy flex flex-col gap-2 !p-3" role="status" data-test="done">
        <p class="kick !mb-0">{{ t('shop.last') }}</p>
        <p class="font-semibold">{{ done }}</p>
        <RouterLink :to="{ name: 'workshop' }" class="btn btn-primary btn-sm" data-test="to-workshop">{{ t('shop.toWorkshop') }}</RouterLink>
      </div>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>

      <p v-if="!store.shopCar" class="text-muted" data-test="no-car">{{ t('shop.noCar') }}</p>

      <ul class="flex flex-col gap-2.5">
        <li v-for="offer in store.offers" :key="offer.slug" class="card flex flex-col gap-1.5 !p-3" :data-test="`offer-${offer.slug}`">
          <div class="flex items-center justify-between gap-2.5">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <h2 class="text-[15px] font-bold text-ink">{{ offer.name }}</h2>
                <span class="tag">{{ t('part.tier', { tier: offer.tier ?? 0 }) }}</span>
              </div>
              <p class="sub">{{ t(`slot.${offer.slot}`) }}</p>
            </div>
            <button
              type="button"
              class="btn btn-primary btn-sm shrink-0"
              :disabled="buying !== null || !affordable(offer)"
              :data-test="`buy-${offer.slug}`"
              @click="buy(offer)"
            >
              {{ buying === offer.slug ? t('shop.buying') : t('shop.buy', { price: money(offer.price_cents ?? 0, 2) }) }}
            </button>
          </div>

          <PartStats :part="offer" />

          <p v-if="!affordable(offer)" class="sub" data-test="no-cash">{{ t('shop.noCash') }}</p>
          <p v-if="store.shopCar && !offer.mountable" class="text-sm text-danger" data-test="needs-level">
            {{ t('shop.needsLevel', { level: offer.required_car_level }) }} {{ t('shop.yourLevel', { level: store.shopCar.level }) }}
          </p>
        </li>
      </ul>
    </template>
  </GameShell>
</template>
