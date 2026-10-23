<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import type { CarStatKey, JobKind, OwnedPart, WorkshopJob } from '@/api/types'
import GameNav from '@/components/GameNav.vue'
import PartStats from '@/components/PartStats.vue'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useGarageStore } from '@/stores/garage'

/** How often an open workshop asks the server for the state of the jobs, besides asking when one is due. */
const WORKSHOP_POLL_MS = 15_000

/** The garage is asked again at the earliest this long after the last answer, so a job that is late cannot cause a storm of requests. */
const MIN_ASK_MS = 3_000

const STATS: CarStatKey[] = ['accel', 'top_speed', 'grip', 'handling', 'braking']

const { t } = useI18n()
const { number } = useFormat()
const store = useGarageStore()

useLeaveOnSignOut()

const loadError = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

/** What time it is for the server, to the second: the clock of the countdown. */
const now = ref(store.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined
let lastAsk = 0

const car = computed(() => store.garage?.car ?? null)
const jobs = computed(() => store.garage?.workshop.jobs ?? [])
const inventory = computed(() => store.garage?.inventory ?? [])
const standing = computed(() => car.value?.run?.stop_reason === 'workshop')
const busyPartIds = computed(() => new Set(jobs.value.map((job) => job.part.id)))
const cut = computed(() => Math.round((store.garage?.workshop.mechanic.time_cut_ppm ?? 0) / 10_000))

async function load(): Promise<void> {
  lastAsk = Date.now()

  try {
    await store.loadGarage()
    loadError.value = false
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = !store.loaded
    }
  }
}

function tick(): void {
  now.value = store.serverNow()

  // A job whose time has come is carried out by the server when it is asked.
  if (jobs.value.some((job) => job.finish_ms <= now.value) && Date.now() - lastAsk >= MIN_ASK_MS) {
    void load()
  }
}

let poller: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  void load()
  ticker = setInterval(tick, 1_000)
  poller = setInterval(() => !document.hidden && void load(), WORKSHOP_POLL_MS)
})

onBeforeUnmount(() => {
  clearInterval(ticker)
  clearInterval(poller)
})

function left(job: WorkshopJob): string {
  return formatDuration(job.finish_ms - now.value)
}

/** What a statistic changed by, as the part makes it: nothing for a change too small to see. */
function delta(stat: CarStatKey): number {
  const value = (car.value?.stats[stat] ?? 0) - (car.value?.base_stats[stat] ?? 0)

  return Math.abs(value) < 0.005 ? 0 : value
}

async function act(kind: JobKind, part: OwnedPart): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  error.value = null

  try {
    await store.queue(kind, part.id)
    now.value = store.serverNow()
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    // The server says what is wrong in the language of the driver: the level of the car, a full queue.
    error.value =
      failure instanceof ApiError && failure.status === 429
        ? t('common.tooManyRequests')
        : failure instanceof ApiError && failure.status === 422
          ? failure.message
          : t('common.error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="mx-auto flex max-w-xl flex-col gap-6 px-4 py-8">
    <GameNav />

    <header class="flex flex-col gap-1">
      <h1 class="text-3xl font-bold tracking-tight">{{ t('workshop.title') }}</h1>
      <p v-if="store.garage" class="text-muted" data-test="mechanic">
        {{ t('workshop.mechanic', { level: store.garage.workshop.mechanic.level, cut }) }}
      </p>
    </header>

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('workshop.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="load">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!store.garage" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <p v-else-if="!car" class="text-muted" data-test="no-car">{{ t('workshop.noCar') }}</p>

    <template v-else>
      <p v-if="standing" class="rounded-lg border border-amber bg-surface-2 p-3 text-sm" role="status" data-test="standing">
        {{ t('workshop.standing') }}
      </p>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>

      <section class="card flex flex-col gap-3" aria-labelledby="queue-title" data-test="queue">
        <div class="flex items-baseline justify-between gap-3">
          <h2 id="queue-title" class="text-xl font-bold">{{ t('workshop.queue', { count: jobs.length, max: store.garage.workshop.queue_max }) }}</h2>
        </div>

        <p v-if="jobs.length === 0" class="text-muted" data-test="queue-empty">{{ t('workshop.queueEmpty') }}</p>

        <ol v-else class="flex flex-col gap-2">
          <li v-for="job in jobs" :key="job.id" class="rounded-lg border border-line bg-surface-2 p-3" :data-test="`job-${job.id}`">
            <p class="font-semibold">{{ t(`workshop.${job.kind}`, { part: job.part.name }) }}</p>
            <p class="text-sm" :class="job.status === 'working' ? 'text-cyan' : 'text-muted'" :data-test="`job-${job.id}-state`">
              {{ job.status === 'working' ? `${t('workshop.working')}: ${t('workshop.left', { time: left(job) })}` : t('workshop.waiting') }}
            </p>
          </li>
        </ol>
      </section>

      <section class="card flex flex-col gap-3" aria-labelledby="car-title" data-test="car">
        <div class="flex items-start justify-between gap-3">
          <h2 id="car-title" class="text-xl font-bold">{{ car.vehicle.name }}</h2>
          <span class="rounded-full border border-line px-3 py-1 text-sm font-bold text-amber" data-test="car-level">{{ t('workshop.level', { level: car.level }) }}</span>
        </div>

        <dl class="grid grid-cols-1 gap-2" :aria-label="t('workshop.stats')">
          <div v-for="stat in STATS" :key="stat" class="flex items-baseline justify-between gap-3" :data-test="`stat-${stat}`">
            <dt class="text-muted">{{ t(`carStat.${stat}`) }}</dt>
            <dd class="font-semibold">
              {{ number(car.stats[stat], 1) }}
              <span v-if="delta(stat) !== 0" class="ml-1 text-sm" :class="delta(stat) > 0 ? 'text-cyan' : 'text-danger'" :data-test="`delta-${stat}`">
                {{ delta(stat) > 0 ? '+' : '−' }}{{ number(Math.abs(delta(stat)), 1) }}
              </span>
            </dd>
          </div>
        </dl>

        <h3 class="mt-2 font-bold">{{ t('workshop.slots') }}</h3>
        <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">
          <li v-for="slot in car.slots" :key="slot.slot" class="rounded-lg border border-line p-3" :data-test="`slot-${slot.slot}`">
            <p class="text-sm text-muted">{{ t(`slot.${slot.slot}`) }}</p>
            <template v-if="slot.part">
              <p class="font-semibold">
                {{ slot.part.name }}
                <span class="ml-1 rounded border border-line px-1 font-mono text-xs">{{ t('part.tier', { tier: slot.part.tier ?? 0 }) }}</span>
              </p>
              <button
                type="button"
                class="btn btn-secondary mt-2"
                :disabled="busy || busyPartIds.has(slot.part.id)"
                :data-test="`take-off-${slot.part.id}`"
                @click="act('unmount', slot.part)"
              >
                {{ t('workshop.takeOff') }}
              </button>
            </template>
            <p v-else-if="slot.built_in" class="font-semibold">{{ car.vehicle.engine ?? t('workshop.builtIn') }}</p>
            <p v-else class="text-muted">{{ t('workshop.empty') }}</p>
          </li>
        </ul>
      </section>

      <section class="card flex flex-col gap-3" aria-labelledby="inventory-title" data-test="inventory">
        <h2 id="inventory-title" class="text-xl font-bold">{{ t('workshop.inventory') }}</h2>

        <p v-if="inventory.length === 0" class="text-muted" data-test="inventory-empty">{{ t('workshop.inventoryEmpty') }}</p>

        <ul v-else class="flex flex-col gap-3">
          <li v-for="part in inventory" :key="part.id" class="flex items-start justify-between gap-3" :data-test="`part-${part.id}`">
            <div>
              <p class="font-semibold">
                {{ part.name }}
                <span class="ml-1 rounded border border-line px-1 font-mono text-xs">{{ t('part.tier', { tier: part.tier ?? 0 }) }}</span>
              </p>
              <p class="text-sm text-muted">{{ t(`slot.${part.slot}`) }}</p>
              <PartStats :part="part" />
            </div>
            <button
              type="button"
              class="btn btn-primary shrink-0"
              :disabled="busy || busyPartIds.has(part.id)"
              :data-test="`put-on-${part.id}`"
              @click="act('mount', part)"
            >
              {{ t('workshop.putOn') }}
            </button>
          </li>
        </ul>
      </section>
    </template>
  </main>
</template>
