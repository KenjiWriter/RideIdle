<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { useCarClass } from '@/features/car/useCarClass'
import { gameTime } from '@/features/clock/gameClock'
import { levelOf } from '@/features/player/level'
import { usePremiumCoins } from '@/features/player/usePremiumCoins'
import { useFormat } from '@/i18n/useFormat'
import { useGarageStore } from '@/stores/garage'
import { useMissionsStore } from '@/stores/missions'
import { usePremiumStore } from '@/stores/premium'
import { useRunStore } from '@/stores/run'
import { useSessionStore } from '@/stores/session'

const { t, te } = useI18n()
const { money, number } = useFormat()
const { className } = useCarClass()
const run = useRunStore()
const garage = useGarageStore()
const session = useSessionStore()
const { coins, ensure: ensureCoins } = usePremiumCoins()
const premium = usePremiumStore()
const missions = useMissionsStore()

/** The plate of the missions is there until the game says it has none, with the number of the ones that can be taken. */
const showMissions = computed(() => missions.view?.available !== false)
const missionsCount = computed(() => missions.claimable)

const progress = computed(() => levelOf(run.wallet?.xp ?? 0))
const fill = computed(() => Math.min(100, Math.round((progress.value.into / progress.value.needed) * 100)))
/** The cash of the run, or the one the shop saw last when the run has not been asked for yet. */
const cash = computed(() => run.wallet?.cash_cents ?? garage.cash?.cash_cents ?? null)

/** The tank of the car that is played, in ten cells as on the screen of the fuel: null when there is no car, or its tank is not known. */
const FUEL_CELLS = 10
const fuelPercent = computed(() => (run.run?.tank_ml ? Math.min(100, Math.round((run.run.fuel_ml / run.run.tank_ml) * 100)) : null))
const fuelCells = computed(() => Math.ceil(((fuelPercent.value ?? 0) / 100) * FUEL_CELLS))

/** The car that is played: what the strip under the bars says of it. */
const car = computed(() => run.run?.car ?? null)
const carKind = computed(() => className(car.value?.class))

/** How often the time of the game is read again: a minute of the game is 7.5 s, so a look every 2 s never shows a stale one. */
const CLOCK_TICK_MS = 2_000

const now = ref(run.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  now.value = run.serverNow()
  ticker = setInterval(() => (now.value = run.serverNow()), CLOCK_TICK_MS)
  void ensureCoins()
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
    <div class="hud-main">
      <!-- The account and its settings are at the bottom of the garage: the way there is a gear, as on the board of the game. -->
      <RouterLink :to="{ name: 'garage', hash: '#account' }" class="hud-gear" :aria-label="t('hud.settings')" data-test="hud-gear">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path
            d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"
          />
          <circle cx="12" cy="12" r="3" />
        </svg>
      </RouterLink>

      <!-- The missions: a plate like the gear, with how many can be taken. -->
      <RouterLink
        v-if="showMissions"
        :to="{ name: 'missions' }"
        class="hud-gear hud-missions"
        :aria-label="missionsCount > 0 ? t('hud.missionsCount', { count: missionsCount }, missionsCount) : t('hud.missions')"
        data-test="hud-missions"
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="m9 11 3 3L22 4" />
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
        </svg>
        <b v-if="missionsCount > 0" class="hud-badge" data-test="hud-missions-count">{{ missionsCount > 99 ? '99+' : missionsCount }}</b>
      </RouterLink>

      <div class="lvl" data-test="level">
        <small>{{ t('hud.level') }}</small>
        <strong>{{ progress.level }}</strong>
      </div>

      <!-- The experience of the driver over the fuel of the car, each with its own bar, and the way to the tank. -->
      <div class="hud-meters">
        <div>
          <p class="hud-line">
            <span class="sr-only" data-test="driver">{{ session.me?.name }}</span>
            <span class="whitespace-nowrap" data-test="xp-progress">{{ t('hud.progress', { into: number(progress.into), needed: number(progress.needed) }) }}</span>
          </p>
          <div class="bar" role="progressbar" :aria-label="t('hud.xp')" :aria-valuenow="fill" aria-valuemin="0" aria-valuemax="100">
            <i :style="{ width: `${fill}%` }" />
          </div>
        </div>

        <div v-if="fuelPercent !== null" class="hud-fuel" data-test="hud-fuel">
          <div class="fuelbar hud-fuelbar" role="progressbar" :aria-label="t('hud.fuel', { percent: fuelPercent })" :aria-valuenow="fuelPercent" aria-valuemin="0" aria-valuemax="100" data-test="hud-fuel-bar">
            <i v-for="cell in FUEL_CELLS" :key="cell" :class="cell <= fuelCells ? (fuelPercent < 15 ? 'lo' : 'on') : ''" />
          </div>
          <RouterLink :to="{ name: 'garage', hash: '#fuel' }" class="hud-plus" :aria-label="t('hud.refuel')" data-test="hud-fuel-plus">+</RouterLink>
        </div>
      </div>

      <!-- The two currencies as pills, the cash and the premium coins. -->
      <div class="hud-money">
        <span v-if="cash !== null" class="chip chip-am chip-coin h-6" data-tutorial="cash" data-test="cash-chip">{{ money(cash, 2) }}</span>
        <!-- The way to the premium shop: the coins, the VIP account and the pass are bought there. -->
        <RouterLink v-if="coins !== null" :to="{ name: 'premium' }" class="chip chip-cy chip-gem h-6 hover:brightness-110" :aria-label="t('hud.coins', { count: number(coins) })" data-test="coins-chip">{{ number(coins) }}</RouterLink>
      </div>
    </div>

    <!-- The car that is played, with its tier, and the time and the weather of its place. -->
    <div class="hud-car" data-test="hud-car">
      <template v-if="car">
        <span class="hud-car-name" data-test="hud-car-name">{{ car.name }}</span>
        <span v-if="car.tier" class="tier tier-cy" data-test="hud-car-tier">{{ t('car.tier', { tier: car.tier }) }}</span>
        <span v-if="carKind" class="hud-car-kind" data-test="hud-car-kind">{{ carKind }}</span>
      </template>
      <span v-if="premium.vipActiveAt(now)" class="tag tag-am" :title="t('hud.vipTitle')" data-test="hud-vip">{{ t('hud.vip') }}</span>
      <span v-if="premium.infinityActiveAt(now)" class="tag tag-ok" :title="t('hud.passTitle')" data-test="hud-pass">{{ t('hud.pass') }}</span>
      <span class="hud-clock" :title="t('clock.label', { time: time.label, phase: phaseName })" data-test="clock">{{ clockText }}</span>
    </div>
  </div>
</template>
