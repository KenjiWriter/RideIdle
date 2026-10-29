<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { AwayAccount, AwayJourney } from '@/api/types'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'

/**
 * What happened to the rest of the account while the driver was away: the cars that were not played (what each earned, and the ones that wait for the driver), the jobs
 * of the workshop that were done, the journeys that ended and what the police made of a boosted one. The card of the garage tells the same of the car that is played.
 */
const props = defineProps<{ summary: AwayAccount }>()

defineEmits<{ dismiss: [] }>()

const { t } = useI18n()
const { money, number } = useFormat()

const cars = computed(() => (props.summary.cars ?? []).filter((car) => !car.played))
const jobs = computed(() => props.summary.jobs ?? [])
const journeys = computed(() => props.summary.journeys ?? [])
const earned = computed(() => cars.value.reduce((sum, car) => sum + car.earned_cents, 0))
/** The tank filled for the driver and the bonus of the VIP account, for the cars that were not played. */
const refills = computed(() => cars.value.reduce((sum, car) => sum + (car.refills ?? 0), 0))
const chargesUsed = computed(() => cars.value.reduce((sum, car) => sum + (car.refill_charges ?? 0), 0))
const bonus = computed(() => cars.value.reduce((sum, car) => sum + (car.bonus_cents ?? 0), 0))

/** What the police made of a boosted journey, in words (nothing when they did not stop the car). */
function police(journey: AwayJourney): string | null {
  const stop = journey.police

  if (stop === null) {
    return null
  }

  const amount = money(stop.paid_cents, 2)

  switch (stop.outcome) {
    case 'defaulted':
      return t('awayAccount.policeDefaulted', { amount })
    case 'paid':
      return t('awayAccount.policePaid', { amount })
    case 'bribed':
      return t('awayAccount.policeBribed', { amount })
    case 'bribe_failed':
      return t('awayAccount.policeBribeFailed', { amount })
    case 'lawyered':
      return t('awayAccount.policeLawyered')
    default:
      return null
  }
}
</script>

<template>
  <section class="card flex flex-col gap-3 border-amber" aria-labelledby="away-account-title" data-test="away-account">
    <div class="flex items-start justify-between gap-2">
      <h2 id="away-account-title" class="text-xl font-bold">{{ t('awayAccount.title') }}</h2>
      <span class="chip" data-test="away-account-duration">{{ formatDuration((summary.away_minutes ?? 0) * 60_000) }}</span>
    </div>

    <div v-if="cars.length > 0" class="flex flex-col gap-1.5" data-test="away-cars">
      <p class="kick !mb-0">{{ t('awayAccount.cars', { earned: money(earned, 2) }) }}</p>
      <ul class="flex flex-col gap-1">
        <li v-for="car in cars" :key="car.id ?? car.name" class="flex items-center justify-between gap-2 text-[13px]" :data-test="`away-car-${car.id}`">
          <span class="min-w-0 truncate text-ink">{{ car.name }}</span>
          <span v-if="car.waiting" class="chip chip-am !h-6 !px-2" :data-test="`away-car-${car.id}-waiting`">{{ t(`awayAccount.waiting.${car.waiting}`) }}</span>
          <span v-else class="mono whitespace-nowrap text-amber">+{{ money(car.earned_cents, 2) }} · {{ t('awayAccount.laps', { count: number(car.laps) }) }}</span>
        </li>
      </ul>
    </div>

    <div v-if="refills > 0 || bonus > 0" class="flex flex-col gap-1" data-test="away-extras">
      <p v-if="refills > 0" class="text-[13px] text-muted" data-test="away-extras-refills">
        {{ chargesUsed > 0 ? t('run.away.refills', { n: refills, charges: chargesUsed }, refills) : t('run.away.refillsFree', { n: refills }, refills) }}
      </p>
      <p v-if="bonus > 0" class="text-[13px] text-amber" data-test="away-extras-bonus">{{ t('run.away.bonus', { amount: money(bonus, 2) }) }}</p>
    </div>

    <div v-if="jobs.length > 0" class="flex flex-col gap-1.5" data-test="away-jobs">
      <p class="kick !mb-0">{{ t('awayAccount.jobs') }}</p>
      <ul class="flex flex-col gap-1">
        <li v-for="(job, index) in jobs" :key="index" class="text-[13px] text-ink">{{ t(`awayAccount.job.${job.kind}`) }}{{ job.part ? `: ${job.part}` : '' }}</li>
      </ul>
    </div>

    <div v-if="journeys.length > 0" class="flex flex-col gap-1.5" data-test="away-journeys">
      <p class="kick !mb-0">{{ t('awayAccount.journeys') }}</p>
      <ul class="flex flex-col gap-1">
        <li v-for="(journey, index) in journeys" :key="index" class="flex flex-col text-[13px] text-ink" :data-test="`away-journey-${index}`">
          <span>{{ t('awayAccount.arrived', { city: journey.to }) }}</span>
          <span v-if="police(journey)" class="sub" :data-test="`away-journey-${index}-police`">{{ police(journey) }}</span>
        </li>
      </ul>
    </div>

    <div>
      <button type="button" class="btn btn-primary" data-test="away-account-dismiss" @click="$emit('dismiss')">{{ t('run.away.dismiss') }}</button>
    </div>
  </section>
</template>
