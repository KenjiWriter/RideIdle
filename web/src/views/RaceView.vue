<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import FuelGauge from '@/components/FuelGauge.vue'
import GameShell from '@/components/GameShell.vue'
import LapRing from '@/components/LapRing.vue'
import RunAlerts from '@/components/RunAlerts.vue'
import TutorialOverlay from '@/components/TutorialOverlay.vue'
import { gameTime, untilNight } from '@/features/clock/gameClock'
import { baseLapMs, baseRewardCents } from '@/features/run/laps'
import { formatCountdown, lapProgress } from '@/features/run/lapProgress'
import { useRunPolling } from '@/features/run/useRunPolling'
import { useRunStatus } from '@/features/run/useRunStatus'
import { useTutorialReward } from '@/features/run/useTutorialReward'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatDuration, formatLapTime } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { currentLocale } from '@/i18n'
import { useRunStore } from '@/stores/run'
import { useTutorialStore } from '@/stores/tutorial'

/** How often the countdown is drawn again. */
const TICK_MS = 250

/** The forecast tells of the next four stretches of the weather, an hour and a half: a night that comes within it is told of. */
const FORECAST_WINDOW_MS = 4 * 1_350_000

/** A coming weather that makes the lap this much longer, in percent, is worth a word about the tires. */
const SLOWER_NOTE_PERCENT = 8

/** The time after the end of a lap at which the server is asked what the lap paid: a moment later, so the lap is surely over there. */
const LAP_END_GRACE_MS = 400

const { t, te } = useI18n()
const router = useRouter()
const run = useRunStore()
const tutorial = useTutorialStore()
const { money, number } = useFormat()
const { statusKey, chipClass, towing } = useRunStatus()

useLeaveOnSignOut()
useRunPolling()

const { rewardMessage } = useTutorialReward()

const loadError = ref(false)
const starting = ref(false)
const startError = ref<string | null>(null)

async function open(): Promise<void> {
  loadError.value = false

  try {
    await run.load()

    // A driver without a car on the track has not chosen a wreck yet.
    if (!run.run) {
      await router.replace({ name: 'choose' })

      return
    }

    // The tutorial is no reason for an error: without it the screen is the screen.
    await tutorial.load().catch(() => undefined)
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

/** The server brings the tutorial up to date whenever it settles the run, so a new version of the run may mean a new step. */
watch(
  () => run.run?.state_version,
  (version, before) => {
    if (before !== undefined && version !== before) {
      void tutorial.load().catch(() => undefined)
    }
  },
)

/** Sends the car that waits in the garage out: from now on it drives by itself. */
async function start(): Promise<void> {
  if (starting.value) {
    return
  }

  starting.value = true
  startError.value = null

  try {
    await run.begin()
    void tutorial.load().catch(() => undefined)
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      startError.value = t('common.error')
    }
  } finally {
    starting.value = false
  }
}

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

/** The stretches of the weather that are still to come, with the lap the car would drive in each. */
const upcoming = computed(() => run.run?.weather.forecast.filter((segment) => segment.starts_ms > now.value) ?? [])

function lapOf(weather: string): number {
  return run.run?.lap.ms[weather] ?? dryLapMs.value
}

/** How much longer than the lap now the longest of the coming laps is, in percent. */
const slowdown = computed(() => (lapMs.value > 0 ? Math.round((Math.max(0, ...upcoming.value.map((segment) => lapOf(segment.weather))) / lapMs.value - 1) * 100) : 0))

/** On a track that is not lit the night makes the laps longer: it is on now, or it comes within the forecast, or it is not worth a word. */
const nightPercent = computed(() => Math.round(((run.run?.lap.night_ppm ?? 1_000_000) / 1_000_000 - 1) * 100))
const night = computed(() => {
  if (!run.run || nightPercent.value <= 0) {
    return null
  }

  if (gameTime(now.value, run.run.clock).phase === 'night') {
    return { soonMs: null }
  }

  const until = untilNight(now.value, run.run.clock)

  return until !== null && until <= FORECAST_WINDOW_MS ? { soonMs: until } : null
})

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
      <p v-if="rewardMessage" class="card card-hi text-sm" role="status" data-test="tutorial-reward">{{ rewardMessage }}</p>

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

        <!-- The car waits in the garage: this is where it is sent out. -->
        <section v-if="run.run.stop_reason === 'parked'" class="card card-hi flex flex-col gap-2.5 !p-3.5" data-tutorial="start" data-test="start-card">
          <div class="flex flex-col gap-1">
            <p class="font-display text-xl font-bold text-ink" data-test="start-title">{{ t('race.startTitle') }}</p>
            <p class="sub">{{ t('race.startNote') }}</p>
          </div>
          <p v-if="startError" class="text-danger" role="alert" data-test="start-error">{{ startError }}</p>
          <button type="button" class="btn btn-primary" :disabled="starting" data-test="start" @click="start">{{ t('race.start') }}</button>
        </section>

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

        <div v-if="upcoming.length" class="card flex flex-col gap-1 !px-3 !py-1.5 text-xs" data-test="forecast">
          <p class="kick !mb-0">{{ t('race.forecast.title') }}</p>
          <ul class="flex flex-col gap-0.5 text-ink">
            <li v-for="segment in upcoming" :key="segment.starts_ms" :data-test="`forecast-${segment.weather}`">
              {{ t('race.forecast.entry', { time: formatDuration(segment.starts_ms - now), weather: weatherName(segment.weather), lap: formatLapTime(lapOf(segment.weather)) }) }}
            </li>
          </ul>
          <p v-if="slowdown >= SLOWER_NOTE_PERCENT" class="text-amber" data-test="forecast-slower">{{ t('race.forecast.slower', { percent: slowdown }) }}</p>
        </div>

        <p v-if="night" class="card card-hi !px-3 !py-1.5 text-xs" data-test="night-note">
          {{ night.soonMs === null ? t('race.forecast.nightNow', { percent: nightPercent }) : t('race.forecast.nightSoon', { time: formatDuration(night.soonMs), percent: nightPercent }) }}
        </p>

        <div class="card grid grid-cols-[112px_1fr] items-center gap-3.5 !p-3.5" data-tutorial="laps" data-test="lap">
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
              <span class="chip !h-7 !px-2" data-test="lap-car-xp">{{ t('race.carXp', { value: number(run.run.lap.car_xp) }) }}</span>
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

    <TutorialOverlay />
  </GameShell>
</template>
