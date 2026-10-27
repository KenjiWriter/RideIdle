<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import type { CarStatKey, JobKind, MergeResult, OwnedPart, RepairNext, UpgradeNext, WorkshopJob } from '@/api/types'
import GameShell from '@/components/GameShell.vue'
import JobCancel from '@/components/JobCancel.vue'
import PartCondition from '@/components/PartCondition.vue'
import PartMerge from '@/components/PartMerge.vue'
import PartStats from '@/components/PartStats.vue'
import RarityMark from '@/components/RarityMark.vue'
import UpgradeMark from '@/components/UpgradeMark.vue'
import { useServiceLine } from '@/features/car/serviceLine'
import { statFill } from '@/features/car/statFill'
import { rankOf } from '@/features/parts/rarity'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useGarageStore } from '@/stores/garage'
import { useTutorialStore } from '@/stores/tutorial'

/** How often an open workshop asks the server for the state of the jobs, besides asking when one is due. */
const WORKSHOP_POLL_MS = 15_000

/** The garage is asked again at the earliest this long after the last answer, so a job that is late cannot cause a storm of requests. */
const MIN_ASK_MS = 3_000

const STATS: CarStatKey[] = ['accel', 'top_speed', 'grip', 'handling', 'braking', 'clearance']

const { t } = useI18n()
const { number, money, percent } = useFormat()
const store = useGarageStore()
const tutorial = useTutorialStore()
const { serviceLine, breakdownLine } = useServiceLine()

useLeaveOnSignOut()

const loadError = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const { message: cancelled, show: showCancelled, clear: clearCancelled } = useFlash()

/** What time it is for the server, to the second: the clock of the countdown. */
const now = ref(store.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined
let poller: ReturnType<typeof setInterval> | undefined
let lastAsk = 0

const car = computed(() => store.garage?.car ?? null)
const jobs = computed(() => store.garage?.workshop.jobs ?? [])
const working = computed(() => jobs.value.find((job) => job.status === 'working') ?? null)
const waiting = computed(() => jobs.value.filter((job) => job.status === 'queued'))
const inventory = computed(() => store.garage?.inventory ?? [])
/** The part that the tutorial points at when it asks for the tires to be put on: the first pair of tires in the inventory, or the first part. */
const mountTarget = computed(() => (inventory.value.find((part) => part.slot === 'tires') ?? inventory.value[0])?.id ?? null)
const standing = computed(() => car.value?.run?.stop_reason === 'workshop')
/** The parts that wait in a job: a merge keeps the two of them busy, and a service is a job on the car, with no part. */
const busyPartIds = computed(() => new Set(jobs.value.flatMap((job) => [job.part?.id, job.merge_with?.id].filter((id): id is number => id !== undefined))))
/** A service that waits for the mechanic or is done by him: there is no use in asking for a second. */
const servicing = computed(() => jobs.value.some((job) => job.kind === 'service'))
const merges = computed(() => store.garage?.merges ?? [])
/** The part of the inventory whose chooser of a merge is open. */
const merging = ref<number | null>(null)
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

onMounted(() => {
  void load()
  ticker = setInterval(tick, 1_000)
  poller = setInterval(() => !document.hidden && void load(), WORKSHOP_POLL_MS)
})

onBeforeUnmount(() => {
  clearInterval(ticker)
  clearInterval(poller)
})

/** Minutes and seconds, "3:55". */
function clock(ms: number): string {
  const seconds = Math.max(0, Math.floor(ms / 1000))

  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function left(job: WorkshopJob): string {
  return formatDuration(job.finish_ms - now.value)
}

/** How far the job in progress is, in percent. */
function progress(job: WorkshopJob): number {
  const total = job.finish_ms - job.start_ms

  return total > 0 ? Math.min(100, Math.max(0, Math.round(((now.value - job.start_ms) / total) * 100))) : 100
}

/** What a job is doing, in words: putting a part on, taking it off, upgrading it (and to what level), repairing it, merging two (and into what rarity), or servicing the car. */
function jobText(job: WorkshopJob): string {
  return t(`workshop.${job.kind}`, {
    part: job.part?.name ?? '',
    to: job.kind === 'merge' && job.merge_to ? t(`rarity.${job.merge_to}`) : (job.upgrade_to ?? 0),
    other: job.merge_with?.name ?? '',
  })
}

/** The other parts of the inventory that a part can be merged with, in the order of the inventory: the ones the server listed. */
function partnersOf(part: OwnedPart): OwnedPart[] {
  const ids = new Set(part.merge?.with ?? [])

  return inventory.value.filter((other) => ids.has(other.id))
}

/** What became of an attempt of merging, in words: what was made, or that both parts were lost and what the scrap was. */
function resultText(result: MergeResult): string {
  return result.success ? t('workshop.mergeMade', { name: result.name }) : t('workshop.mergeLost', { name: result.name })
}

/** What the next step of an upgrade says under its button: what it costs and how long it takes, or what stops it. */
function upgradeInfo(next: UpgradeNext): string {
  if (next.locked?.reason === 'max') {
    return t('workshop.upgradeMax')
  }

  if (next.locked?.reason === 'car_level') {
    return t('workshop.upgradeCarLevel', { required: next.locked.required, current: next.locked.current })
  }

  return t('workshop.upgradeCost', { price: money(next.cost_cents ?? 0, 2), time: formatDuration(next.duration_ms ?? 0) })
}

/** Whether a worn part can be put right: the server says what it costs, or why not (it is worn out, which is said in words; it is not worn, or has no price: nothing is offered). */
function canRepair(next: RepairNext | undefined): next is RepairNext & { cost_cents: number; duration_ms: number } {
  return next !== undefined && next.locked === null && next.cost_cents !== null && next.duration_ms !== null
}

function repairInfo(next: RepairNext): string {
  return next.locked?.reason === 'worn_out' ? t('workshop.repairWornOut') : t('workshop.repairCost', { price: money(next.cost_cents ?? 0, 2), time: formatDuration(next.duration_ms ?? 0) })
}

/** What a statistic changed by, as the parts make it: nothing for a change too small to see. */
function delta(stat: CarStatKey): number {
  const value = (car.value?.stats[stat] ?? 0) - (car.value?.base_stats[stat] ?? 0)

  return Math.abs(value) < 0.005 ? 0 : value
}

/** The text of a failed action: what the server says in the language of the driver (the level of the car, a full queue), or a general one. */
function failureText(failure: unknown): string {
  return failure instanceof ApiError && failure.status === 429
    ? t('common.tooManyRequests')
    : failure instanceof ApiError && failure.status === 422
      ? failure.message
      : t('common.error')
}

async function act(kind: JobKind, part: OwnedPart | null, other?: OwnedPart['id']): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  error.value = null
  clearCancelled()

  try {
    await store.queue(kind, part?.id ?? null, other)
    merging.value = null
    now.value = store.serverNow()
    // The server moves the tutorial when it is asked, and a job in the queue is what a step waits for.
    void tutorial.load().catch(() => undefined)
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    error.value = failureText(failure)
  } finally {
    busy.value = false
  }
}

/** The jobs that go with this one when it is cancelled: the putting on of the part that was to take the place of the one it takes off. */
function dependents(job: WorkshopJob): WorkshopJob[] {
  if (job.kind !== 'unmount') {
    return []
  }

  const later = jobs.value.slice(jobs.value.findIndex((other) => other.id === job.id) + 1)

  return later.filter((other) => other.kind === 'mount' && other.part?.slot === job.part?.slot)
}

async function cancel(jobId: number): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  error.value = null
  clearCancelled()

  try {
    const gone = await store.cancelJob(jobId)
    now.value = store.serverNow()

    // The part of a job that went with the one asked for, or the price of a job that was given back (one that had not started: it was paid when it was queued).
    const [asked, withIt] = gone
    const refund = asked && asked.status === 'queued' ? asked.cost_cents : 0
    showCancelled(
      withIt ? t('workshop.cancelledWith', { part: withIt.part?.name ?? '' }) : refund > 0 ? t('workshop.cancelledRefund', { price: money(refund, 2) }) : t('workshop.cancelled'),
    )
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    error.value = failureText(failure)

    // The job was not there any more, or was done in the meantime: the queue shown is stale.
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
      <h1 class="ttl">{{ t('workshop.title') }}</h1>
      <p v-if="store.garage" class="sub" data-test="city">{{ t('workshop.city', { city: store.garage.workshop.city.name }) }}</p>
      <p v-if="store.garage" class="sub" data-test="mechanic">
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
      <p v-if="standing" class="card card-hi text-sm" role="status" data-test="standing">
        {{ t('workshop.standing') }}
      </p>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>
      <p v-if="cancelled" class="card card-cy text-sm" role="status" data-test="cancelled">{{ cancelled }}</p>

      <section class="card flex flex-col gap-1.5 !p-3" aria-labelledby="mechanic-title" data-test="mechanic-card">
        <div class="flex items-center justify-between gap-2">
          <p id="mechanic-title" class="text-[15px] font-bold text-ink">
            {{ t('workshop.mechanicTitle') }}
            <span class="sub font-normal">{{ t('workshop.level', { level: store.garage.workshop.mechanic.level }) }} · −{{ cut }}%</span>
          </p>
        </div>

        <template v-if="working">
          <p class="text-[13px] text-ink" :data-test="`job-${working.id}`">{{ jobText(working) }}</p>
          <div class="bar bar-cy" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="progress(working)" data-test="job-bar">
            <i :style="{ width: `${progress(working)}%` }" />
          </div>
          <p class="flex justify-between text-xs text-muted" :data-test="`job-${working.id}-state`">
            <span class="mono text-ink">{{ clock(now - working.start_ms) }} / {{ clock(working.finish_ms - working.start_ms) }}</span>
            <span>{{ `${t('workshop.working')}: ${t('workshop.left', { time: left(working) })}` }}</span>
          </p>
          <JobCancel :key="working.id" class="self-start" :job="working" :dependents="dependents(working)" :disabled="busy" @cancel="cancel" />
        </template>
        <p v-else class="sub" data-test="mechanic-idle">{{ t('workshop.idle') }}</p>
      </section>

      <section class="flex flex-col gap-2" aria-labelledby="queue-title" data-test="queue">
        <h2 id="queue-title" class="kick !mb-0">{{ t('workshop.queue', { count: jobs.length, max: store.garage.workshop.queue_max }) }}</h2>

        <p v-if="jobs.length === 0" class="sub" data-test="queue-empty">{{ t('workshop.queueEmpty') }}</p>

        <ol v-else class="flex flex-col gap-2">
          <li
            v-for="job in waiting"
            :key="job.id"
            class="card flex flex-wrap items-center justify-between gap-2.5 !p-2.5"
            :data-test="`job-${job.id}`"
          >
            <div class="min-w-0">
              <p class="text-[13px] text-ink">{{ jobText(job) }}</p>
              <p class="sub" :data-test="`job-${job.id}-state`">{{ t('workshop.waiting') }}</p>
            </div>
            <span class="chip">{{ t('workshop.eta', { time: clock(job.finish_ms - now) }) }}</span>
            <JobCancel :job="job" :dependents="dependents(job)" :disabled="busy" @cancel="cancel" />
          </li>
        </ol>
      </section>

      <section v-if="merges.length > 0" class="flex flex-col gap-2" aria-labelledby="merges-title" data-test="merges">
        <h2 id="merges-title" class="kick !mb-0">{{ t('workshop.mergesTitle') }}</h2>
        <ul class="flex flex-col gap-2">
          <li
            v-for="result in merges"
            :key="result.id"
            class="card flex flex-col gap-1 !p-3"
            :style="result.success ? { borderColor: `var(--color-r${rankOf(result.to)})` } : undefined"
            :data-success="result.success"
            :data-test="`merge-result-${result.id}`"
          >
            <p class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[13px] font-semibold" :class="result.success ? 'text-ink' : 'text-danger'">
              {{ resultText(result) }}
              <RarityMark :rarity="result.to" />
              <span class="tag">{{ t('part.tier', { tier: result.tier }) }}</span>
            </p>
            <p class="sub">{{ t(`slot.${result.slot}`) }} · {{ t('workshop.mergeChance', { chance: percent(result.chance_ppm / 10_000) }) }}</p>
            <p v-if="!result.success" class="sub" data-test="merge-result-scrap">{{ t('workshop.mergeScrap', { scrap: money(result.scrap_cents, 2) }) }}</p>
          </li>
        </ul>
      </section>

      <section class="flex flex-col gap-3" aria-labelledby="car-title" data-test="car">
        <div class="flex items-start justify-between gap-3">
          <h2 id="car-title" class="ttl text-2xl">{{ car.vehicle.name }}</h2>
          <span class="chip chip-am" data-test="car-level">{{ t('workshop.level', { level: car.level }) }}</span>
        </div>

        <div class="flex flex-col gap-1" :aria-label="t('workshop.stats')">
          <div v-for="stat in STATS" :key="stat" class="st" :data-test="`stat-${stat}`">
            <span>{{ t(`carStat.${stat}`) }}</span>
            <div class="bar"><i :style="{ width: `${statFill(car, stat)}%` }" /></div>
            <span>
              {{ number(car.stats[stat], 1) }}
              <em v-if="delta(stat) !== 0" :class="{ dn: delta(stat) < 0 }" :data-test="`delta-${stat}`">{{ delta(stat) > 0 ? '+' : '−' }}{{ number(Math.abs(delta(stat)), 1) }}</em>
            </span>
          </div>
        </div>

        <!-- The odometer, the service and how often the car breaks down; the service is a job on the car, paid when it is queued. -->
        <h3 class="kick !mb-0 mt-1">{{ t('workshop.conditionTitle') }}</h3>
        <div class="card flex flex-col gap-1.5 !p-3" data-test="condition">
          <p class="text-[13px] text-ink" data-test="mileage">{{ t('workshop.mileage', { km: number(car.mileage_km) }) }}</p>
          <p class="text-[13px]" :class="car.service.due_in_km < 0 ? 'text-danger' : 'text-ink'" data-test="service-line">{{ serviceLine(car.service) }}</p>
          <p v-if="breakdownLine(car)" class="sub" data-test="breakdown-line">{{ breakdownLine(car) }}</p>
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || servicing" data-test="service" @click="act('service', null)">
              {{ t('workshop.serviceButton') }}
            </button>
            <span class="sub" data-test="service-info">{{ servicing ? t('workshop.serviceWaits') : t('workshop.serviceCost', { price: money(car.service.cost_cents, 2), time: formatDuration(car.service.duration_ms) }) }}</span>
          </div>
        </div>

        <h3 class="kick !mb-0 mt-1">{{ t('workshop.slots') }}</h3>
        <ul class="grid grid-cols-2 gap-2">
          <li
            v-for="slot in car.slots"
            :key="slot.slot"
            class="tile"
            :class="[slot.part ? 'on' : '', slot.part && slot.part.rarity_rank > 1 ? `r${slot.part.rarity_rank}` : '']"
            :data-test="`slot-${slot.slot}`"
          >
            <span class="sl">{{ t(`slot.${slot.slot}`) }}</span>
            <template v-if="slot.part">
              <span class="nm">
                {{ slot.part.name }}
                <span class="mono ml-1 text-[10px] text-faint">{{ t('part.tier', { tier: slot.part.tier ?? 0 }) }}</span>
              </span>
              <span v-if="slot.part.rarity_rank > 1 || slot.part.upgrade > 0" class="flex flex-wrap items-center gap-1.5">
                <RarityMark v-if="slot.part.rarity_rank > 1" :rarity="slot.part.rarity" />
                <UpgradeMark :level="slot.part.upgrade" />
              </span>
              <PartCondition :percent="slot.part.condition_percent" />
              <!-- Putting a worn part right: paid now, and the car stands while the mechanic is at it. A part that is worn out has to be replaced, and says so. -->
              <template v-if="canRepair(slot.part.repair_next) || slot.part.repair_next.locked?.reason === 'worn_out'">
                <button
                  v-if="canRepair(slot.part.repair_next)"
                  type="button"
                  class="btn btn-primary btn-sm mt-1.5 !min-h-9 !text-base"
                  :disabled="busy || busyPartIds.has(slot.part.id)"
                  :data-test="`repair-${slot.part.id}`"
                  @click="act('repair', slot.part)"
                >
                  {{ t('workshop.repairAction') }}
                </button>
                <p class="sub mt-1" :data-test="`repair-info-${slot.part.id}`">{{ repairInfo(slot.part.repair_next) }}</p>
              </template>
              <!-- The next step of the upgrade: paid now, and the car stands while the mechanic is at it. A part that is as far as it goes says so instead of offering it. -->
              <template v-if="slot.part.upgrade_next.locked?.reason !== 'unavailable'">
                <button
                  v-if="slot.part.upgrade_next.to !== null"
                  type="button"
                  class="btn btn-primary btn-sm mt-1.5 !min-h-9 !text-base"
                  :disabled="busy || busyPartIds.has(slot.part.id) || slot.part.upgrade_next.locked !== null"
                  :data-test="`upgrade-${slot.part.id}`"
                  @click="act('upgrade', slot.part)"
                >
                  {{ t('workshop.upgradeTo', { to: slot.part.upgrade_next.to }) }}
                </button>
                <p class="sub mt-1" :data-test="`upgrade-info-${slot.part.id}`">{{ upgradeInfo(slot.part.upgrade_next) }}</p>
              </template>
              <button
                type="button"
                class="btn btn-secondary btn-sm mt-1.5 !min-h-9 !text-base"
                :disabled="busy || busyPartIds.has(slot.part.id)"
                :data-test="`take-off-${slot.part.id}`"
                @click="act('unmount', slot.part)"
              >
                {{ t('workshop.takeOff') }}
              </button>
            </template>
            <span v-else-if="slot.built_in" class="nm">{{ car.vehicle.engine ?? t('workshop.builtIn') }}</span>
            <span v-else class="nm text-faint">{{ t('workshop.empty') }}</span>
          </li>
        </ul>
      </section>

      <section class="flex flex-col gap-2" aria-labelledby="inventory-title" data-test="inventory">
        <h2 id="inventory-title" class="kick !mb-0">{{ t('workshop.inventory') }}</h2>

        <p v-if="inventory.length === 0" class="sub" data-test="inventory-empty">{{ t('workshop.inventoryEmpty') }}</p>

        <ul v-else class="flex flex-col gap-2">
          <li v-for="part in inventory" :key="part.id" class="card flex flex-wrap items-start justify-between gap-2.5 !p-3" :data-test="`part-${part.id}`">
            <div class="min-w-0">
              <p class="flex items-center gap-2 text-[15px] font-bold text-ink">
                {{ part.name }}
                <span class="tag">{{ t('part.tier', { tier: part.tier ?? 0 }) }}</span>
              </p>
              <p class="sub">{{ t(`slot.${part.slot}`) }}</p>
              <p class="my-1 flex flex-wrap items-center gap-2">
                <RarityMark :rarity="part.rarity" />
                <UpgradeMark :level="part.upgrade" />
              </p>
              <PartStats :part="part" />
              <PartCondition :percent="part.condition_percent" class="mt-1.5" />
              <p v-if="part.repair_next?.locked?.reason === 'worn_out'" class="sub mt-1" :data-test="`repair-info-${part.id}`">{{ t('workshop.repairWornOut') }}</p>
            </div>
            <div class="flex shrink-0 flex-col gap-1.5">
              <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="busy || busyPartIds.has(part.id)"
                :data-tutorial="part.id === mountTarget ? 'mount' : undefined"
                :data-test="`put-on-${part.id}`"
                @click="act('mount', part)"
              >
                {{ t('workshop.putOn') }}
              </button>
              <!-- A worn part of the inventory is put right without the car: the repair costs and takes what its condition says. -->
              <button
                v-if="canRepair(part.repair_next)"
                type="button"
                class="btn btn-secondary btn-sm"
                :disabled="busy || busyPartIds.has(part.id)"
                :data-test="`repair-${part.id}`"
                @click="act('repair', part)"
              >
                {{ t('workshop.repairAction') }}
              </button>
              <p v-if="canRepair(part.repair_next)" class="sub" :data-test="`repair-info-${part.id}`">{{ repairInfo(part.repair_next) }}</p>
              <!-- Merging two parts of one slot, tier and rarity into one of the next rarity: the server says what a part can be merged with. -->
              <button
                v-if="part.merge"
                type="button"
                class="btn btn-secondary btn-sm"
                :disabled="busy || busyPartIds.has(part.id)"
                :aria-expanded="merging === part.id"
                :data-test="`merge-open-${part.id}`"
                @click="merging = merging === part.id ? null : part.id"
              >
                {{ t('workshop.mergeOpen') }}
              </button>
            </div>
            <PartMerge
              v-if="part.merge && merging === part.id"
              :part="part"
              :offer="part.merge"
              :partners="partnersOf(part)"
              :disabled="busy"
              @merge="(other) => act('merge', part, other)"
              @close="merging = null"
            />
          </li>
        </ul>
      </section>
    </template>
  </GameShell>
</template>
