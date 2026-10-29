<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import type { FleetCar, FleetGarage } from '@/api/types'
import CarSale from '@/components/CarSale.vue'
import CarStage from '@/components/CarStage.vue'
import GameShell from '@/components/GameShell.vue'
import GarageTabs from '@/components/GarageTabs.vue'
import CarSilhouette from '@/components/CarSilhouette.vue'
import MediaPicture from '@/components/MediaPicture.vue'
import { stageArt } from '@/features/art'
import { useCarClass } from '@/features/car/useCarClass'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useFleetStore } from '@/stores/fleet'

/** How often an open fleet asks the server for the cars, besides asking when a journey is due: the parked cars drive on by themselves. */
const FLEET_POLL_MS = 15_000

/** The fleet is asked again at the earliest this long after the last answer, so a journey that is late cannot cause a storm of requests. */
const MIN_ASK_MS = 3_000

const { t } = useI18n()
const { money, fixed } = useFormat()
const { className } = useCarClass()
const router = useRouter()
const store = useFleetStore()
const { message: done, show: showDone, clear: clearDone } = useFlash()

useLeaveOnSignOut()

const loadError = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

const now = ref(store.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined
let poller: ReturnType<typeof setInterval> | undefined
let lastAsk = 0

const cars = computed(() => store.fleet?.cars ?? [])
const garages = computed(() => store.fleet?.garages ?? [])
const owned = computed(() => garages.value.filter((garage) => garage.owned))
const unowned = computed(() => garages.value.filter((garage) => !garage.owned))
const cash = computed(() => store.cash?.cash_cents ?? 0)

/** What a thumb of the strip shows, the same as the stage would for the car. */
const thumbArt = (car: FleetCar) => stageArt(car.vehicle.image_url, car.vehicle.slug)

/** The car on the stage: the one the driver tapped in the strip while it is still in the fleet, else the one that is played. */
const focusId = ref<number | null>(null)
const focused = computed<FleetCar | null>(() => cars.value.find((car) => car.id === focusId.value) ?? cars.value.find((car) => car.selected) ?? cars.value[0] ?? null)

async function load(): Promise<void> {
  lastAsk = Date.now()

  try {
    await store.load()
    loadError.value = false

    // A driver without a car has not chosen a wreck yet.
    if (store.fleet && store.fleet.cars.length === 0) {
      await router.replace({ name: 'choose' })
    }
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = !store.loaded
    }
  }
}

function tick(): void {
  now.value = store.serverNow()

  // A journey whose time has come is carried out by the server when it is asked.
  if (cars.value.some((car) => car.travel && car.travel.arrive_ms <= now.value) && Date.now() - lastAsk >= MIN_ASK_MS) {
    void load()
  }
}

onMounted(() => {
  void load()
  ticker = setInterval(tick, 1_000)
  poller = setInterval(() => !document.hidden && void load(), FLEET_POLL_MS)
})

onBeforeUnmount(() => {
  clearInterval(ticker)
  clearInterval(poller)
})

function statusKey(car: FleetCar): string {
  return car.run?.stop_reason ?? 'running'
}

function chipClass(car: FleetCar): string {
  const reason = car.run?.stop_reason ?? null

  return car.run?.status !== 'stopped' ? 'chip-cy' : reason === 'workshop' || reason === 'travel' || reason === 'parked' ? 'chip-am' : 'chip-bad'
}

/** Where the car is, and what it is doing there. */
function whereText(car: FleetCar): string {
  if (car.travel) {
    return t('fleet.onTheRoad', { from: car.city.name, to: car.travel.to_name, time: formatDuration(Math.max(0, car.travel.arrive_ms - now.value)) })
  }

  return car.run ? `${car.city.name} · ${car.run.track}` : car.city.name
}

async function attempt(action: () => Promise<string>): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  error.value = null
  clearDone()

  try {
    showDone(await action())
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    // The server says what is wrong in the language of the driver: the cash, the level, a full garage.
    error.value = failure instanceof ApiError && failure.status === 429 ? t('common.tooManyRequests') : failure instanceof ApiError && failure.status === 422 ? failure.message : t('common.error')

    if (failure instanceof ApiError && failure.status === 422) {
      void load()
    }
  } finally {
    busy.value = false
  }
}

const sell = (car: FleetCar) =>
  attempt(async () => {
    const cents = await store.sell(car.id)

    return t('fleet.sold', { car: car.vehicle.name, price: money(cents) })
  })

const choose = (car: FleetCar) =>
  attempt(async () => {
    await store.select(car.id)

    return t('fleet.chosen', { car: car.vehicle.name })
  })

const buyGarage = (garage: FleetGarage) =>
  attempt(async () => {
    await store.buyGarage(garage.city)

    return t('fleet.garageBought', { city: garage.name })
  })
</script>

<template>
  <GameShell>
    <header class="flex flex-col gap-1">
      <h1 class="ttl">{{ t('fleet.title') }}</h1>
      <p v-if="store.fleet" class="sub" data-test="summary">{{ t('fleet.summary', { cars: cars.length, garages: owned.length }) }}</p>
      <!-- A fleet earns cash with every car, but only the car that is played levels the driver. -->
      <p v-if="cars.length > 1" class="sub" data-test="xp-note">{{ t('fleet.xpNote') }}</p>
    </header>

    <GarageTabs />

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('fleet.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="load">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!store.fleet" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>
      <p v-if="done" class="card card-cy text-sm" role="status" data-test="done">{{ done }}</p>

      <template v-if="focused">
        <!-- The car that is looked at, on its stage, and the strip of all the cars under it: a tap puts another one on the stage. -->
        <CarStage :key="focused.id" :name="focused.vehicle.name" :src="focused.vehicle.image_url" :slug="focused.vehicle.slug">
          <span class="chip chip-am">{{ t('garage.carLevel', { level: focused.level }) }}</span>
          <span class="chip" :class="chipClass(focused)" data-test="car-status">{{ t(`run.status.${statusKey(focused)}`) }}</span>
        </CarStage>

        <div class="rail" role="group" :aria-label="t('fleet.cars')" data-test="rail">
          <button
            v-for="car in cars"
            :key="car.id"
            type="button"
            class="thumb"
            :class="{ on: car.id === focused.id }"
            :aria-pressed="car.id === focused.id"
            :data-test="`thumb-${car.id}`"
            @click="focusId = car.id"
          >
            <span class="thumb-pic" :class="{ 'thumb-light': thumbArt(car).cutout }">
              <MediaPicture :src="car.vehicle.image_url" :fallback="thumbArt(car).picture" alt="">
                <template #placeholder><span class="stage-empty" data-test="thumb-empty" aria-hidden="true"><CarSilhouette /></span></template>
              </MediaPicture>
              <span v-if="car.selected" class="thumb-tag" data-test="thumb-selected">{{ t('fleet.selected') }}</span>
            </span>
            <span class="thumb-body">
              <span class="thumb-name" data-test="thumb-name">{{ car.vehicle.name }}</span>
              <span class="thumb-meta">{{ car.city.name }}</span>
              <span class="thumb-meta text-amber">{{ t('fleet.value', { price: money(car.sale_cents) }) }}</span>
            </span>
          </button>
        </div>

        <article class="card flex flex-col gap-2 !p-3" :class="{ 'card-hi': focused.selected }" :data-test="`car-${focused.id}`">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p v-if="className(focused.vehicle.class)" class="kick !mb-1">{{ className(focused.vehicle.class) }}</p>
              <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <b class="truncate text-[17px]" data-test="car-name">{{ focused.vehicle.name }}</b>
                <span v-if="focused.vehicle.tier" class="tier">{{ t('car.tier', { tier: focused.vehicle.tier }) }}</span>
                <span v-if="focused.selected" class="tag tag-am" data-test="car-selected">{{ t('fleet.selected') }}</span>
              </div>
            </div>
          </div>
          <p class="text-xs text-muted" data-test="car-where">{{ whereText(focused) }}</p>
          <div v-if="focused.run" class="flex flex-wrap gap-1.5">
            <span class="chip" data-test="car-fuel">{{ t('fleet.fuel', { fuel: fixed(focused.run.fuel_ml / 1000, 0), tank: fixed(focused.run.tank_ml / 1000, 0) }) }}</span>
            <span class="chip" data-test="car-laps">{{ t('fleet.laps', { count: focused.run.laps_done }) }}</span>
            <span class="chip chip-am">{{ t('garage.carLevel', { level: focused.level }) }}</span>
            <span class="chip" :class="focused.condition_percent < 60 ? 'chip-bad' : ''" data-test="car-condition">{{ t('fleet.condition', { percent: focused.condition_percent }) }}</span>
          </div>
          <div class="flex flex-wrap gap-2">
            <button v-if="!focused.selected" type="button" class="btn btn-secondary btn-sm flex-1" :disabled="busy" data-test="play" @click="choose(focused)">{{ t('fleet.play') }}</button>
            <CarSale :car="focused" :disabled="busy" @sell="sell(focused)" />
          </div>
        </article>
      </template>

      <section v-for="garage in owned" :key="garage.city" class="flex flex-col gap-2" :aria-label="garage.name" :data-test="`garage-${garage.city}`">
        <p class="kick !mb-0" data-test="garage-title">{{ garage.name }} · {{ t('fleet.places', { used: garage.used, capacity: garage.capacity }) }}</p>

        <div v-if="garage.free > 0" class="card flex items-center justify-between gap-2.5 !p-3 !border-dashed" data-test="free-places">
          <p class="text-sm text-muted">{{ t('fleet.free', { count: garage.free }) }}</p>
        </div>
      </section>

      <section v-if="unowned.length > 0" class="flex flex-col gap-2" :aria-label="t('fleet.garagesToBuy')" data-test="garages-to-buy">
        <p class="kick !mb-0">{{ t('fleet.garagesToBuy') }}</p>

        <div v-for="garage in unowned" :key="garage.city" class="card flex items-center justify-between gap-2.5 !p-3 !border-dashed" :data-test="`garage-offer-${garage.city}`">
          <div class="min-w-0">
            <p class="text-sm text-ink">{{ t('fleet.garageIn', { city: garage.name }) }}</p>
            <p class="sub" data-test="offer-note">
              {{ garage.open ? t('fleet.garagePlaces', { capacity: garage.capacity }) : t('fleet.garageLocked', { required: garage.level_required }) }}
            </p>
          </div>
          <button type="button" class="btn btn-secondary btn-sm flex-none" :disabled="busy || !garage.open || cash < (garage.price_cents ?? 0)" data-test="buy-garage" @click="buyGarage(garage)">
            {{ t('fleet.buyGarage', { price: money(garage.price_cents ?? 0) }) }}
          </button>
        </div>
      </section>


      <section class="card flex items-center justify-between gap-2.5 !p-3" data-test="buy-car">
        <div class="min-w-0">
          <p class="text-sm text-ink">{{ t('fleet.buyCarTitle') }}</p>
          <p class="sub">{{ t('fleet.buyCarNote') }}</p>
        </div>
        <RouterLink :to="{ name: 'exchange' }" class="btn btn-secondary btn-sm flex-none no-underline" data-test="to-exchange">{{ t('market.tabExchange') }}</RouterLink>
      </section>
    </template>
  </GameShell>
</template>
