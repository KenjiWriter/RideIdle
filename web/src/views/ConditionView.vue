<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { ApiError } from '@/api/client'
import type { GarageSlot, JobKind, MountedPart, RepairNext } from '@/api/types'
import GameShell from '@/components/GameShell.vue'
import { useServiceLine } from '@/features/car/serviceLine'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useModel } from '@/model'
import { useGarageStore } from '@/stores/garage'
import { useTutorialStore } from '@/stores/tutorial'

/**
 * Where a slot stands on the board, by what it is to the formulas: the parts that wear out first (the tires, the suspension, the brakes), then the slots that have no
 * role (the exhaust), then the engine group and the tank. Slots of the same rank stay in the order of the model.
 */
const BOARD_RANK: Record<string, number> = { tires: 0, suspension: 1, brakes: 2, engine: 4, boost: 5, gearbox: 6, cooling: 7, tank: 8 }
const NO_ROLE_RANK = 3

/** Under this much of its life a part is marked as needing a repair: it gives a fifth less than a new one (0.6 + 0.4 x 0.5 = 80%). */
const NEEDS_REPAIR_PERCENT = 50

const { t } = useI18n()
const { number, money } = useFormat()
const { roleOf } = useModel()
const store = useGarageStore()
const tutorial = useTutorialStore()
const { serviceLine, breakdownLine } = useServiceLine()
const { message: queued, show: showQueued, clear: clearQueued } = useFlash()

useLeaveOnSignOut()

const loadError = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

const car = computed(() => store.garage?.car ?? null)
const jobs = computed(() => store.garage?.workshop.jobs ?? [])
/** The parts that wait in a job: a second job on one of them would be refused. */
const busyPartIds = computed(() => new Set(jobs.value.flatMap((job) => [job.part?.id, job.merge_with?.id].filter((id): id is number => id !== undefined))))
const servicing = computed(() => jobs.value.some((job) => job.kind === 'service'))

/** The slots of the car in the order of the board. */
const rows = computed<GarageSlot[]>(() => {
  const rank = (row: GarageSlot): number => BOARD_RANK[row.role ?? roleOf(row.slot) ?? ''] ?? NO_ROLE_RANK

  return (car.value?.slots ?? [])
    .map((row, index) => ({ row, index }))
    .sort((a, b) => rank(a.row) - rank(b.row) || a.index - b.index)
    .map(({ row }) => row)
})

/** The parts that have worn, the most worn first. */
const worn = computed<MountedPart[]>(() =>
  rows.value
    .map((row) => row.part)
    .filter((part): part is MountedPart => part !== null && part.condition_percent < 100)
    .sort((a, b) => a.condition_percent - b.condition_percent),
)

/** The part the effect is told of: the most worn one, when it is worn enough to need a repair. */
const worst = computed(() => (worn.value[0] && worn.value[0].condition_percent < NEEDS_REPAIR_PERCENT ? worn.value[0] : null))

/** The part the repair button is for: the most worn one that can be put right and does not wait in a job. */
const target = computed(() => worn.value.find((part) => canRepair(part.repair_next) && !busyPartIds.value.has(part.id)) ?? null)

/** Whether a worn part can be put right: the server says what it costs, or why not. */
function canRepair(next: RepairNext): next is RepairNext & { cost_cents: number; duration_ms: number } {
  return next.locked === null && next.cost_cents !== null && next.duration_ms !== null
}

/** What stands under the repair button: the price and the time of the repair, or why there is nothing to do. */
const repairInfo = computed(() => {
  const part = target.value

  if (part !== null && canRepair(part.repair_next)) {
    return t('condition.cost', { price: money(part.repair_next.cost_cents, 2), time: formatDuration(part.repair_next.duration_ms) })
  }

  if (worn.value.some((other) => other.repair_next.locked?.reason === 'worn_out')) {
    return t('workshop.repairWornOut')
  }

  return worn.value.length > 0 ? t('condition.waits') : t('condition.nothingToRepair')
})

/** How much of the way to the next service is left, in percent: the bar of the card. */
const serviceLeft = computed(() => {
  const service = car.value?.service

  return service && service.every_km > 0 ? Math.max(0, Math.min(100, Math.round((service.due_in_km / service.every_km) * 100))) : 0
})

async function load(): Promise<void> {
  try {
    await store.loadGarage()
    loadError.value = false
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = !store.loaded
    }
  }
}

onMounted(load)

/** The text of a failed action: what the server says in the language of the driver, or a general one. */
function failureText(failure: unknown): string {
  return failure instanceof ApiError && failure.status === 429
    ? t('common.tooManyRequests')
    : failure instanceof ApiError && failure.status === 422
      ? failure.message
      : t('common.error')
}

/** Queues a repair of the part, or a service of the car: paid at once, the car stands while the mechanic is at it, and the workshop shows the job. */
async function act(kind: JobKind, part: MountedPart | null): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  error.value = null
  clearQueued()

  try {
    await store.queue(kind, part?.id ?? null)
    showQueued(t('condition.queued'))
    void tutorial.load().catch(() => undefined)
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    error.value = failureText(failure)

    // The part was in a job already, or was put right in the meantime: what is shown is stale.
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
    <p v-if="loadError" class="text-danger" role="alert" data-test="load-error">{{ t('common.error') }}</p>

    <template v-else-if="car">
      <header class="flex items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <h1 class="ttl">{{ t('condition.title') }}</h1>
          <p class="sub" data-test="subtitle">{{ car.vehicle.name }} · {{ store.garage?.workshop.city.name }}</p>
        </div>
        <RouterLink :to="{ name: 'garage' }" class="btn btn-secondary btn-sm no-underline" data-test="back">{{ t('condition.back') }}</RouterLink>
      </header>

      <section class="grid grid-cols-2 gap-2" :aria-label="t('condition.title')">
        <div class="card !p-2.5" data-test="mileage-card">
          <p class="kick !mb-1">{{ t('condition.mileage') }}</p>
          <p class="font-display text-[28px] font-bold leading-none" data-test="mileage">{{ t('condition.km', { km: number(car.mileage_km) }) }}</p>
          <p class="sub mt-1" data-test="overall">{{ t('condition.overall', { percent: car.parts_condition_percent }) }}</p>
        </div>
        <div class="card !p-2.5" data-test="service-card">
          <p class="kick !mb-1">{{ t('condition.serviceIn') }}</p>
          <p class="font-display text-[28px] font-bold leading-none" :class="{ 'text-danger': car.service.due_in_km < 0 }" data-test="service-km">
            {{ t('condition.km', { km: number(Math.abs(car.service.due_in_km)) }) }}
          </p>
          <div class="bar mt-2" :class="{ 'bar-bad': car.service.due_in_km < 0 }" role="progressbar" :aria-label="serviceLine(car.service)" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="serviceLeft">
            <i :style="{ width: `${serviceLeft}%` }" />
          </div>
          <p class="sub mt-1" :class="{ 'text-danger': car.service.due_in_km < 0 }" data-test="service-line">{{ serviceLine(car.service) }}</p>
        </div>
      </section>

      <section class="card flex flex-col gap-1 !px-3 !py-2" :aria-label="t('condition.parts')" data-test="parts">
        <div v-for="row in rows" :key="row.slot" class="st" :data-test="`row-${row.slot}`">
          <span>{{ t(`slot.${row.slot}`) }}</span>
          <template v-if="row.part">
            <div
              class="bar"
              :class="{ 'bar-bad': row.part.condition_percent < NEEDS_REPAIR_PERCENT }"
              role="progressbar"
              :aria-label="t('part.condition', { percent: row.part.condition_percent })"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-valuenow="row.part.condition_percent"
            >
              <i :style="{ width: `${row.part.condition_percent}%` }" />
            </div>
            <span :class="{ 'text-danger': row.part.condition_percent < NEEDS_REPAIR_PERCENT }" :data-test="`percent-${row.slot}`">
              {{ row.part.condition_percent }}%
              <em v-if="row.part.condition_percent < NEEDS_REPAIR_PERCENT" class="text-danger" :data-test="`needs-repair-${row.slot}`">!</em>
            </span>
          </template>
          <template v-else>
            <div class="bar" />
            <span class="text-faint" :data-test="`percent-${row.slot}`">{{ row.built_in ? t('condition.notWorn') : t('condition.none') }}</span>
          </template>
        </div>
      </section>

      <div class="flex flex-wrap justify-between gap-x-3 gap-y-1 text-xs text-muted" data-test="legend">
        <span>{{ t('condition.legend') }}</span>
        <span v-if="worst" class="text-danger" data-test="effect">{{ t('condition.effect', { slot: t(`slot.${worst.slot}`), percent: worst.condition_percent, loss: worst.condition_loss_percent }) }}</span>
      </div>

      <section class="grid grid-cols-2 gap-2" :aria-label="t('condition.actions')">
        <div class="flex flex-col gap-1">
          <button type="button" class="btn btn-primary btn-sm w-full" :disabled="busy || target === null" data-test="repair" @click="target && act('repair', target)">
            {{ target ? t('condition.repair', { slot: t(`slot.${target.slot}`) }) : t('workshop.repairAction') }}
          </button>
          <p class="sub text-center" data-test="repair-info">{{ repairInfo }}</p>
        </div>
        <div class="flex flex-col gap-1">
          <button type="button" class="btn btn-secondary btn-sm w-full" :disabled="busy || servicing" data-test="service" @click="act('service', null)">
            {{ t('workshop.serviceButton') }}
          </button>
          <p class="sub text-center" data-test="service-info">
            {{ servicing ? t('workshop.serviceWaits') : t('condition.cost', { price: money(car.service.cost_cents, 2), time: formatDuration(car.service.duration_ms) }) }}
          </p>
        </div>
      </section>

      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>
      <p v-if="queued" class="text-[13px] text-ink" role="status" data-test="queued">
        {{ queued }}
        <RouterLink :to="{ name: 'workshop' }" class="text-amber" data-test="to-workshop">{{ t('condition.toWorkshop') }}</RouterLink>
      </p>

      <p v-if="breakdownLine(car)" class="sub" data-test="breakdown-line">{{ breakdownLine(car) }}</p>
      <p class="sub leading-snug" data-test="note">{{ t('condition.note') }}</p>
    </template>

    <p v-else-if="store.loaded" class="sub" data-test="no-car">{{ t('condition.noCar') }}</p>
  </GameShell>
</template>
