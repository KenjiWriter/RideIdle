<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import type { StorageClass, StorageFind, StorageUnit } from '@/api/types'
import CityTabs from '@/components/CityTabs.vue'
import GameShell from '@/components/GameShell.vue'
import { useMarketScreen } from '@/features/market/useMarketScreen'
import { useUnits } from '@/game/useUnits'
import { useFormat } from '@/i18n/useFormat'

/** What can be in a unit, from the worst to the best: the order of the odds on the card. */
const CLASSES: StorageClass[] = ['scrap', 'junk', 'good', 'gem']

const { store, t, loadError, busy, error, cash, garage, city, hasRoom, className, load, attempt } = useMarketScreen()
const { money, number, percent } = useFormat()
const { distanceKm } = useUnits()

const units = computed(() => store.market?.storage.units ?? [])
const every = computed(() => store.market?.storage.pity_every ?? 0)

/** What the last unit that was opened held, until another one is. */
const found = ref<StorageFind | null>(null)
const foundCard = ref<HTMLElement | null>(null)

onMounted(() => void load())

/** The year of the car, from the year of the game and its age. */
const modelYear = (find: StorageFind): number => new Date(store.serverNow()).getUTCFullYear() - find.age_years

const open = (unit: StorageUnit) =>
  attempt(async () => {
    const find = await store.openStorage(unit.key)

    if (find) {
      found.value = find
      // The unit that was opened may be far down the page, and what it held is on top.
      await nextTick()
      foundCard.value?.scrollIntoView?.({ block: 'center', behavior: 'smooth' })
    }

    return null
  })
</script>

<template>
  <GameShell>
    <header class="flex flex-col gap-1">
      <h1 class="ttl">{{ t('market.storageTitle') }}</h1>
      <p v-if="store.market" class="sub" data-test="sub">{{ t('market.storageSub', { city }) }}</p>
    </header>

    <CityTabs />

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('market.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="load">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!store.market" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>

      <p v-if="garage" class="sub" data-test="garage">{{ t('market.garage', { city, used: garage.used, capacity: garage.capacity }) }}</p>
      <p v-if="!hasRoom" class="card card-hi text-sm" role="status" data-test="no-room">{{ t('market.noRoom', { city }) }}</p>

      <section v-if="found" ref="foundCard" class="card card-hi flex flex-col gap-2 !p-3" role="status" :data-class="found.class" data-test="found">
        <p class="kick !mb-0" data-test="found-title">{{ t(`market.storageResultTitle.${found.class}`) }}</p>

        <div class="flex items-center justify-between gap-2">
          <div class="flex min-w-0 items-center gap-2">
            <b class="truncate text-base" data-test="found-name">{{ found.vehicle.name }}</b>
            <span class="tag flex-none">{{ className(found.vehicle.class) }}</span>
          </div>
          <div class="flex flex-none flex-col items-end">
            <span class="text-xs text-muted">{{ t('market.storageSale') }}</span>
            <span class="font-display text-[26px] font-bold leading-none text-amber" data-test="found-sale">{{ money(found.sale_cents) }}</span>
          </div>
        </div>

        <div class="flex flex-wrap gap-1.5">
          <span class="chip" data-test="found-km">{{ t('market.km', { km: number(found.mileage_km), distance: distanceKm(found.mileage_km) }) }}</span>
          <span class="chip" data-test="found-engine">{{ found.vehicle.engine }}</span>
          <span class="chip" data-test="found-year">{{ t('market.year', { year: modelYear(found) }) }}</span>
          <span class="chip" :class="found.condition.percent < 60 ? 'chip-bad' : 'chip-cy'" data-test="found-condition">{{ t('fleet.condition', { percent: found.condition.percent }) }}</span>
          <span v-if="found.condition.defects > 0" class="chip chip-bad" data-test="found-defects">{{ t('market.defects', { count: found.condition.defects }) }}</span>
        </div>

        <p class="sub" data-test="found-worth">{{ t('market.storageWorth', { price: money(found.value_cents) }) }}</p>

        <RouterLink :to="{ name: 'fleet' }" class="btn btn-secondary btn-sm no-underline" data-test="to-fleet">{{ t('market.storageToFleet') }}</RouterLink>
      </section>

      <article v-for="unit in units" :key="unit.key" class="card flex flex-col gap-2 !p-3" :class="{ 'opacity-90': !unit.open }" :data-test="`unit-${unit.key}`">
        <div class="flex items-center justify-between gap-2">
          <b class="text-base" data-test="name">{{ t(`market.storageKind.${unit.key}`) }}</b>
          <div class="flex-none font-display text-[26px] font-bold leading-none text-amber" data-test="price">{{ money(unit.price_cents) }}</div>
        </div>

        <div class="flex flex-col gap-1">
          <p class="kick !mb-0">{{ t('market.storageOdds') }}</p>
          <div class="flex flex-wrap gap-1.5" data-test="odds">
            <span v-for="kind in CLASSES" :key="kind" class="chip" :class="{ 'chip-cy': kind === 'good', 'chip-am': kind === 'gem' }" :data-test="`odds-${kind}`">
              {{ t('market.storageOdd', { name: t(`market.storageClass.${kind}`), percent: percent(unit.odds[kind]) }) }}
            </span>
          </div>
        </div>

        <p class="sub" data-test="guarantee">{{ t('market.storageGuarantee', { count: unit.until_guarantee }) }}</p>

        <button v-if="unit.open" type="button" class="btn btn-primary btn-sm" :disabled="busy || !hasRoom || cash < unit.price_cents" data-test="open" @click="open(unit)">
          {{ t('market.storageOpen', { price: money(unit.price_cents) }) }}
        </button>
        <button v-else type="button" class="btn btn-secondary btn-sm" disabled data-test="locked">{{ t('market.storageLocked', { level: unit.unlock_level }) }}</button>
      </article>

      <p class="sub" data-test="rule">{{ t('market.storageRule', { every }) }}</p>
    </template>
  </GameShell>
</template>
