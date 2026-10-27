<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'

import type { CarStatKey } from '@/api/types'
import AccountData from '@/components/AccountData.vue'
import AwaySummary from '@/components/AwaySummary.vue'
import FuelGauge from '@/components/FuelGauge.vue'
import GarageTabs from '@/components/GarageTabs.vue'
import GameShell from '@/components/GameShell.vue'
import LinkAccountBanner from '@/components/LinkAccountBanner.vue'
import LinkAccountDialog from '@/components/LinkAccountDialog.vue'
import LinkedAccounts from '@/components/LinkedAccounts.vue'
import RarityMark from '@/components/RarityMark.vue'
import UpgradeMark from '@/components/UpgradeMark.vue'
import RecoveryPanel from '@/components/RecoveryPanel.vue'
import RunAlerts from '@/components/RunAlerts.vue'
import { carPicture } from '@/features/art'
import { useServiceLine } from '@/features/car/serviceLine'
import { statFill } from '@/features/car/statFill'
import { baseLapMs, baseRewardCents } from '@/features/run/laps'
import { useFlash } from '@/features/run/useFlash'
import { useRunStatus } from '@/features/run/useRunStatus'
import { useRunPolling } from '@/features/run/useRunPolling'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatLapTime } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useGarageStore } from '@/stores/garage'
import { useRunStore } from '@/stores/run'
import { useSessionStore } from '@/stores/session'
import { useTutorialStore } from '@/stores/tutorial'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const run = useRunStore()
const garage = useGarageStore()
const tutorial = useTutorialStore()
const { money, number } = useFormat()
const { serviceLine } = useServiceLine()

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

      return
    }

    // The parts and the statistics are not worth an error of their own: the car and its run are on the screen without them.
    await garage.loadGarage().catch(() => undefined)

    // The tutorial is no reason for an error either: without it the garage is the garage.
    await tutorial.load().catch(() => undefined)
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = true
    }
  }
}

onMounted(open)

/** A rise of the car is said once, with the tier of parts it opened. */
const { message: levelUpMessage, show: showLevelUp } = useFlash()

watch(
  () => garage.levelUp,
  (rose) => {
    if (rose !== null) {
      showLevelUp([t('garage.levelUp', { level: rose.level }), rose.tier !== null ? t('garage.tierUnlocked', { tier: rose.tier }) : null].filter(Boolean).join(' '))
      garage.acknowledgeLevelUp()
    }
  },
  { immediate: true },
)

/** The server carries out the jobs of the workshop whenever it settles the run, so a new version of the run may mean new parts. */
watch(
  () => run.run?.state_version,
  (version, before) => {
    if (before !== undefined && version !== before) {
      void garage.loadGarage().catch(() => undefined)
      void tutorial.load().catch(() => undefined)
    }
  },
)

const { statusKey, chipClass } = useRunStatus()

const car = computed(() => garage.garage?.car ?? null)

/** How far the car is into its level, how much the level takes, and how full the bar is: all of it at the highest level. */
const xpInto = computed(() => (car.value ? car.value.xp.total - car.value.xp.level_start : 0))
const xpNeeded = computed(() => (car.value && car.value.xp.next_level_at !== null ? car.value.xp.next_level_at - car.value.xp.level_start : null))
const xpFill = computed(() => (xpNeeded.value ? Math.min(100, Math.round((xpInto.value / xpNeeded.value) * 100)) : 100))
const STATS: CarStatKey[] = ['accel', 'top_speed', 'grip', 'handling', 'braking', 'clearance']

/** The top speed the gearbox on the car allows, in km/h, or null when there is no gearbox with a limit on it. */
const gearboxLimit = computed(() => car.value?.slots.find((slot) => slot.slot === 'gearbox')?.part?.stats.top_speed_cap_kmh ?? null)

function delta(stat: CarStatKey): number {
  const value = (car.value?.stats[stat] ?? 0) - (car.value?.base_stats[stat] ?? 0)

  return Math.abs(value) < 0.005 ? 0 : value
}

/**
 * A guest who has earned something has a garage worth saving: the window "Save your garage" opens once, and when the
 * driver says later a quiet banner stays. It waits for the summary of an absence to be dismissed, so the two never
 * pile up.
 */
const linkOpen = ref(false)
const recoveryPanel = ref<InstanceType<typeof RecoveryPanel> | null>(null)
const offerLinking = computed(() => session.me?.is_guest === true && (run.run?.laps_done ?? 0) > 0)

watch(
  () => [offerLinking.value, session.linkPromptDismissed, run.away, tutorial.asked, tutorial.current !== null] as const,
  ([offer, dismissed, away, tutorialKnown, stepWaits]) => {
    // Not over the tutorial: the message of the mentor about saving the garage comes first, and this window after it.
    // So it waits until the tutorial has been asked about, and for as long as a step waits, with the phone out or put away:
    // the lap that earns the offer is also the one that brings the next message, and the run is known a moment before the tutorial.
    if (offer && !dismissed && away === null && tutorialKnown && !stepWaits) {
      linkOpen.value = true
    }
  },
  { immediate: true },
)

function later(): void {
  linkOpen.value = false
  session.dismissLinkPrompt()
}

/** The driver prefers the recovery code: the window closes and the panel with the code is shown. */
async function useCode(): Promise<void> {
  later()
  await nextTick()
  recoveryPanel.value?.$el.scrollIntoView?.({ behavior: 'smooth' })
}
</script>

<template>
  <GameShell :load-run="false" :load-tutorial="false">
    <p class="sub" data-test="welcome">
      {{ session.me ? t('garage.welcome', { name: session.me.name }) : t('common.loading') }}
    </p>

    <GarageTabs />

    <p v-if="levelUpMessage" class="card card-hi text-sm" role="status" data-test="level-up">{{ levelUpMessage }}</p>

    <LinkAccountBanner v-if="offerLinking && session.linkPromptDismissed && !linkOpen" @open="linkOpen = true" />

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('run.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="open">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!run.run" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <AwaySummary v-if="run.away" :summary="run.away" :now-ms="run.run.server_time_ms" @dismiss="run.dismissAway()" />

      <section class="flex flex-col gap-3" aria-labelledby="run-title" data-test="run">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <h1 id="run-title" class="ttl" data-test="car">{{ run.run.car.name }}</h1>
            <p class="sub mt-1" data-test="track">{{ run.run.track.name }}</p>
          </div>
          <div class="flex flex-none flex-col items-end gap-1.5">
            <span v-if="car" class="chip chip-am" data-test="car-level">{{ t('garage.carLevel', { level: car.level }) }}</span>
            <span class="chip" :class="chipClass" data-test="status">
              {{ t(`run.status.${statusKey}`) }}
            </span>
          </div>
        </div>

        <!-- The experience of the car, and what is left to the next level, as the board of the garage has it. -->
        <div v-if="car" data-test="car-xp">
          <div class="mb-1 flex justify-between text-xs text-muted">
            <span>{{ t('garage.carXp') }}</span>
            <span class="mono text-ink" data-test="car-xp-value">
              {{ xpNeeded === null ? t('garage.carXpMax') : `${number(xpInto)} / ${number(xpNeeded)}` }}
            </span>
          </div>
          <div class="bar" role="progressbar" :aria-label="t('garage.carXp')" :aria-valuenow="xpFill" aria-valuemin="0" aria-valuemax="100"><i :style="{ width: `${xpFill}%` }" /></div>
          <p v-if="car.tier_unlock" class="sub mt-1" data-test="tier-unlock">{{ t('garage.nextTier', { level: car.tier_unlock.level, tier: car.tier_unlock.tier }) }}</p>
        </div>

        <div v-if="carPicture(run.run.car.slug)" class="card flex items-stretch overflow-hidden !p-0" data-test="picture">
          <img :src="carPicture(run.run.car.slug) ?? ''" :alt="run.run.car.name" class="h-[86px] w-[130px] flex-none object-cover" />
          <div class="flex min-w-0 flex-col justify-center gap-1.5 px-3 py-2">
            <div class="flex flex-wrap gap-1.5">
              <span v-if="car?.vehicle.drivetrain" class="tag tag-am" data-test="drivetrain">{{ car.vehicle.drivetrain.toUpperCase() }}</span>
              <span v-if="car?.vehicle.engine" class="chip h-6">{{ car.vehicle.engine }}</span>
              <span v-if="car?.vehicle.power_kw" class="chip h-6">{{ t('car.power', { value: car.vehicle.power_kw }) }}</span>
            </div>
            <p class="text-xs text-muted">
              {{ t('run.stat.lapTime') }} <b class="mono text-ink" data-test="lap-time">{{ formatLapTime(baseLapMs(run.run)) }}</b>
            </p>
          </div>
        </div>

        <dl class="grid grid-cols-3 gap-2" data-test="run-stats">
          <div class="card !p-2.5" data-tutorial="laps">
            <dt class="kick !mb-0.5 !text-[10px] !tracking-[0.06em]">{{ t('run.stat.laps') }}</dt>
            <dd class="font-display text-xl font-bold" data-test="laps">{{ number(run.run.laps_done) }}</dd>
          </div>
          <div class="card !p-2.5">
            <dt class="kick !mb-0.5 !text-[10px] !tracking-[0.06em]">{{ t('run.stat.reward') }}</dt>
            <dd class="font-display text-xl font-bold text-amber" data-test="reward">{{ money(baseRewardCents(run.run), 2) }}</dd>
          </div>
          <div class="card !p-2.5">
            <dt class="kick !mb-0.5 !text-[10px] !tracking-[0.06em]">{{ t('run.stat.xp') }}</dt>
            <dd class="font-display text-xl font-bold" data-test="xp">{{ t('car.xp', { value: number(run.wallet?.xp ?? 0) }) }}</dd>
          </div>
        </dl>

        <RunAlerts :run="run.run" />
      </section>

      <section v-if="car" class="flex flex-col gap-1" :aria-label="t('workshop.stats')" data-test="stats">
        <div v-for="stat in STATS" :key="stat" class="st" :data-test="`stat-${stat}`">
          <span>{{ t(`carStat.${stat}`) }}</span>
          <div class="bar"><i :style="{ width: `${statFill(car, stat)}%` }" /></div>
          <span>
            {{ number(car.stats[stat], 0) }}
            <em v-if="delta(stat) !== 0" :class="{ dn: delta(stat) < 0 }" :data-test="`delta-${stat}`">{{ delta(stat) > 0 ? '+' : '−' }}{{ number(Math.abs(delta(stat)), 0) }}</em>
          </span>
        </div>
        <!-- The limit of the gearbox, when it has one, and the way to the screen of the technical state of the car. -->
        <div class="mt-1 flex items-center justify-between gap-3 text-xs">
          <span v-if="gearboxLimit !== null" class="text-faint" data-test="gearbox-limit">{{ t('garage.gearboxLimit', { speed: number(gearboxLimit) }) }}</span>
          <RouterLink :to="{ name: 'condition' }" class="-my-3 ml-auto py-3 text-amber no-underline" data-test="condition-link">
            {{ t('garage.conditionLink', { percent: car.parts_condition_percent }) }}
          </RouterLink>
        </div>
        <!-- The odometer and the service of the car: the place to see that it is due is here, the place to do it is the workshop. -->
        <p class="sub mt-1" data-test="upkeep">
          {{ t('workshop.mileage', { km: number(car.mileage_km) }) }} ·
          <span :class="{ 'text-danger': car.service.due_in_km < 0 }" data-test="upkeep-service">{{ serviceLine(car.service) }}</span>
        </p>
      </section>

      <section v-if="car" class="grid grid-cols-3 gap-2" :aria-label="t('workshop.slots')" data-test="slots">
        <RouterLink
          v-for="slot in car.slots"
          :key="slot.slot"
          :to="{ name: 'workshop' }"
          class="tile no-underline"
          :class="[slot.part ? 'on' : '', slot.part && slot.part.rarity_rank > 1 ? `r${slot.part.rarity_rank}` : '']"
          :data-test="`slot-${slot.slot}`"
        >
          <span class="sl">{{ t(`slot.${slot.slot}`) }}</span>
          <span class="nm">{{ slot.part ? slot.part.name : slot.built_in ? (car.vehicle.engine ?? t('workshop.builtIn')) : t('workshop.empty') }}</span>
          <span v-if="slot.part" class="mono text-[10px] text-faint">{{ t('part.tier', { tier: slot.part.tier ?? 0 }) }}</span>
          <RarityMark v-if="slot.part && slot.part.rarity_rank > 1" :rarity="slot.part.rarity" />
          <UpgradeMark v-if="slot.part" :level="slot.part.upgrade" />
          <!-- A part that has worn says how much of its life is left, in red when it is unserviceable. -->
          <span v-if="slot.part && slot.part.condition_percent < 100" class="mono text-[10px]" :class="slot.part.condition_percent < 20 ? 'text-danger' : 'text-faint'" :data-test="`slot-condition-${slot.slot}`">
            {{ t('part.condition', { percent: slot.part.condition_percent }) }}
          </span>
        </RouterLink>
      </section>

      <FuelGauge :run="run.run" />
    </template>

    <RecoveryPanel v-if="session.me?.is_guest" ref="recoveryPanel" />

    <LinkedAccounts />

    <AccountData />

    <LinkAccountDialog v-if="linkOpen && offerLinking" @later="later" @recovery="useCode" />

    <div>
      <button type="button" class="btn btn-secondary" data-test="sign-out" @click="session.signOut()">
        {{ t('garage.signOut') }}
      </button>
    </div>
  </GameShell>
</template>
