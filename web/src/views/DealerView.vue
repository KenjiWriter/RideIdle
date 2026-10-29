<script setup lang="ts">
import { computed, onMounted } from 'vue'

import type { DealerOffer } from '@/api/types'
import CarStage from '@/components/CarStage.vue'
import CityTabs from '@/components/CityTabs.vue'
import GameShell from '@/components/GameShell.vue'
import { useMarketScreen } from '@/features/market/useMarketScreen'
import { useFormat } from '@/i18n/useFormat'

const { store, t, done, loadError, busy, error, cash, garage, city, hasRoom, className, load, attempt } = useMarketScreen()
const { money, number } = useFormat()

const offers = computed(() => store.market?.dealer ?? [])

onMounted(() => void load())

const buy = (offer: DealerOffer) =>
  attempt(async () => {
    await store.buyNew(offer.vehicle.slug)

    return t('fleet.carBought', { car: offer.vehicle.name, city: city.value })
  })
</script>

<template>
  <GameShell>
    <header class="flex flex-col gap-1">
      <h1 class="ttl">{{ t('market.dealerTitle') }}</h1>
      <p v-if="store.market" class="sub" data-test="sub">{{ t('market.dealerSub', { city }) }}</p>
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

      <article v-for="offer in offers" :key="offer.vehicle.slug" class="card flex flex-col gap-2 !p-3" :class="{ 'opacity-90': !offer.open }" :data-test="`offer-${offer.vehicle.slug}`">
        <CarStage compact :name="offer.vehicle.name" :src="offer.vehicle.image_url" :slug="offer.vehicle.slug" />

        <div class="flex items-center justify-between gap-2">
          <div class="flex min-w-0 items-center gap-2">
            <b class="truncate text-base" data-test="name">{{ offer.vehicle.name }}</b>
            <span class="tag flex-none">{{ className(offer.vehicle.class) }}</span>
            <span v-if="offer.vehicle.tier" class="tier !h-5 flex-none !px-2 !text-sm" data-test="tier">{{ t('car.tier', { tier: offer.vehicle.tier }) }}</span>
          </div>
          <div class="flex-none font-display text-[26px] font-bold leading-none text-amber" data-test="price">{{ money(offer.price_cents) }}</div>
        </div>

        <div class="flex flex-wrap gap-1.5">
          <span class="chip" data-test="engine">{{ offer.vehicle.engine }} · {{ t('car.power', { value: offer.vehicle.power_kw }) }}</span>
          <span class="chip" data-test="speed">{{ t('market.speed', { value: offer.vehicle.top_speed_kmh }) }}</span>
          <span class="chip" data-test="mass">{{ t('market.mass', { value: number(offer.vehicle.mass_kg) }) }}</span>
        </div>

        <p class="sub">{{ t('market.dealerNew') }}</p>

        <button v-if="offer.open" type="button" class="btn btn-primary btn-sm" :disabled="busy || !hasRoom || cash < offer.price_cents" data-test="buy" @click="buy(offer)">{{ t('market.buy') }}</button>
        <button v-else type="button" class="btn btn-secondary btn-sm" disabled data-test="locked">{{ t('market.dealerLocked', { level: offer.level_required }) }}</button>
      </article>
    </template>
  </GameShell>
</template>
