<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { gameTime } from '@/features/clock/gameClock'
import { levelOf } from '@/features/player/level'
import { useFormat } from '@/i18n/useFormat'
import { useGarageStore } from '@/stores/garage'
import { useRunStore } from '@/stores/run'
import { useSessionStore } from '@/stores/session'

const { t, te } = useI18n()
const { money, number } = useFormat()
const run = useRunStore()
const garage = useGarageStore()
const session = useSessionStore()

const progress = computed(() => levelOf(run.wallet?.xp ?? 0))
const fill = computed(() => Math.min(100, Math.round((progress.value.into / progress.value.needed) * 100)))
/** The cash of the run, or the one the shop saw last when the run has not been asked for yet. */
const cash = computed(() => run.wallet?.cash_cents ?? garage.cash?.cash_cents ?? null)

/** How often the time of the game is read again: a minute of the game is 7.5 s, so a look every 2 s never shows a stale one. */
const CLOCK_TICK_MS = 2_000

const now = ref(run.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  now.value = run.serverNow()
  ticker = setInterval(() => (now.value = run.serverNow()), CLOCK_TICK_MS)
})

onBeforeUnmount(() => clearInterval(ticker))

/** The time of the game, and the weather of the region where the car drives when there is a car: "11:20 · sucho". */
const time = computed(() => gameTime(now.value, run.run?.clock))
const weatherKey = computed(() => run.run?.weather.now ?? null)
const weatherName = computed(() => (weatherKey.value === null ? null : te(`weather.${weatherKey.value}`) ? t(`weather.${weatherKey.value}`) : weatherKey.value))
const clockText = computed(() => (weatherName.value === null ? time.value.label : `${time.value.label} · ${weatherName.value}`))
const phaseName = computed(() => (te(`clock.phase.${time.value.phase}`) ? t(`clock.phase.${time.value.phase}`) : time.value.phase))
</script>

<template>
  <div class="hud" data-test="hud">
    <div class="lvl" data-test="level">
      <small>{{ t('hud.level') }}</small>
      <strong>{{ progress.level }}</strong>
    </div>

    <div class="min-w-0 flex-1">
      <div class="mb-1 flex justify-between gap-2 text-xs text-muted">
        <span class="truncate" :title="t('clock.label', { time: time.label, phase: phaseName })" data-test="clock">{{ clockText }}</span>
        <span class="sr-only" data-test="driver">{{ session.me?.name }}</span>
        <span class="whitespace-nowrap" data-test="xp-progress">{{ t('hud.progress', { into: number(progress.into), needed: number(progress.needed) }) }}</span>
      </div>
      <div class="bar" role="progressbar" :aria-label="t('hud.xp')" :aria-valuenow="fill" aria-valuemin="0" aria-valuemax="100">
        <i :style="{ width: `${fill}%` }" />
      </div>
    </div>

    <span v-if="cash !== null" class="chip chip-am chip-coin" data-tutorial="cash" data-test="cash-chip">{{ money(cash, 2) }}</span>
  </div>
</template>
