<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { levelOf } from '@/features/player/level'
import { useFormat } from '@/i18n/useFormat'
import { useGarageStore } from '@/stores/garage'
import { useRunStore } from '@/stores/run'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const { money, number } = useFormat()
const run = useRunStore()
const garage = useGarageStore()
const session = useSessionStore()

const progress = computed(() => levelOf(run.wallet?.xp ?? 0))
const fill = computed(() => Math.min(100, Math.round((progress.value.into / progress.value.needed) * 100)))
/** The cash of the run, or the one the shop saw last when the run has not been asked for yet. */
const cash = computed(() => run.wallet?.cash_cents ?? garage.cash?.cash_cents ?? null)
</script>

<template>
  <div class="hud" data-test="hud">
    <div class="lvl" data-test="level">
      <small>{{ t('hud.level') }}</small>
      <strong>{{ progress.level }}</strong>
    </div>

    <div class="min-w-0 flex-1">
      <div class="mb-1 flex justify-between gap-2 text-xs text-muted">
        <span class="truncate" data-test="driver">{{ session.me?.name }}</span>
        <span class="whitespace-nowrap" data-test="xp-progress">{{ t('hud.progress', { into: number(progress.into), needed: number(progress.needed) }) }}</span>
      </div>
      <div class="bar" role="progressbar" :aria-label="t('hud.xp')" :aria-valuenow="fill" aria-valuemin="0" aria-valuemax="100">
        <i :style="{ width: `${fill}%` }" />
      </div>
    </div>

    <span v-if="cash !== null" class="chip chip-am chip-coin" data-tutorial="cash" data-test="cash-chip">{{ money(cash, 2) }}</span>
  </div>
</template>
