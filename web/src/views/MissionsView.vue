<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import type { Mission, MissionKind, MissionPeriodKind, MissionsClaimed } from '@/api/types'
import GameShell from '@/components/GameShell.vue'
import MissionCard from '@/components/MissionCard.vue'
import PartAffixes from '@/components/PartAffixes.vue'
import PartStats from '@/components/PartStats.vue'
import RarityMark from '@/components/RarityMark.vue'
import { useMissionText } from '@/features/missions/useMissionText'
import { rankOf } from '@/features/parts/rarity'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatTimeLeft } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { useMissionsStore } from '@/stores/missions'
import { usePremiumStore } from '@/stores/premium'
import { useRunStore } from '@/stores/run'

/**
 * The missions: the main ones (chains, one open mission in each), and the sets of a day, a week and a month. Every mission shows how far the driver is and what it
 * pays, and is taken with a button; when every mission of a set is done, the set has a bonus of premium coins to take. Everything is the server's: what a mission asks
 * and pays, when the set resets, what is done. The screen asks again when it is opened and from time to time, and when the driver takes something.
 */
const { t } = useI18n()
const { money, number } = useFormat()
const { rewards } = useMissionText()
const missions = useMissionsStore()
const premium = usePremiumStore()
const run = useRunStore()

useLeaveOnSignOut()

type Tab = MissionKind

const TABS: Tab[] = ['main', 'daily', 'weekly', 'monthly']
const TICK_MS = 30_000

const tab = ref<Tab>('daily')
const loaded = ref(false)
const loadError = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const { message: told, show: tell, clear: clearTold } = useFlash(12_000)
/** What the last request gave: the cash, the XP and the parts, until the driver has seen it. */
const gave = ref<MissionsClaimed | null>(null)
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | undefined

onMounted(async () => {
  ticker = setInterval(() => (now.value = Date.now()), TICK_MS)
  await open()
})

onBeforeUnmount(() => clearInterval(ticker))

async function open(): Promise<void> {
  loadError.value = false

  try {
    await missions.load()
    loaded.value = true
    // The tab with something to take is the one to show first.
    const first = TABS.find((kind) => takeable(kind) > 0)

    if (first !== undefined) {
      tab.value = first
    }
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = true
    }
  }
}

const periods = computed(() => missions.periods)
const set = computed(() => (tab.value === 'main' ? null : (periods.value?.[tab.value as MissionPeriodKind] ?? null)))
const chains = computed(() => missions.main)
const locked = computed(() => (tab.value === 'main' ? missions.view?.main_locked : missions.locked) ?? null)

/** How many missions of a tab can be taken now, the bonus of its set included. */
function takeable(kind: Tab): number {
  if (kind === 'main') {
    return chains.value.reduce((sum, chain) => sum + chain.missions.filter((mission) => mission.status === 'completed').length, 0)
  }

  const of = periods.value?.[kind as MissionPeriodKind]

  return (of?.missions.filter((mission) => mission.status === 'completed').length ?? 0) + (of?.bonus?.state === 'ready' ? 1 : 0)
}

const toTake = computed(() => (set.value === null ? takeable('main') : set.value.missions.filter((mission) => mission.status === 'completed').length))

/** The time until the set resets, in words: the server's answer, minus what has passed on this device since. */
const resets = computed(() => {
  if (set.value === null) {
    return null
  }

  const passed = Math.max(0, now.value - (missions.seenAt || now.value))

  return formatTimeLeft(Math.max(0, set.value.resets_in_ms - passed))
})

function fail(failure: unknown): void {
  if (!endSessionIfUnauthorized(failure)) {
    error.value = failure instanceof ApiError ? failure.message : t('missions.error')
  }
}

/** What the driver has after taking something: the cash in the bar at the top, and the coins. */
function refreshBars(): void {
  void run.refresh().catch(() => undefined)
  void premium.load().catch(() => undefined)
}

async function take(taking: () => Promise<MissionsClaimed | null>): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  error.value = null
  clearTold()

  try {
    const claimed = await taking()

    gave.value = claimed !== null && (claimed.cash_cents > 0 || claimed.xp > 0 || claimed.parts.length > 0 || claimed.car_xp > 0) ? claimed : null
    refreshBars()
  } catch (failure) {
    fail(failure)
  } finally {
    busy.value = false
  }
}

function claim(mission: Mission): Promise<void> {
  return take(() => missions.claim(mission.id))
}

function claimAll(): Promise<void> {
  return take(() => missions.claimAll(tab.value))
}

async function claimBonus(): Promise<void> {
  if (busy.value || tab.value === 'main') {
    return
  }

  const kind = tab.value as MissionPeriodKind

  busy.value = true
  error.value = null
  clearTold()

  try {
    const coins = await missions.claimBonus(kind)

    if (coins > 0) {
      tell(t('missions.bonusTaken', { coins: number(coins) }, coins))
    }

    refreshBars()
  } catch (failure) {
    fail(failure)
  } finally {
    busy.value = false
  }
}

const gaveLines = computed(() => (gave.value === null ? [] : rewards({ cash_cents: gave.value.cash_cents, xp: gave.value.xp, car_xp: gave.value.car_xp, items: [] })))
const collectedLine = computed(() => {
  const list = missions.collected
  const cash = list.reduce((sum, entry) => sum + (entry.type === 'mission' ? (entry.mission.reward.cash_cents ?? 0) : 0), 0)
  const coins = list.reduce((sum, entry) => sum + (entry.type === 'bonus' ? entry.coins : 0), 0)

  return list.length === 0 ? null : t('missions.collected', { cash: money(cash), coins: number(coins) })
})
</script>

<template>
  <GameShell>
    <header class="flex flex-col gap-1">
      <h1 class="ttl">{{ t('missions.title') }}</h1>
      <p class="sub">{{ t('missions.intro') }}</p>
    </header>

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('missions.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="open">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!loaded" class="text-muted" role="status" data-test="loading">{{ t('common.loading') }}</p>

    <p v-else-if="!missions.available && missions.view && missions.view.available === false" class="text-muted" data-test="none">{{ t('missions.none') }}</p>

    <template v-else>
      <nav class="seg" :aria-label="t('missions.tabs')" role="tablist" data-test="mission-tabs">
        <button
          v-for="kind in TABS"
          :key="kind"
          type="button"
          role="tab"
          :aria-selected="tab === kind"
          :class="{ on: tab === kind }"
          :data-test="`tab-${kind}`"
          @click="tab = kind"
        >
          {{ t(`missions.tab.${kind}`) }}
          <b v-if="takeable(kind) > 0" class="mbadge" :data-test="`tab-${kind}-count`">{{ takeable(kind) }}</b>
        </button>
      </nav>

      <p v-if="collectedLine" class="card card-hi text-sm" role="status" data-test="collected">{{ collectedLine }}</p>
      <p v-if="told" class="card card-hi text-sm" role="status" data-test="told">{{ told }}</p>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>

      <section
        v-if="gave"
        class="card card-hi flex flex-col gap-2"
        :aria-label="t('missions.gave')"
        data-test="gave"
      >
        <p class="kick !mb-0">{{ t('missions.gave') }}</p>
        <ul v-if="gaveLines.length > 0" class="flex flex-wrap gap-1.5">
          <li v-for="line in gaveLines" :key="line" class="chip chip-am !h-6 !px-2">{{ line }}</li>
        </ul>
        <article
          v-for="part in gave.parts"
          :key="part.id"
          class="card flex flex-col gap-1.5"
          :style="{ borderColor: `var(--color-r${rankOf(part.rarity)})` }"
          :data-rarity="part.rarity"
          data-test="gave-part"
        >
          <div class="flex flex-wrap items-center gap-2">
            <b class="text-base text-ink" data-test="gave-part-name">{{ part.name }}</b>
            <RarityMark :rarity="part.rarity" />
            <span class="tag">{{ t('part.tier', { tier: part.tier ?? 0 }) }}</span>
          </div>
          <PartStats :part="part" />
          <PartAffixes :part="part" />
        </article>
        <button type="button" class="btn btn-secondary btn-sm self-start" data-test="gave-close" @click="gave = null">{{ t('missions.gotIt') }}</button>
      </section>

      <p v-if="locked" class="card text-sm text-muted" data-test="locked">{{ t(`missions.locked.${tab === 'main' ? 'main' : 'sets'}`) }}</p>

      <!-- A set of a day, a week or a month. -->
      <section v-else-if="set" class="flex flex-col gap-3" :data-test="`set-${tab}`">
        <div class="flex items-center justify-between gap-2">
          <p class="kick !mb-0" data-test="set-count">{{ t('missions.setCount', { done: set.done, total: set.total }) }}</p>
          <span class="chip !h-6 !px-2" data-test="set-reset">{{ t('missions.resets', { time: resets }) }}</span>
        </div>

        <p v-if="!set.drawn" class="card text-sm text-muted" data-test="not-drawn">{{ t('missions.notDrawn') }}</p>

        <section v-if="set.bonus" class="card flex flex-col gap-2" :class="{ 'card-hi': set.bonus.state === 'ready' }" data-test="bonus">
          <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
              <h3 class="text-[15px] font-bold leading-tight">{{ t('missions.bonusTitle') }}</h3>
              <p class="mt-0.5 text-[12.5px] text-muted">{{ t(`missions.bonusText.${tab}`) }}</p>
            </div>
            <span class="chip chip-cy chip-gem" data-test="bonus-coins">+{{ number(set.bonus.coins) }}</span>
          </div>
          <div class="flex items-center gap-2">
            <div class="bar bar-cy flex-1" role="progressbar" :aria-valuenow="set.done" aria-valuemin="0" :aria-valuemax="set.total">
              <i :style="{ width: `${set.total > 0 ? Math.round((set.done / set.total) * 100) : 0}%` }" />
            </div>
            <span class="mono whitespace-nowrap text-[12px] text-muted">{{ set.done }} / {{ set.total }}</span>
          </div>
          <button v-if="set.bonus.state === 'ready'" type="button" class="btn btn-primary self-start" :disabled="busy" data-test="bonus-claim" @click="claimBonus">
            {{ t('missions.bonusClaim', { coins: number(set.bonus.coins) }, set.bonus.coins) }}
          </button>
          <span v-else-if="set.bonus.state === 'claimed'" class="tag tag-ok self-start" data-test="bonus-claimed">{{ t('missions.claimed') }}</span>
        </section>

        <MissionCard v-for="mission in set.missions" :key="mission.id" :mission="mission" :busy="busy" @claim="claim" />

        <button v-if="toTake > 1" type="button" class="btn btn-primary" :disabled="busy" data-test="claim-all" @click="claimAll">{{ t('missions.claimAll', { count: toTake }, toTake) }}</button>
      </section>

      <!-- The main missions: a chain at a time. -->
      <section v-else class="flex flex-col gap-4" data-test="main">
        <p v-if="chains.length === 0" class="card text-sm text-muted" data-test="no-chains">{{ t('missions.noChains') }}</p>

        <section v-for="chain in chains" :key="chain.key" class="flex flex-col gap-2" :data-test="`chain-${chain.key}`">
          <div class="flex items-center justify-between gap-2">
            <h2 class="text-lg font-bold">{{ chain.title }}</h2>
            <span class="chip !h-6 !px-2" data-test="chain-count">{{ t('missions.chainCount', { done: chain.done, total: chain.total }) }}</span>
          </div>
          <MissionCard v-for="mission in chain.missions" :key="mission.id" :mission="mission" :busy="busy" @claim="claim" />
          <p v-if="chain.waiting" class="card text-sm text-muted" data-test="chain-waiting">{{ t('missions.waiting', { level: chain.waiting.min_player_level }) }}</p>
          <p v-else-if="chain.done === chain.total && chain.missions.length === 0" class="card text-sm text-muted" data-test="chain-finished">{{ t('missions.chainFinished') }}</p>
        </section>

        <button v-if="toTake > 1" type="button" class="btn btn-primary" :disabled="busy" data-test="claim-all" @click="claimAll">{{ t('missions.claimAll', { count: toTake }, toTake) }}</button>
      </section>
    </template>
  </GameShell>
</template>
