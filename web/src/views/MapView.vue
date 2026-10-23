<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import type { MapCity } from '@/api/types'
import GameShell from '@/components/GameShell.vue'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useMapStore } from '@/stores/map'
import { useRunStore } from '@/stores/run'

/** How often an open map asks the server for the journey, besides asking when one is due. */
const MAP_POLL_MS = 15_000

/** The map is asked again at the earliest this long after the last answer, so a journey that is late cannot cause a storm of requests. */
const MIN_ASK_MS = 3_000

/** The part of the board of the map that is drawn: the cities lie between these rows, and the names are under them. */
const BOARD = { width: 390, top: 60, height: 300 }

/** The order the statistics are listed in. */
const STATS = ['accel', 'top_speed', 'grip', 'handling', 'braking', 'clearance']

/** The icons are the ones of the design canvas (24 x 24, drawn with a line). */
const ICONS = {
  home: 'M3 20V9l9-5 9 5v11M7 20v-7h10v7M7 16h10',
  flag: 'M5 21V4M5 4h12l-2 4 2 4H5',
  lock: 'M6 11h12v9H6zM8 11V8a4 4 0 0 1 8 0v3',
}

const { t } = useI18n()
const { fixed } = useFormat()
const map = useMapStore()
const run = useRunStore()
const { message: started, show: showStarted, clear: clearStarted } = useFlash()

useLeaveOnSignOut()

const loadError = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const picked = ref<string | null>(null)

const now = ref(map.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined
let poller: ReturnType<typeof setInterval> | undefined
let lastAsk = 0

const cities = computed(() => map.map?.cities ?? [])
const travel = computed(() => map.map?.travel ?? null)
/** The city whose sheet is open: the one that was touched, and until one is, the one the car is in. */
const selected = computed(() => cities.value.find((city) => city.slug === picked.value) ?? cities.value.find((city) => city.here) ?? null)
const car = computed(() => run.run?.car.name ?? null)

async function load(): Promise<void> {
  lastAsk = Date.now()

  try {
    await map.load()
    loadError.value = false
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = !map.loaded
    }
  }
}

function tick(): void {
  now.value = map.serverNow()

  // A journey whose time has come is carried out by the server when it is asked.
  if (travel.value && travel.value.arrive_ms <= now.value && Date.now() - lastAsk >= MIN_ASK_MS) {
    void load()
  }
}

onMounted(() => {
  void load()
  ticker = setInterval(tick, 1_000)
  poller = setInterval(() => !document.hidden && void load(), MAP_POLL_MS)
})

onBeforeUnmount(() => {
  clearInterval(ticker)
  clearInterval(poller)
})

const position = (city: { x: number; y: number }) => ({ left: `${(city.x / BOARD.width) * 100}%`, top: `${((city.y - BOARD.top) / BOARD.height) * 100}%` })
const bySlug = (slug: string): MapCity | undefined => cities.value.find((city) => city.slug === slug)

/** The roads, drawn between the cities they join. */
const roads = computed(() =>
  (map.map?.roads ?? []).flatMap((road) => {
    const from = bySlug(road.from)
    const to = bySlug(road.to)

    return from && to ? [{ key: `${road.from}-${road.to}`, x1: from.x, y1: from.y, x2: to.x, y2: to.y }] : []
  }),
)

/** How far the journey is, from 0 to 1. */
const progress = computed(() => (travel.value ? Math.min(1, Math.max(0, (now.value - travel.value.start_ms) / (travel.value.arrive_ms - travel.value.start_ms))) : 0))

/** Where the car is on the board: in its city, or on the straight line between the two cities of a journey (the way itself is not drawn). */
const marker = computed(() => {
  const journey = travel.value
  const from = journey ? bySlug(journey.from) : cities.value.find((city) => city.here)
  const to = journey ? bySlug(journey.to) : undefined

  if (!from) {
    return null
  }

  return to ? { x: from.x + (to.x - from.x) * progress.value, y: from.y + (to.y - from.y) * progress.value } : { x: from.x, y: from.y }
})

const fromName = computed(() => bySlug(travel.value?.from ?? '')?.name ?? '')
const toName = computed(() => bySlug(travel.value?.to ?? '')?.name ?? '')

function icon(city: MapCity): string {
  return !city.open ? ICONS.lock : city.home ? ICONS.home : ICONS.flag
}

function nodeLabel(city: MapCity): string {
  return `${t('map.node', { name: city.name, level: city.level_required })}${city.here ? `. ${t('map.here')}` : ''}`
}

/** What the track asks, as bars scaled to the one it asks most. */
const demands = computed(() => {
  const demand = selected.value?.track.demand ?? {}
  const most = Math.max(1, ...Object.values(demand))

  return STATS.filter((stat) => demand[stat]).map((stat) => ({ stat, value: demand[stat] ?? 0, fill: Math.round(((demand[stat] ?? 0) / most) * 100) }))
})

/** Why the car cannot go to the selected city, or null when it can. The server says it again, with the same words, when asked. */
const blocked = computed(() => {
  const city = selected.value

  if (!city || city.here || travel.value) {
    return null
  }

  if (!city.open) {
    return t('map.locked', { required: city.level_required, level: map.map?.level ?? 1 })
  }

  return run.run ? null : t('map.noCar')
})

const canGo = computed(() => !!selected.value && !selected.value.here && !!selected.value.route && !!selected.value.open && !travel.value && !busy.value)

async function go(city: MapCity): Promise<void> {
  if (!canGo.value) {
    return
  }

  busy.value = true
  error.value = null
  clearStarted()

  try {
    await map.travel(city.slug)
    now.value = map.serverNow()
    showStarted(t('map.started', { city: city.name }))
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    // The server says what is wrong in the language of the driver: the level, the fuel, a car that stands.
    error.value = failure instanceof ApiError && failure.status === 429 ? t('common.tooManyRequests') : failure instanceof ApiError && failure.status === 422 ? failure.message : t('common.error')

    if (failure instanceof ApiError && failure.status === 422) {
      void load()
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <GameShell>
    <header class="flex flex-col gap-1">
      <h1 class="ttl">{{ t('map.title') }}</h1>
      <p class="sub">{{ t('map.sub') }}</p>
    </header>

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('map.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="load">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!map.map" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>
      <p v-if="started" class="card card-cy text-sm" role="status" data-test="started">{{ started }}</p>

      <div v-if="travel" class="card card-cy flex flex-col gap-1.5 !p-3" data-test="travel-banner">
        <div class="flex items-center justify-between gap-2">
          <p class="text-[13px] font-semibold text-ink">{{ t('map.onTheWay', { from: fromName, to: toName }) }}</p>
          <span class="chip chip-cy" data-test="travel-eta">{{ t('map.eta', { time: formatDuration(travel.arrive_ms - now) }) }}</span>
        </div>
        <div class="bar bar-cy" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(progress * 100)" data-test="travel-bar">
          <i :style="{ width: `${Math.round(progress * 100)}%` }" />
        </div>
      </div>

      <section class="card relative aspect-[390/300] overflow-hidden !p-0" :aria-label="t('map.label')" data-test="map">
        <svg class="absolute inset-0 h-full w-full" :viewBox="`0 ${BOARD.top} ${BOARD.width} ${BOARD.height}`" preserveAspectRatio="none" aria-hidden="true">
          <g fill="none" stroke-linecap="round">
            <g v-for="road in roads" :key="road.key" data-test="road">
              <line :x1="road.x1" :y1="road.y1" :x2="road.x2" :y2="road.y2" stroke="#262C37" stroke-width="12" />
              <line :x1="road.x1" :y1="road.y1" :x2="road.x2" :y2="road.y2" stroke="#3B4252" stroke-width="2" stroke-dasharray="6 8" />
            </g>
          </g>
        </svg>

        <div
          v-if="marker"
          class="pointer-events-none absolute z-[1] h-[18px] w-[18px] -translate-x-1/2 -translate-y-1/2 rounded-full border-[3px] border-[#0A1E25] bg-cyan shadow-[0_0_0_2px_var(--color-cyan)]"
          :style="position(marker)"
          data-test="car-marker"
        />

        <template v-for="city in cities" :key="city.slug">
          <button
            type="button"
            class="absolute z-[2] grid h-11 w-11 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full border-2 bg-[#14181f] text-ink"
            :class="[city.here ? 'border-amber' : 'border-line', selected?.slug === city.slug ? 'ring-2 ring-cyan' : '', city.open ? '' : 'opacity-60']"
            :style="position(city)"
            :aria-label="nodeLabel(city)"
            :aria-pressed="selected?.slug === city.slug"
            :data-test="`city-${city.slug}`"
            @click="picked = city.slug"
          >
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path :d="icon(city)" />
            </svg>
          </button>
          <div class="pointer-events-none absolute z-[2] -translate-x-1/2 whitespace-nowrap text-center text-[11px] font-semibold leading-tight text-ink" :style="{ ...position(city), marginTop: '28px' }">
            {{ city.name }}
            <small class="block font-mono text-[9px] font-normal text-faint">{{ t('map.level', { level: city.level_required }) }} · {{ t(`map.surface.${city.track.surface}`) }}</small>
          </div>
        </template>
      </section>

      <section v-if="selected" class="card flex flex-col gap-2.5 !p-3.5" :aria-label="selected.name" data-test="sheet">
        <div class="flex items-center justify-between gap-2.5">
          <div class="min-w-0">
            <h2 class="ttl !text-2xl" data-test="sheet-name">{{ selected.name }}</h2>
            <p class="sub" data-test="sheet-track">{{ selected.track.name }}</p>
          </div>
          <span class="tag flex-none" data-test="sheet-tag">{{ selected.here ? t('map.here') : t('map.target') }}</span>
        </div>

        <div class="flex flex-wrap gap-1.5">
          <span class="chip">{{ t(`map.surface.${selected.track.surface}`) }}</span>
          <span class="chip" :class="selected.track.lit ? 'chip-cy' : ''">{{ selected.track.lit ? t('map.lit') : t('map.unlit') }}</span>
          <span class="chip chip-am">{{ t('map.level', { level: selected.level_required }) }}</span>
        </div>

        <p class="kick !mb-0">{{ t('map.requires') }}</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-2" data-test="demands">
          <div v-for="demand in demands" :key="demand.stat" :data-test="`demand-${demand.stat}`">
            <div class="mb-1 flex justify-between text-xs text-muted">
              <span>{{ t(`carStat.${demand.stat}`) }}</span>
              <span class="mono text-ink">{{ demand.value }}</span>
            </div>
            <div class="bar"><i :style="{ width: `${demand.fill}%` }" /></div>
          </div>
        </div>

        <div v-if="selected.fit_percent !== null" class="flex items-center justify-between gap-2.5 text-[13px] text-muted" data-test="fit">
          <span>{{ t('map.fit', { car }) }}</span>
          <span class="chip" :class="selected.fit_percent < 80 ? 'chip-am' : 'chip-cy'">{{ selected.fit_percent }}%{{ selected.fit_percent < 80 ? ` · ${t('map.fitLow')}` : '' }}</span>
        </div>

        <p v-if="selected.route" class="text-[13px] text-muted" data-test="route">{{ t('map.route', { km: selected.route.km, time: formatDuration(selected.route.ms), fuel: fixed(selected.route.fuel_ml / 1000, 1) }) }}</p>

        <button v-if="!selected.here" type="button" class="btn btn-primary" :disabled="!canGo" data-test="go" @click="go(selected)">{{ t('map.go') }}</button>
        <p v-if="blocked" class="text-sm text-muted" data-test="reason">{{ blocked }}</p>
      </section>
    </template>
  </GameShell>
</template>
