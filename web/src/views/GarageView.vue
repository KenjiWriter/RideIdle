<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import AwaySummary from '@/components/AwaySummary.vue'
import FuelGauge from '@/components/FuelGauge.vue'
import RecoveryPanel from '@/components/RecoveryPanel.vue'
import RepairControl from '@/components/RepairControl.vue'
import TowControl from '@/components/TowControl.vue'
import { baseLapMs, baseRewardCents } from '@/features/run/laps'
import { useRunPolling } from '@/features/run/useRunPolling'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatDuration, formatLapTime } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useRunStore } from '@/stores/run'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const run = useRunStore()
const { money, number } = useFormat()

useLeaveOnSignOut()
useRunPolling()

const loadError = ref(false)

async function open(): Promise<void> {
  loadError.value = false

  if (!session.me) {
    void session.fetchMe().catch(() => undefined)
  }

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

onMounted(open)

/**
 * A towed car is running, but its start is still in the future: the wait of the tow. Nothing else puts the cursor of
 * a run ahead of the clock of the server.
 */
const towMs = computed(() => (run.run && run.run.status === 'running' ? Math.max(0, run.run.cursor_ms - run.run.server_time_ms) : 0))
const towing = computed(() => towMs.value > 0)

/** Why the car stands, that it is being towed, or that it is racing: the key of the status in the messages. */
const statusKey = computed(() => (towing.value ? 'towed' : (run.run?.stop_reason ?? 'running')))
const stopped = computed(() => run.run?.status === 'stopped')
</script>

<template>
  <main class="mx-auto flex max-w-xl flex-col gap-6 px-4 py-8">
    <p class="text-sm text-muted" data-test="welcome">
      {{ session.me ? t('garage.welcome', { name: session.me.name }) : t('common.loading') }}
    </p>

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('run.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="open">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!run.run" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <AwaySummary v-if="run.away" :summary="run.away" :now-ms="run.run.server_time_ms" @dismiss="run.dismissAway()" />

      <section class="card flex flex-col gap-4" aria-labelledby="run-title" data-test="run">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h1 id="run-title" class="text-3xl font-bold tracking-tight" data-test="car">{{ run.run.car.name }}</h1>
            <p class="text-muted" data-test="track">{{ run.run.track.name }}</p>
          </div>
          <span
            class="shrink-0 rounded-full border px-3 py-1 text-sm font-bold"
            :class="stopped ? 'border-danger text-danger' : towing ? 'border-amber text-amber' : 'border-cyan text-cyan'"
            data-test="status"
          >
            {{ t(`run.status.${statusKey}`) }}
          </span>
        </div>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
          <div>
            <dt class="text-sm text-muted">{{ t('run.stat.cash') }}</dt>
            <dd class="text-lg font-bold text-amber" data-test="cash">{{ money(run.wallet?.cash_cents ?? 0, 2) }}</dd>
          </div>
          <div>
            <dt class="text-sm text-muted">{{ t('run.stat.xp') }}</dt>
            <dd class="text-lg font-bold" data-test="xp">{{ t('car.xp', { value: number(run.wallet?.xp ?? 0) }) }}</dd>
          </div>
          <div>
            <dt class="text-sm text-muted">{{ t('run.stat.laps') }}</dt>
            <dd class="text-lg font-bold" data-test="laps">{{ number(run.run.laps_done) }}</dd>
          </div>
          <div>
            <dt class="text-sm text-muted">{{ t('run.stat.reward') }}</dt>
            <dd class="font-semibold" data-test="reward">{{ money(baseRewardCents(run.run), 2) }}</dd>
          </div>
          <div>
            <dt class="text-sm text-muted">{{ t('run.stat.lapTime') }}</dt>
            <dd class="font-semibold" data-test="lap-time">{{ formatLapTime(baseLapMs(run.run)) }}</dd>
          </div>
        </dl>

        <p
          v-if="stopped && run.run.stop_reason"
          class="rounded-lg border border-danger bg-surface-2 p-3 text-sm"
          role="status"
          data-test="banner"
        >
          {{ t(`run.banner.${run.run.stop_reason}`) }}
        </p>

        <RepairControl :run="run.run" />

        <TowControl :run="run.run" />

        <p v-if="towing" class="rounded-lg border border-amber bg-surface-2 p-3 text-sm" role="status" data-test="towing">
          {{ t('run.tow.underway', { time: formatDuration(towMs) }) }}
        </p>
      </section>

      <FuelGauge :run="run.run" />
    </template>

    <RecoveryPanel v-if="session.me?.is_guest" />

    <div>
      <button type="button" class="btn btn-secondary" data-test="sign-out" @click="session.signOut()">
        {{ t('garage.signOut') }}
      </button>
    </div>
  </main>
</template>
