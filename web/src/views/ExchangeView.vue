<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import type { ExchangeListing, YardCar } from '@/api/types'
import CarStage from '@/components/CarStage.vue'
import CityTabs from '@/components/CityTabs.vue'
import GameShell from '@/components/GameShell.vue'
import { useMarketScreen } from '@/features/market/useMarketScreen'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'

/** The exchange is asked again at the earliest this long after the last answer, so a refresh that is late cannot cause a storm of requests. */
const MIN_ASK_MS = 3_000

/** The cells of the bar of a car: the defects of a car that was inspected take these places, in this order, and a car that was not has none to show. */
const CELLS = 8
const WORN_ORDER = [1, 2, 4, 6, 0, 3, 5, 7]

const { store, t, done, loadError, busy, error, cash, garage, city, hasRoom, className, askedAgoMs, load, attempt } = useMarketScreen()
const { money, number } = useFormat()

const now = ref(store.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined

const listings = computed(() => store.market?.exchange.listings ?? [])
const wrecks = computed(() => store.market?.wrecks ?? [])
const refreshInMs = computed(() => (store.market ? store.market.exchange.next_refresh_ms - now.value : 0))

function tick(): void {
  now.value = store.serverNow()

  // The server makes new listings when the refresh is due, and it says so only when it is asked.
  if (store.market && refreshInMs.value <= 0 && askedAgoMs() >= MIN_ASK_MS) {
    void load()
  }
}

onMounted(() => {
  void load()
  ticker = setInterval(tick, 1_000)
})

onBeforeUnmount(() => clearInterval(ticker))

/** The year of the car, from the year of the game and its age. */
const modelYear = (listing: ExchangeListing): number => new Date(now.value).getUTCFullYear() - listing.age_years

type Cell = 'on' | 'lo' | 'unknown'

/** The bar of a car: dashed while its condition is unknown, and then a cell for each of its parts, in red for the ones that are worn out. */
function cells(listing: ExchangeListing): Cell[] {
  if (!listing.condition) {
    return Array.from({ length: CELLS }, () => 'unknown' as const)
  }

  const worn = new Set(WORN_ORDER.slice(0, Math.min(CELLS, listing.condition.defects)))

  return Array.from({ length: CELLS }, (_, index) => (worn.has(index) ? ('lo' as const) : ('on' as const)))
}

const inspect = (listing: ExchangeListing) =>
  attempt(async () => {
    await store.inspect(listing.key)

    return null
  })

const buy = (listing: ExchangeListing) =>
  attempt(async () => {
    await store.buyListing(listing.key)

    return t('fleet.carBought', { car: listing.vehicle.name, city: city.value })
  })

const buyWreck = (wreck: YardCar) =>
  attempt(async () => {
    await store.buyWreck(wreck.slug)

    return t('fleet.carBought', { car: wreck.name, city: city.value })
  })
</script>

<template>
  <GameShell>
    <header class="flex flex-col gap-1">
      <h1 class="ttl">{{ t('market.exchangeTitle') }}</h1>
      <p v-if="store.market" class="sub" data-test="sub">{{ t('market.exchangeSub', { city, time: formatDuration(refreshInMs) }) }}</p>
    </header>

    <CityTabs />

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('market.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="load">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!store.market" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>
      <p v-if="done" class="card card-cy text-sm" role="status" data-test="done">{{ done }}</p>

      <p v-if="garage" class="sub" data-test="garage">{{ t('market.garage', { city, used: garage.used, capacity: garage.capacity }) }}</p>
      <p v-if="!hasRoom" class="card card-hi text-sm" role="status" data-test="no-room">{{ t('market.noRoom', { city }) }}</p>

      <p v-if="listings.length === 0" class="text-muted" data-test="empty">{{ t('market.exchangeEmpty') }}</p>

      <article v-for="(listing, index) in listings" :key="listing.key" class="card flex flex-col gap-2 !p-3" :data-tutorial="listing.key === 'tutorial' ? 'exchange' : undefined" :data-test="`listing-${index}`" data-listing>
        <CarStage compact :name="listing.vehicle.name" :src="listing.vehicle.image_url" :slug="listing.vehicle.slug" />

        <div class="flex items-center justify-between gap-2">
          <div class="flex min-w-0 items-center gap-2">
            <b class="truncate text-base" data-test="name">{{ listing.vehicle.name }}</b>
            <span class="tag flex-none">{{ className(listing.vehicle.class) }}</span>
            <span v-if="listing.vehicle.tier" class="tier !h-5 flex-none !px-2 !text-sm" data-test="tier">{{ t('car.tier', { tier: listing.vehicle.tier }) }}</span>
          </div>
          <div class="flex-none font-display text-[26px] font-bold leading-none text-amber" data-test="price">{{ money(listing.price_cents) }}</div>
        </div>

        <div class="flex flex-wrap gap-1.5">
          <span class="chip" data-test="km">{{ t('market.km', { km: number(listing.mileage_km) }) }}</span>
          <span class="chip" data-test="engine">{{ listing.vehicle.engine }}</span>
          <span class="chip" data-test="year">{{ t('market.year', { year: modelYear(listing) }) }}</span>
          <span v-if="listing.condition" class="chip chip-cy" data-test="inspection">{{ t('market.inspection', { percent: listing.condition.percent }) }}</span>
          <span v-else class="chip chip-am" data-test="word">{{ t(`market.word.${listing.word}`) }}</span>
          <span v-if="listing.condition && listing.condition.defects > 0" class="chip chip-bad" data-test="defects">{{ t('market.defects', { count: listing.condition.defects }) }}</span>
        </div>

        <div class="fuelbar" aria-hidden="true" data-test="cells">
          <i
            v-for="(cell, cellIndex) in cells(listing)"
            :key="cellIndex"
            :class="{ on: cell === 'on', lo: cell === 'lo', 'border border-dashed border-line-2 !bg-transparent': cell === 'unknown' }"
          />
        </div>

        <div class="grid grid-cols-2 gap-2">
          <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || listing.inspected || cash < (listing.inspection_cost_cents ?? 0)" data-test="inspect" @click="inspect(listing)">
            {{ listing.inspected ? t('market.inspected') : t('market.inspect', { price: money(listing.inspection_cost_cents ?? 0) }) }}
          </button>
          <button type="button" class="btn btn-primary btn-sm" :disabled="busy || !hasRoom || cash < listing.price_cents" data-test="buy" @click="buy(listing)">{{ t('market.buy') }}</button>
        </div>
      </article>

      <section v-if="wrecks.length > 0" class="flex flex-col gap-2" :aria-label="t('fleet.yard')" data-test="yard">
        <p class="kick !mb-0">{{ t('fleet.yard') }}</p>

        <article v-for="wreck in wrecks" :key="wreck.slug" class="card flex items-center justify-between gap-2.5 !p-3" :data-test="`wreck-${wreck.slug}`">
          <div class="min-w-0">
            <b class="text-sm">{{ wreck.name }}</b>
            <p class="sub">{{ className(wreck.class) }}</p>
          </div>
          <button type="button" class="btn btn-secondary btn-sm flex-none" :disabled="busy || !hasRoom || cash < wreck.price_cents" data-test="buy-wreck" @click="buyWreck(wreck)">
            {{ t('fleet.buyWreck', { price: money(wreck.price_cents) }) }}
          </button>
        </article>
      </section>
    </template>
  </GameShell>
</template>
