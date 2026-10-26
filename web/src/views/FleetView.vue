<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import type { FleetCar, FleetGarage } from '@/api/types'
import CarSale from '@/components/CarSale.vue'
import GameShell from '@/components/GameShell.vue'
import GarageTabs from '@/components/GarageTabs.vue'
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

/** The cars that stand in a garage, or are on their way to it: the place is kept for a car on the road. */
const carsIn = (garage: FleetGarage): FleetCar[] => cars.value.filter((car) => (car.travel?.to ?? car.city.slug) === garage.city)

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

      <section v-for="garage in owned" :key="garage.city" class="flex flex-col gap-2" :aria-label="garage.name" :data-test="`garage-${garage.city}`">
        <p class="kick !mb-0" data-test="garage-title">{{ garage.name }} · {{ t('fleet.places', { used: garage.used, capacity: garage.capacity }) }}</p>

        <article v-for="car in carsIn(garage)" :key="car.id" class="card flex flex-col gap-1.5 !p-3" :class="{ 'card-hi': car.selected }" :data-test="`car-${car.id}`">
          <div class="flex items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-2">
              <b class="truncate text-[15px]" data-test="car-name">{{ car.vehicle.name }}</b>
              <span v-if="car.selected" class="tag tag-am" data-test="car-selected">{{ t('fleet.selected') }}</span>
            </div>
            <span class="chip flex-none" :class="chipClass(car)" data-test="car-status">{{ t(`run.status.${statusKey(car)}`) }}</span>
          </div>
          <p class="text-xs text-muted" data-test="car-where">{{ whereText(car) }}</p>
          <div v-if="car.run" class="flex flex-wrap gap-1.5">
            <span class="chip" data-test="car-fuel">{{ t('fleet.fuel', { fuel: fixed(car.run.fuel_ml / 1000, 0), tank: fixed(car.run.tank_ml / 1000, 0) }) }}</span>
            <span class="chip" data-test="car-laps">{{ t('fleet.laps', { count: car.run.laps_done }) }}</span>
            <span class="chip chip-am">{{ t('garage.carLevel', { level: car.level }) }}</span>
            <span class="chip" :class="car.condition_percent < 60 ? 'chip-bad' : ''" data-test="car-condition">{{ t('fleet.condition', { percent: car.condition_percent }) }}</span>
          </div>
          <div class="flex flex-wrap gap-2">
            <button v-if="!car.selected" type="button" class="btn btn-secondary btn-sm flex-1" :disabled="busy" data-test="play" @click="choose(car)">{{ t('fleet.play') }}</button>
            <CarSale :car="car" :disabled="busy" @sell="sell(car)" />
          </div>
        </article>

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
