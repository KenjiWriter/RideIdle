<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import FuelGauge from '@/components/FuelGauge.vue'
import GameShell from '@/components/GameShell.vue'
import LapRing from '@/components/LapRing.vue'
import RunAlerts from '@/components/RunAlerts.vue'
import { baseLapMs, baseRewardCents } from '@/features/run/laps'
import { formatCountdown, lapProgress } from '@/features/run/lapProgress'
import { useRunPolling } from '@/features/run/useRunPolling'
import { useRunStatus } from '@/features/run/useRunStatus'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatDuration, formatLapTime } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { currentLocale } from '@/i18n'
import { useRunStore } from '@/stores/run'

/** How often the countdown is drawn again. */
const TICK_MS = 250

/** The time after the end of a lap at which the server is asked what the lap paid: a moment later, so the lap is surely over there. */
const LAP_END_GRACE_MS = 400

const { t, te } = useI18n()
const router = useRouter()
const run = useRunStore()
const { money, number } = useFormat()
const { statusKey, chipClass, towing } = useRunStatus()

useLeaveOnSignOut()
useRunPolling()

const loadError = ref(false)

async function open(): Promise<void> {
  loadError.value = false

  try {
    await run.load()

    // A driver without a car on the track has not chosen a wreck yet.
    if (!run.run) {
      await router.replace({ name: 'choose' })
    }
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = true
    }
  }
}

/** What time it is for the server, drawn again every moment: the countdowns count with it. */
const now = ref(run.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined
let lapTimer: ReturnType<typeof setTimeout> | undefined

onMounted(() => {
  void open()
  ticker = setInterval(() => (now.value = run.serverNow()), TICK_MS)
})

onBeforeUnmount(() => {
  clearInterval(ticker)
  clearTimeout(lapTimer)
})

/** The lap ends, and the server is asked what it paid, so the cash and the laps are right at the moment, not at the next poll. */
watch(
  () => run.run?.current_lap?.finish_ms,
  (finish) => {
    clearTimeout(lapTimer)

    if (finish !== undefined) {
      lapTimer = setTimeout(() => void run.refresh().catch(() => undefined), Math.max(0, finish - run.serverNow()) + LAP_END_GRACE_MS)
    }
  },
  { immediate: true },
)

const lap = computed(() => run.run?.current_lap ?? null)
const progress = computed(() => (lap.value ? lapProgress(lap.value, now.value) : null))

/** The weather the lap in progress is driven in, or the weather now for a car that stands. */
const weather = computed(() => lap.value?.weather ?? run.run?.weather.now ?? 'dry')

function weatherName(key: string): string {
  return te(`weather.${key}`) ? t(`weather.${key}`) : key
}

const lapMs = computed(() => (run.run ? (run.run.lap.ms[weather.value] ?? baseLapMs(run.run)) : 0))
const lapCash = computed(() => (run.run ? (run.run.lap.cash_cents[weather.value] ?? baseRewardCents(run.run)) : 0))
const lapXp = computed(() => run.run?.lap.xp[weather.value] ?? 0)
const dryLapMs = computed(() => (run.run ? baseLapMs(run.run) : 0))

const untilChangeMs = computed(() => Math.max(0, (run.run?.weather.ends_ms ?? 0) - now.value))
const weatherChanges = computed(() => run.run !== null && run.run.weather.next !== run.run.weather.now)
/** The lap is slower or faster than in the dry, which the driver should know when the weather turns. */
const weatherMatters = computed(() => run.run !== null && lapMs.value !== dryLapMs.value)

/** The middle of the ring: the time to the end of the lap, or the wait of the tow, or nothing for a car that stands. */
const ring = computed(() => {
  if (towing.value && run.run) {
    return { value: formatCountdown(run.run.cursor_ms - now.value), caption: t('run.status.towed').toLocaleUpperCase(currentLocale()), fraction: 0, label: t('race.ring.towed') }
  }

  if (progress.value) {
    const time = formatCountdown(progress.value.remainingMs)

    return { value: time, caption: t('race.toEnd'), fraction: progress.value.fraction, label: t('race.ring.lap', { percent: Math.round(progress.value.fraction * 100), time }) }
  }

  return { value: '—', caption: t(`run.status.${statusKey.value}`).toLocaleUpperCase(currentLocale()), fraction: 0, label: t('race.ring.stands') }
})
</script>

<template>
  <GameShell :load-run="false">
    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('run.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="open">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!run.run" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <section class="flex flex-col gap-2.5" aria-labelledby="race-title" data-test="race">
        <div class="flex items-start justify-between gap-2.5">
          <div class="min-w-0">
            <h1 id="race-title" class="ttl" data-test="track">{{ run.run.track.name }}</h1>
            <p class="sub mt-1" data-test="sub">
              {{ lap ? t('race.lapNow', { lap: number(run.run.laps_done + 1) }) : t('race.lapsDone', { laps: number(run.run.laps_done) }) }} · {{ run.run.car.name }} · {{ weatherName(weather) }}
            </p>
          </div>
          <span class="chip flex-none" :class="chipClass" data-test="status">{{ statusKey === 'running' ? t('race.live') : t(`run.status.${statusKey}`) }}</span>
        </div>

        <RunAlerts :run="run.run" />

        <div class="card card-cy flex flex-col gap-1 !px-3 !py-1.5 text-xs" data-test="weather">
          <p class="text-ink" data-test="weather-forecast">
            {{
              weatherChanges
                ? t('race.weather.changes', { now: weatherName(run.run.weather.now), time: formatDuration(untilChangeMs), next: weatherName(run.run.weather.next) })
                : t('race.weather.stays', { now: weatherName(run.run.weather.now), time: formatDuration(untilChangeMs) })
            }}
          </p>
          <p v-if="weatherMatters" class="text-muted" data-test="weather-lap">
            {{ t('race.weather.lap', { time: formatLapTime(lapMs), cash: money(lapCash, 2), dry: formatLapTime(dryLapMs) }) }}
          </p>
        </div>

        <div class="card grid grid-cols-[112px_1fr] items-center gap-3.5 !p-3.5" data-test="lap">
          <LapRing :fraction="ring.fraction" :value="ring.value" :caption="ring.caption" :label="ring.label" />
          <div class="flex min-w-0 flex-col gap-1.5">
            <div class="flex justify-between text-[13px] text-muted">
              <span>{{ lap ? t('race.lapLabel') : t('race.lapsDoneLabel') }}</span>
              <b class="mono text-[13px] text-ink" data-test="lap-number">{{ number(lap ? run.run.laps_done + 1 : run.run.laps_done) }}</b>
            </div>
            <div class="flex justify-between text-[13px] text-muted">
              <span>{{ t('run.stat.lapTime') }}</span>
              <b class="mono text-[13px] text-ink" data-test="lap-time">{{ formatLapTime(lapMs) }}</b>
            </div>
            <div class="flex justify-between text-[13px] text-muted">
              <span>{{ t('race.lapPays') }}</span>
              <b class="mono text-[13px] text-amber" data-test="lap-pays">{{ money(lapCash, 2) }}</b>
            </div>
            <div class="mt-0.5 flex flex-wrap gap-[5px]">
              <span class="chip chip-am !h-7 !px-2" data-test="lap-cash">+{{ money(lapCash, 2) }}</span>
              <span class="chip !h-7 !px-2" data-test="lap-xp">+{{ t('car.xp', { value: number(lapXp) }) }}</span>
            </div>
          </div>
        </div>

        <!-- Nobody races the driver yet: the board has one row, which is true, and says when the others come. -->
        <div class="lb" data-test="board">
          <div class="lbr lbh"><span>#</span><span>{{ t('race.board.driver') }}</span><span>{{ t('race.board.kind') }}</span><span class="text-right">{{ t('race.board.time') }}</span></div>
          <div class="lbr me"><b>1</b><span class="truncate">{{ t('race.board.you', { car: run.run.car.name }) }}</span><span class="tag tag-am justify-center">{{ t('race.board.youTag') }}</span><span class="tm">{{ formatLapTime(lapMs) }}</span></div>
        </div>
        <p class="sub" data-test="board-later">{{ t('race.board.later') }}</p>

        <FuelGauge :run="run.run" />
      </section>
    </template>
  </GameShell>
</template>
