<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import type { CarStatKey } from '@/api/types'
import AccountData from '@/components/AccountData.vue'
import AccountAway from '@/components/AccountAway.vue'
import AwaySummary from '@/components/AwaySummary.vue'
import CarHotspots from '@/components/CarHotspots.vue'
import CarStage from '@/components/CarStage.vue'
import FuelGauge from '@/components/FuelGauge.vue'
import GarageTabs from '@/components/GarageTabs.vue'
import GameShell from '@/components/GameShell.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import LinkAccountBanner from '@/components/LinkAccountBanner.vue'
import LinkAccountDialog from '@/components/LinkAccountDialog.vue'
import LinkedAccounts from '@/components/LinkedAccounts.vue'
import RecoveryPanel from '@/components/RecoveryPanel.vue'
import RunAlerts from '@/components/RunAlerts.vue'
import SlotChips from '@/components/SlotChips.vue'
import SlotDetail from '@/components/SlotDetail.vue'
import MissionsGlance from '@/components/MissionsGlance.vue'
import SpecCard from '@/components/SpecCard.vue'
import { carPicture } from '@/features/art'
import { useServiceLine } from '@/features/car/serviceLine'
import { overallScore } from '@/features/car/score'
import { statFill } from '@/features/car/statFill'
import { useCarClass } from '@/features/car/useCarClass'
import { useCarSpots } from '@/features/car/useCarSpots'
import { baseLapMs, baseRewardCents } from '@/features/run/laps'
import { useFlash } from '@/features/run/useFlash'
import { useRunStatus } from '@/features/run/useRunStatus'
import { useRunPolling } from '@/features/run/useRunPolling'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatLapTime } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useModel } from '@/model'
import { useGarageStore } from '@/stores/garage'
import { useAwayStore } from '@/stores/away'
import { useRunStore } from '@/stores/run'
import { useSessionStore } from '@/stores/session'
import { useTutorialStore } from '@/stores/tutorial'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const session = useSessionStore()
const run = useRunStore()
const away = useAwayStore()
const garage = useGarageStore()
const tutorial = useTutorialStore()
const { money, number } = useFormat()
const { serviceLine } = useServiceLine()
const { className } = useCarClass()

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

    // Nor is the summary of the rest of the account: what the other cars did while the driver was away.
    await away.load().catch(() => undefined)

    await scrollToHash()
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = true
    }
  }
}

/** The gear and the plus of the bar at the top lead to the account and to the tank of this screen: they are scrolled to once the screen has what it shows. */
async function scrollToHash(): Promise<void> {
  if (route.hash === '') {
    return
  }

  await nextTick()
  document.getElementById(route.hash.slice(1))?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
}

watch(() => route.hash, scrollToHash)

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
/** The picture the pack gives the car that drives, if it gives one: it is shown in place of the one the app brings. */
const pictureUrl = computed(() => run.run?.car.image_url ?? car.value?.vehicle.image_url ?? null)

/** How far the car is into its level, how much the level takes, and how full the bar is: all of it at the highest level. */
const xpInto = computed(() => (car.value ? car.value.xp.total - car.value.xp.level_start : 0))
const xpNeeded = computed(() => (car.value && car.value.xp.next_level_at !== null ? car.value.xp.next_level_at - car.value.xp.level_start : null))
const xpFill = computed(() => (xpNeeded.value ? Math.min(100, Math.round((xpInto.value / xpNeeded.value) * 100)) : 100))
/** The statistics that the screens show, in the order of the model of the game, and what a slot is to the formulas. */
const { stats: STATS, roleOf } = useModel()

/** How good the car is as a whole, for the card of its specification. */
const score = computed(() => overallScore(car.value, STATS.value))

/**
 * The parts are on the car where they are on its picture, when it has one (a car with the outline of a body has no places to put them on); the slots that have no place
 * are a row under the stage.
 */
const hasPicture = computed(() => !!(pictureUrl.value || (run.run && carPicture(run.run.car.slug))))
const { pickedSlot, picked, mounted: mountedCount, parts, pick, clear: clearPick } = useCarSpots(() => (car.value && run.run ? { slots: car.value.slots, slug: run.run.car.slug, hasPicture: hasPicture.value } : null))

/** The top speed the gearbox on the car allows, in km/h, or null when there is no gearbox with a limit on it. The gearbox is the slot with the role, whatever it is called. */
const gearboxLimit = computed(() => car.value?.slots.find((slot) => (slot.role ?? roleOf(slot.slot)) === 'gearbox')?.part?.stats.top_speed_cap_kmh ?? null)

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
      <AccountAway v-if="away.visible && away.summary" :summary="away.summary" @dismiss="away.dismiss().catch(() => undefined)" />
      <MissionsGlance />

      <section class="flex flex-col gap-3" aria-labelledby="run-title" data-test="run">
        <!-- The car on its stage, as the showroom of the canvas has it: the name in outline behind it, the level and the state of the run on the floor. -->
        <CarStage :name="run.run.car.name" :src="pictureUrl" :slug="run.run.car.slug">
          <template #spots>
            <CarHotspots v-if="car && parts.placed.length > 0" :placed="parts.placed" :picked="pickedSlot" :engine="car.vehicle.engine" @pick="pick" />
          </template>
          <span v-if="car" class="chip chip-am !h-6 !text-[11px]" data-test="car-level">{{ t('garage.carLevel', { level: car.level }) }}</span>
          <span class="chip !h-6 !text-[11px]" :class="chipClass" data-test="status">
            {{ t(`run.status.${statusKey}`) }}
          </span>
        </CarStage>

        <!-- The slots that have no place on the picture, so that every part can be looked at; and the part that was tapped, or how many there are. -->
        <template v-if="car">
          <SlotChips :slots="parts.unplaced" :picked="pickedSlot" @pick="pick" />

          <SlotDetail :slot="picked" :engine="car.vehicle.engine" :mounted="mountedCount" :total="car.slots.length" @close="clearPick" />
        </template>

        <SpecCard :name="run.run.car.name" :kicker="className(car?.vehicle.class)" :tier="car?.vehicle.tier ?? null" :score="car ? score : null" level="h1" heading-id="run-title">
          <p class="sub -mt-1" data-test="track">{{ run.run.track.name }}</p>

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

          <div class="flex flex-wrap items-center gap-1.5">
            <span v-if="car?.vehicle.drivetrain" class="tag tag-am" data-test="drivetrain">{{ car.vehicle.drivetrain.toUpperCase() }}</span>
            <span v-if="car?.vehicle.engine" class="chip h-6">{{ car.vehicle.engine }}</span>
            <span v-if="car?.vehicle.power_kw" class="chip h-6">{{ t('car.power', { value: car.vehicle.power_kw }) }}</span>
            <span class="ml-auto text-xs text-muted">
              {{ t('run.stat.lapTime') }} <b class="mono text-ink" data-test="lap-time">{{ formatLapTime(baseLapMs(run.run)) }}</b>
            </span>
          </div>

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
        </SpecCard>

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

      <FuelGauge :run="run.run" />
    </template>

    <!-- The account: the code that brings the garage back, the accounts it is linked to, and the data of the driver. The gear of the bar at the top leads here. -->
    <div id="account" class="flex flex-col gap-3" data-test="account">
      <!-- The game has no header with the language, as the bar at the top is the HUD: it is chosen here, where the gear leads. -->
      <div class="card flex items-center justify-between gap-3 !p-3" data-test="language">
        <LanguageSwitcher />
      </div>

      <RecoveryPanel v-if="session.me?.is_guest" ref="recoveryPanel" />

      <LinkedAccounts />

      <AccountData />
    </div>

    <LinkAccountDialog v-if="linkOpen && offerLinking" @later="later" @recovery="useCode" />

    <div>
      <button type="button" class="btn btn-secondary" data-test="sign-out" @click="session.signOut()">
        {{ t('garage.signOut') }}
      </button>
    </div>
  </GameShell>
</template>
