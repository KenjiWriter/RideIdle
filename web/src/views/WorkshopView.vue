<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { ApiError } from '@/api/client'
import type { CarStatKey, EquipmentItem, EquipmentKey, JobKind, MergeResult, OwnedPart, RepairNext, SlotJobNext, UpgradeNext, WorkshopJob } from '@/api/types'
import CarHotspots from '@/components/CarHotspots.vue'
import CarStage from '@/components/CarStage.vue'
import GameShell from '@/components/GameShell.vue'
import JobCancel from '@/components/JobCancel.vue'
import PartCompare from '@/components/PartCompare.vue'
import PartCondition from '@/components/PartCondition.vue'
import PartMerge from '@/components/PartMerge.vue'
import PartAffixes from '@/components/PartAffixes.vue'
import MediaPicture from '@/components/MediaPicture.vue'
import PartStats from '@/components/PartStats.vue'
import PartVerdict from '@/components/PartVerdict.vue'
import SlotChips from '@/components/SlotChips.vue'
import SlotDetail from '@/components/SlotDetail.vue'
import SpecCard from '@/components/SpecCard.vue'
import SpeedupButton from '@/components/SpeedupButton.vue'
import RarityMark from '@/components/RarityMark.vue'
import UpgradeMark from '@/components/UpgradeMark.vue'
import { carPicture } from '@/features/art'
import { overallScore } from '@/features/car/score'
import { useServiceLine } from '@/features/car/serviceLine'
import { statFill } from '@/features/car/statFill'
import { useCarClass } from '@/features/car/useCarClass'
import { useCarSpots } from '@/features/car/useCarSpots'
import { useSlotText } from '@/features/car/useSlotText'
import { comparePart, type Verdict } from '@/features/parts/compare'
import { rankOf } from '@/features/parts/rarity'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { lockText } from '@/features/parts/lock'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useModel } from '@/model'
import { useGarageStore } from '@/stores/garage'
import { useRunStore } from '@/stores/run'
import { useTutorialStore } from '@/stores/tutorial'

/** How often an open workshop asks the server for the state of the jobs, besides asking when one is due. */
const WORKSHOP_POLL_MS = 15_000

/** The garage is asked again at the earliest this long after the last answer, so a job that is late cannot cause a storm of requests. */
const MIN_ASK_MS = 3_000

/** The statistics that the screens show, in the order of the model of the game, and what a slot is to the formulas. */
const { stats: STATS, roleOf } = useModel()

const { t } = useI18n()
const { number, money, percent, fixed } = useFormat()
const store = useGarageStore()
const run = useRunStore()
const tutorial = useTutorialStore()
const { serviceLine, breakdownLine } = useServiceLine()
const { className } = useCarClass()
const { slotName } = useSlotText()

useLeaveOnSignOut()

const loadError = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const { message: cancelled, show: showCancelled, clear: clearCancelled } = useFlash()
const { message: bought, show: showBought, clear: clearBought } = useFlash()
const { message: finished, show: showFinished, clear: clearFinished } = useFlash()
/** Why a purchase of equipment failed: shown in the card of the equipment, because the page may be scrolled far from the top. */
const gearError = ref<string | null>(null)
/** The card of the equipment is folded away until the driver wants it. */
const gearOpen = ref(false)

/** What time it is for the server, to the second: the clock of the countdown. */
const now = ref(store.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined
let poller: ReturnType<typeof setInterval> | undefined
let lastAsk = 0

const car = computed(() => store.garage?.car ?? null)
const jobs = computed(() => store.garage?.workshop.jobs ?? [])
/** Every job a mechanic is at: several are done at once when the workshop has bays and mechanics for them. */
const workingJobs = computed(() => jobs.value.filter((job) => job.status === 'working'))
/**
 * The cash of the driver as the screens last saw it: the run's wallet (live while the car drives) and the one of the last answer of the garage (current after a
 * purchase). The larger of the two is used, so that a figure that is a little old never shuts a purchase the driver can make; the server has the last word. Null when
 * nothing has told it yet.
 */
const cash = computed(() => {
  const seen = [run.wallet?.cash_cents, store.cash?.cash_cents].filter((cents): cents is number => typeof cents === 'number')

  return seen.length === 0 ? null : Math.max(...seen)
})
const waiting = computed(() => jobs.value.filter((job) => job.status === 'queued'))
const inventory = computed(() => store.garage?.inventory ?? [])
/**
 * The part that the tutorial points at when it asks for the tires to be put on (the tires are the slot with the role, whatever it is called). A step that names a kind of tires (the off-road ones) points at the tires of that
 * kind, and at nothing when there are none in the inventory, so that it never points at the wrong pair; a step that names none points at the first pair of tires in
 * the inventory, or the first part.
 */
const mountTarget = computed(() => {
  const kind = tutorial.goal?.tire_kind ?? tutorial.current?.tire_kind ?? null

  if (kind !== null) {
    return inventory.value.find((part) => roleOf(part.slot) === 'tires' && part.tire_kind === kind)?.id ?? null
  }

  return (inventory.value.find((part) => roleOf(part.slot) === 'tires') ?? inventory.value[0])?.id ?? null
})
/** The card of the mechanic, which a job that is queued is shown on: the page is taken to it, because the parts are far below it. */
const mechanicCard = ref<HTMLElement | null>(null)
const standing = computed(() => car.value?.run?.stop_reason === 'workshop')
/** The parts that wait in a job: a merge keeps the two of them busy, and a service is a job on the car, with no part. */
const busyPartIds = computed(() => new Set(jobs.value.flatMap((job) => [job.part?.id, job.merge_with?.id].filter((id): id is number => id !== undefined))))
/** A service that waits for the mechanic or is done by him: there is no use in asking for a second. */
const servicing = computed(() => jobs.value.some((job) => job.kind === 'service'))
const merges = computed(() => store.garage?.merges ?? [])
/** The part of the inventory whose chooser of a merge is open. */
const merging = ref<number | null>(null)
const cut = computed(() => Math.round((store.garage?.workshop.mechanic.time_cut_ppm ?? 0) / 10_000))

/** How good the car is as a whole, for the card of its specification. */
const score = computed(() => overallScore(car.value, STATS.value))

/**
 * The car stands on a stage with its parts on it, as in the garage: a tap on a part says what is fitted and what can be done with it, and the inventory below shows the
 * parts that fit that slot. A car with no picture has the outline of a body, and its slots are a row under the stage.
 */
const hasPicture = computed(() => !!(car.value && (car.value.vehicle.image_url || carPicture(car.value.vehicle.slug))))
const { pickedSlot, picked, mounted, parts, pick, clear: clearPick } = useCarSpots(() => (car.value ? { slots: car.value.slots, slug: car.value.vehicle.slug, hasPicture: hasPicture.value } : null))
const pickedPart = computed(() => picked.value?.part ?? null)

/** What the workshop is doing on each slot, for the spots on the car: how far the job is while the mechanic is at it, null while it waits (a service is on no slot). */
const marks = computed(() => {
  const byslot: Record<string, number | null> = {}

  for (const job of jobs.value) {
    const slot = job.part?.slot

    if (!slot) {
      continue
    }

    if (job.status === 'working') {
      byslot[slot] = progress(job)
    } else if (!(slot in byslot)) {
      byslot[slot] = null
    }
  }

  return byslot
})

/** What the tutorial points at on this screen, if it does: the step that waits, else the goal in front of the driver. */
const tutorialTarget = computed(() => tutorial.current?.target ?? tutorial.goal?.target ?? null)

/**
 * The slot whose parts the inventory shows: the one that was tapped on the car, and none (all of them) while the tutorial points at a part of the inventory (the tires to
 * put on, the two parts to merge), so that it never points at something that the filter has hidden.
 */
const inventoryFilter = computed(() => (tutorialTarget.value === 'mount' || tutorialTarget.value === 'merge' ? null : pickedSlot.value))
/** The part that is fitted on the slot of a part of the inventory, to set it against; none when the slot holds the factory part. */
function fittedOf(part: OwnedPart): { name: string; stats: Record<string, number>; surfaces?: Record<string, number> | null } | null {
  const fitted = car.value?.slots.find((slot) => slot.slot === part.slot)?.part

  return fitted ? { name: fitted.name, stats: fitted.stats, surfaces: fitted.surfaces } : null
}

/**
 * Whether a part of the inventory is worth fitting in place of the one on the car: null when the slot holds the factory part, when there is nothing to set against, and
 * while the car cannot take the part (the comparison is still shown, but nobody is told to fit what the workshop would refuse).
 */
function verdictOf(part: OwnedPart): Verdict | null {
  const fitted = fittedOf(part)

  return fitted && !part.mount_next?.locked ? (comparePart(part, fitted)?.verdict ?? null) : null
}

const shownParts = computed(() => (inventoryFilter.value === null ? inventory.value : inventory.value.filter((part) => part.slot === inventoryFilter.value)))

// The step of the tutorial that points at the upgrade points at a button on the card of a part, so the slot of a part that can be upgraded is looked at when it comes.
// It is looked at when the step or the car changes, and not whenever the garage is asked again, so a slot that the driver put away stays away.
watch(
  [tutorialTarget, () => car.value?.id],
  ([target]) => {
    if (target === 'upgrade' && pickedSlot.value === null) {
      const slot = car.value?.slots.find((candidate) => candidate.part && candidate.part.upgrade_next.to !== null) ?? car.value?.slots.find((candidate) => candidate.part)

      if (slot) {
        pick(slot.slot)
      }
    }
  },
  { immediate: true },
)

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

  // A job whose time has come is carried out by the server when it is asked, and the step of the tutorial that waited for it is done with it: the goal is asked for too.
  if (jobs.value.some((job) => job.finish_ms <= now.value) && Date.now() - lastAsk >= MIN_ASK_MS) {
    void load().then(() => tutorial.load().catch(() => undefined))
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

/** What putting a part on or taking it off costs and takes: the fee of the vulcanizer where there is one, and the time. */
function slotJobInfo(next: SlotJobNext): string {
  const time = formatDuration(next.duration_ms)

  return next.cost_cents > 0 ? t('workshop.jobFee', { price: money(next.cost_cents, 2), time }) : t('workshop.jobTime', { time })
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
    // The job is on the card of the mechanic ("Putting on: …"), at the top: the driver who pressed the button down in the inventory is taken there.
    await nextTick()
    mechanicCard.value?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' })
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

/** The name of the level of an item of equipment, in words. */
function levelName(item: EquipmentItem, name: string): string {
  return t(`workshop.gear.level.${item.key}.${name}`)
}

/** What a level does to the time of a job, in words: the usual time, or the factor it is multiplied by (×0.50). */
function effectText(ppm: number): string {
  return ppm === 1_000_000 ? t('workshop.gear.effectBase') : t('workshop.gear.effect', { factor: fixed(ppm / 1_000_000, 2) })
}

/** The slots an item works on, or that the toolkit works on every job. */
function slotsText(item: EquipmentItem): string {
  return item.slots === null ? t('workshop.gear.slotsAll') : t('workshop.gear.slots', { slots: item.slots.map((slot) => t(`slot.${slot}`)).join(', ') })
}

/** Whether the driver has the cash for a price; a driver the screens know nothing about is let try, and the server decides. */
function canAfford(priceCents: number): boolean {
  return cash.value === null || cash.value >= priceCents
}

/** Buys the next level of an item, or one more bay or mechanic. `name` is what is bought, in words, for the message. */
async function buyGear(item: EquipmentKey, name: string): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  gearError.value = null
  clearBought()

  try {
    const made = await store.buyEquipment(item)
    now.value = store.serverNow()
    showBought(made ? t('workshop.gear.bought', { name }) : t('workshop.gear.repeated'))
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    gearError.value = failureText(failure)
  } finally {
    busy.value = false
  }
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

/** Finishes the job the mechanic is at, now, for premium coins: the driver has said yes to the price. */
async function finish(jobId: number): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  error.value = null
  clearFinished()
  clearCancelled()

  try {
    const spent = await store.finishJob(jobId)
    now.value = store.serverNow()
    showFinished(t('speedup.done', { coins: spent }))
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    error.value = failureText(failure)

    // The job was done in the meantime, or the coins were too few: what is shown is stale.
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
      <p v-if="finished" class="card card-cy text-sm" role="status" data-test="finished">{{ finished }}</p>

      <!-- The car on its stage, as in the garage: its parts are on it, and the ones the mechanic is at have a ring with the progress. -->
      <CarStage :name="car.vehicle.name" :src="car.vehicle.image_url" :slug="car.vehicle.slug">
        <template #spots>
          <CarHotspots v-if="parts.placed.length > 0" :placed="parts.placed" :picked="pickedSlot" :engine="car.vehicle.engine" :marks="marks" @pick="pick" />
        </template>
        <span class="chip chip-am !h-6 !text-[11px]" data-test="car-level">{{ t('workshop.level', { level: car.level }) }}</span>
      </CarStage>

      <SlotChips :slots="parts.unplaced" :picked="pickedSlot" @pick="pick" />

      <section ref="mechanicCard" class="card flex scroll-mt-32 flex-col gap-1.5 !p-3" aria-labelledby="mechanic-title" data-test="mechanic-card">
        <div class="flex items-center justify-between gap-2">
          <p id="mechanic-title" class="text-[15px] font-bold text-ink">
            {{ t('workshop.mechanicTitle') }}
            <span class="sub font-normal">{{ t('workshop.level', { level: store.garage.workshop.mechanic.level }) }} · −{{ cut }}%</span>
          </p>
        </div>

        <template v-if="workingJobs.length > 0">
          <p v-if="store.garage.workshop.gear.workers > 1" class="sub" data-test="at-work">
            {{ t('workshop.atWork', { working: workingJobs.length, workers: store.garage.workshop.gear.workers }) }}
          </p>
          <div
            v-for="(working, index) in workingJobs"
            :key="working.id"
            class="flex flex-col gap-1.5"
            :class="index > 0 ? 'border-t border-line pt-2' : ''"
            :data-test="`working-${working.id}`"
          >
            <p class="text-[13px] text-ink" :data-test="`job-${working.id}`">{{ jobText(working) }}</p>
            <div class="bar bar-cy" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="progress(working)" data-test="job-bar">
              <i :style="{ width: `${progress(working)}%` }" />
            </div>
            <p class="flex justify-between text-xs text-muted" :data-test="`job-${working.id}-state`">
              <span class="mono text-ink">{{ clock(now - working.start_ms) }} / {{ clock(working.finish_ms - working.start_ms) }}</span>
              <span>{{ `${t('workshop.working')}: ${t('workshop.left', { time: left(working) })}` }}</span>
            </p>
            <div class="flex flex-wrap items-start gap-2">
              <JobCancel :key="working.id" :job="working" :dependents="dependents(working)" :disabled="busy" @cancel="cancel" />
              <!-- What finishing the job at once costs in premium coins: only for the job the mechanic is at, and only when the game sells it. -->
              <SpeedupButton
                v-if="working.speedup"
                :coins="working.speedup.coins"
                :balance="store.premiumCoins"
                :label="t('speedup.finishNow')"
                :disabled="busy"
                :test-id="`finish-${working.id}`"
                @confirm="finish(working.id)"
              />
            </div>
          </div>
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

      <!-- What is fitted on the slot that was tapped, and what the workshop can do with it. A part that waits in a job cannot be touched again. -->
      <SlotDetail :slot="picked" :engine="car.vehicle.engine" :mounted="mounted" :total="car.slots.length" :link="false" @close="clearPick">
        <div v-if="pickedPart" class="flex flex-wrap items-start gap-2" data-test="slot-actions">
          <!-- Putting a worn part right: paid now, and the car stands while the mechanic is at it. A part that is worn out has to be replaced, and says so. -->
          <div v-if="canRepair(pickedPart.repair_next) || pickedPart.repair_next.locked?.reason === 'worn_out'" class="flex min-w-[9rem] flex-1 flex-col gap-1">
            <button
              v-if="canRepair(pickedPart.repair_next)"
              type="button"
              class="btn btn-primary btn-sm"
              :disabled="busy || busyPartIds.has(pickedPart.id)"
              :data-test="`repair-${pickedPart.id}`"
              @click="act('repair', pickedPart)"
            >
              {{ t('workshop.repairAction') }}
            </button>
            <p class="sub" :data-test="`repair-info-${pickedPart.id}`">{{ repairInfo(pickedPart.repair_next) }}</p>
          </div>

          <!-- The next step of the upgrade: paid now, and the car stands while the mechanic is at it. A part that is as far as it goes says so instead of offering it. -->
          <div v-if="pickedPart.upgrade_next.locked?.reason !== 'unavailable'" class="flex min-w-[9rem] flex-1 flex-col gap-1">
            <button
              v-if="pickedPart.upgrade_next.to !== null"
              type="button"
              class="btn btn-primary btn-sm"
              :disabled="busy || busyPartIds.has(pickedPart.id) || pickedPart.upgrade_next.locked !== null"
              data-tutorial="upgrade"
              :data-test="`upgrade-${pickedPart.id}`"
              @click="act('upgrade', pickedPart)"
            >
              {{ t('workshop.upgradeTo', { to: pickedPart.upgrade_next.to }) }}
            </button>
            <p class="sub" :data-test="`upgrade-info-${pickedPart.id}`">{{ upgradeInfo(pickedPart.upgrade_next) }}</p>
          </div>

          <div class="flex min-w-[9rem] flex-1 flex-col gap-1">
            <button
              type="button"
              class="btn btn-secondary btn-sm"
              :disabled="busy || busyPartIds.has(pickedPart.id) || !!pickedPart.unmount_next?.locked"
              :data-test="`take-off-${pickedPart.id}`"
              @click="act('unmount', pickedPart)"
            >
              {{ t('workshop.takeOff') }}
            </button>
            <!-- An engine that the booster on the car needs cannot come out: the factory engine has too few cylinders for it. -->
            <p v-if="pickedPart.unmount_next?.locked" class="sub text-danger" :data-test="`take-off-lock-${pickedPart.id}`">{{ lockText(t, pickedPart.unmount_next.locked, fixed) }}</p>
            <p v-else-if="pickedPart.unmount_next" class="sub" :data-test="`take-off-info-${pickedPart.id}`">{{ slotJobInfo(pickedPart.unmount_next) }}</p>
          </div>
        </div>
      </SlotDetail>

      <section class="flex flex-col gap-2" aria-labelledby="inventory-title" data-test="inventory">
        <div class="flex items-center justify-between gap-2">
          <h2 id="inventory-title" class="kick !mb-0">{{ t('workshop.inventory') }}</h2>
          <!-- The inventory shows the parts that fit the slot that was tapped: one tap puts all of them back. -->
          <button v-if="inventoryFilter !== null" type="button" class="chip chip-am cursor-pointer" data-test="inventory-filter" @click="clearPick">
            {{ t('workshop.filteredBy', { slot: slotName(inventoryFilter) }) }}
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" /></svg>
          </button>
        </div>

        <p v-if="inventory.length === 0" class="sub" data-test="inventory-empty">{{ t('workshop.inventoryEmpty') }}</p>

        <div v-else-if="shownParts.length === 0" class="card flex flex-wrap items-center justify-between gap-2.5 !p-3" data-test="inventory-none">
          <p class="sub">{{ t('workshop.noneForSlot', { slot: slotName(inventoryFilter ?? '') }) }}</p>
          <RouterLink :to="{ name: 'shop' }" class="btn btn-secondary btn-sm no-underline" data-test="inventory-shop">{{ t('workshop.toShop') }}</RouterLink>
        </div>

        <ul v-else class="flex flex-col gap-2">
          <li v-for="part in shownParts" :key="part.id" class="card flex flex-wrap items-start justify-between gap-2.5 !p-3" :data-test="`part-${part.id}`">
            <div class="flex min-w-0 gap-2.5">
              <MediaPicture :src="part.image_url" :alt="part.name" class="h-14 w-14 flex-none rounded-md border border-line-2 object-cover" data-test="part-picture" />
              <div class="min-w-0">
              <p class="flex items-center gap-2 text-[15px] font-bold text-ink">
                {{ part.name }}
                <span class="tag">{{ t('part.tier', { tier: part.tier ?? 0 }) }}</span>
              </p>
              <p class="sub">{{ t(`slot.${part.slot}`) }}</p>
              <!-- Worth fitting in place of the part on the car or not, and what is better and what is worse. -->
              <PartVerdict v-if="verdictOf(part)" :verdict="verdictOf(part)!" class="mt-1" />
              <p class="my-1 flex flex-wrap items-center gap-2">
                <RarityMark :rarity="part.rarity" />
                <UpgradeMark :level="part.upgrade" />
              </p>
              <PartStats :part="part" />
              <PartAffixes :part="part" />
              <PartCompare :offer="part" :fitted="fittedOf(part)" />
              <PartCondition :percent="part.condition_percent" class="mt-1.5" />
              <p v-if="part.repair_next?.locked?.reason === 'worn_out'" class="sub mt-1" :data-test="`repair-info-${part.id}`">{{ t('workshop.repairWornOut') }}</p>
              </div>
            </div>
            <!-- A long reason (the engine bay says what it does not take) wraps inside the card instead of pushing it out of the screen. -->
            <div class="flex max-w-full shrink-0 flex-col gap-1.5">
              <div class="relative">
                <button
                  type="button"
                  class="btn btn-primary btn-sm w-full"
                  :disabled="busy || busyPartIds.has(part.id) || !!part.mount_next?.locked"
                  :data-tutorial="part.id === mountTarget ? 'mount' : undefined"
                  :data-test="`put-on-${part.id}`"
                  @click="act('mount', part)"
                >
                  {{ t('workshop.putOn') }}
                </button>
                <!-- A part that is worth fitting has an arrow on its button. -->
                <span v-if="verdictOf(part)?.kind === 'better'" class="worth-flag" aria-hidden="true" :data-test="`worth-${part.id}`">▲</span>
              </div>
              <!-- A part the car cannot take (too few cylinders, no booster for an intercooler) says why instead of the price: the job would be refused. -->
              <p v-if="part.mount_next?.locked" class="sub text-danger" :data-test="`put-on-lock-${part.id}`">{{ lockText(t, part.mount_next.locked, fixed) }}</p>
              <p v-else-if="part.mount_next" class="sub" :data-test="`put-on-info-${part.id}`">{{ slotJobInfo(part.mount_next) }}</p>
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
                data-tutorial="merge"
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
      <SpecCard :name="car.vehicle.name" :kicker="className(car.vehicle.class)" :tier="car.vehicle.tier ?? null" :score="score" level="h2" heading-id="car-title">
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
          <button type="button" class="btn btn-secondary btn-sm" :disabled="busy || servicing" data-tutorial="service" data-test="service" @click="act('service', null)">
            {{ t('workshop.serviceButton') }}
          </button>
          <span class="sub" data-test="service-info">{{ servicing ? t('workshop.serviceWaits') : t('workshop.serviceCost', { price: money(car.service.cost_cents, 2), time: formatDuration(car.service.duration_ms) }) }}</span>
        </div>
      </div>
      </SpecCard>

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
            <PartAffixes v-if="result.success && result.part" :part="result.part" />
          </li>
        </ul>
      </section>

      <!-- The equipment of the workshop of this city: what is bought here speeds up the jobs queued after it. There is no board for this card; it uses the cards of the screen. -->
      <section class="flex flex-col gap-2" aria-labelledby="gear-title" data-tutorial="equipment" data-test="gear">
        <div class="flex items-center justify-between gap-2">
          <h2 id="gear-title" class="kick !mb-0">{{ t('workshop.gear.title') }}</h2>
          <button type="button" class="btn btn-secondary btn-sm" :aria-expanded="gearOpen" aria-controls="gear-body" data-test="gear-toggle" @click="gearOpen = !gearOpen">
            {{ gearOpen ? t('workshop.gear.hide') : t('workshop.gear.show') }}
          </button>
        </div>
        <p class="sub" data-test="gear-summary">{{ t('workshop.gear.summary', { workers: store.garage.workshop.gear.workers, max: store.garage.workshop.gear.queue_max }) }}</p>
        <p v-if="bought" class="card card-cy text-sm" role="status" data-test="bought">{{ bought }}</p>
        <p v-if="gearError" class="text-danger" role="alert" data-test="gear-error">{{ gearError }}</p>

        <div v-if="gearOpen" id="gear-body" class="flex flex-col gap-2" data-test="gear-body">
          <p class="sub">{{ t('workshop.gear.note') }}</p>
          <p v-if="store.garage.workshop.gear.mechanics.count > store.garage.workshop.gear.bays.count" class="sub text-ink" data-test="gear-hint">{{ t('workshop.gear.needBay') }}</p>
          <p v-else-if="store.garage.workshop.gear.bays.count > store.garage.workshop.gear.mechanics.count" class="sub text-ink" data-test="gear-hint">{{ t('workshop.gear.needMechanic') }}</p>

          <ul class="flex flex-col gap-2">
            <li v-for="item in store.garage.workshop.gear.items" :key="item.key" class="card flex flex-col gap-1.5 !p-3" :data-test="`gear-${item.key}`">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="text-[15px] font-bold text-ink">{{ t(`workshop.gear.item.${item.key}`) }}</p>
                  <p class="text-[13px] text-ink" :data-test="`gear-${item.key}-level`">{{ levelName(item, item.name) }}</p>
                </div>
                <span class="pips shrink-0 pt-1.5 text-cyan" role="img" :aria-label="t('workshop.gear.levelOf', { level: item.level, max: item.max_level })">
                  <b v-for="n in item.max_level" :key="n" :class="{ on: n <= item.level }" />
                </span>
              </div>
              <p class="sub" :data-test="`gear-${item.key}-effect`">{{ effectText(item.time_ppm) }}</p>
              <p class="sub">{{ slotsText(item) }}</p>
              <p v-if="item.fee_cents > 0" class="sub text-ink" :data-test="`gear-${item.key}-fee`">{{ t('workshop.gear.fee', { fee: money(item.fee_cents, 2) }) }}</p>

              <template v-if="item.next">
                <p class="sub" :data-test="`gear-${item.key}-next`">
                  {{ t('workshop.gear.next', { name: levelName(item, item.next.name), factor: fixed(item.next.time_ppm / 1_000_000, 2) }) }}
                </p>
                <div class="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    :disabled="busy || !canAfford(item.next.price_cents)"
                    :aria-label="t('workshop.gear.buyLabel', { name: levelName(item, item.next.name) })"
                    :data-test="`buy-${item.key}`"
                    @click="buyGear(item.key, levelName(item, item.next.name))"
                  >
                    {{ t('workshop.gear.buy', { price: money(item.next.price_cents, 2) }) }}
                  </button>
                  <span v-if="!canAfford(item.next.price_cents)" class="sub text-danger" :data-test="`gear-${item.key}-short`">{{ t('workshop.gear.short', { price: money(item.next.price_cents, 2) }) }}</span>
                </div>
              </template>
              <p v-else class="sub text-ink" :data-test="`gear-${item.key}-max`">{{ t('workshop.gear.max') }}</p>
            </li>

            <!-- More bays and more mechanics: as many mechanics work at once as there are bays for them. -->
            <li v-for="kind in ['bays', 'mechanics'] as const" :key="kind" class="card flex flex-col gap-1.5 !p-3" :data-test="`gear-${kind}`">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="text-[15px] font-bold text-ink">{{ t(`workshop.gear.${kind}`) }}</p>
                  <p class="sub" :data-test="`gear-${kind}-count`">{{ t(`workshop.gear.${kind}Info`, { count: store.garage.workshop.gear[kind].count, max: store.garage.workshop.gear[kind].max }) }}</p>
                </div>
                <span class="pips shrink-0 pt-1.5 text-cyan" role="img" :aria-label="t('workshop.gear.levelOf', { level: store.garage.workshop.gear[kind].count, max: store.garage.workshop.gear[kind].max })">
                  <b v-for="n in store.garage.workshop.gear[kind].max" :key="n" :class="{ on: n <= store.garage.workshop.gear[kind].count }" />
                </span>
              </div>
              <template v-if="store.garage.workshop.gear[kind].next_price_cents !== null">
                <div class="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    :disabled="busy || !canAfford(store.garage.workshop.gear[kind].next_price_cents)"
                    :aria-label="t('workshop.gear.buyLabel', { name: t(`workshop.gear.what.${kind}`) })"
                    :data-test="`buy-${kind}`"
                    @click="buyGear(kind, t(`workshop.gear.what.${kind}`))"
                  >
                    {{ t('workshop.gear.buy', { price: money(store.garage.workshop.gear[kind].next_price_cents, 2) }) }}
                  </button>
                  <span v-if="!canAfford(store.garage.workshop.gear[kind].next_price_cents)" class="sub text-danger" :data-test="`gear-${kind}-short`">
                    {{ t('workshop.gear.short', { price: money(store.garage.workshop.gear[kind].next_price_cents, 2) }) }}
                  </span>
                </div>
              </template>
              <p v-else class="sub text-ink" :data-test="`gear-${kind}-max`">{{ t('workshop.gear.max') }}</p>
            </li>
          </ul>
        </div>
      </section>

    </template>
  </GameShell>
</template>
