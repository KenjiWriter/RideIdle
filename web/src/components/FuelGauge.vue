<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { Run } from '@/api/types'
import { baseLapMs, lapsOfFuel } from '@/features/run/laps'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'

import RefuelControl from './RefuelControl.vue'

const props = defineProps<{ run: Run }>()

const { t } = useI18n()
const { number } = useFormat()

/** The tank is drawn in cells, as in the design. */
const CELLS = 10

const percent = computed(() => (props.run.tank_ml ? Math.min(100, Math.round((props.run.fuel_ml / props.run.tank_ml) * 100)) : 0))
const cells = computed(() => Math.ceil((percent.value / 100) * CELLS))
const laps = computed(() => lapsOfFuel(props.run))
const time = computed(() => formatDuration(laps.value * baseLapMs(props.run)))

function liters(ml: number): string {
  return t('car.liters', { value: number(ml / 1000, 1) })
}
</script>

<template>
  <section class="card flex flex-col gap-3" aria-labelledby="fuel-title" data-test="fuel">
    <div class="flex items-baseline justify-between gap-3">
      <h2 id="fuel-title" class="ttl text-2xl">{{ t('run.fuel.title') }}</h2>
      <span class="font-semibold" data-test="fuel-level">
        {{ liters(run.fuel_ml) }}<template v-if="run.tank_ml"> / {{ liters(run.tank_ml) }}</template>
      </span>
    </div>

    <div
      class="fuelbar"
      role="progressbar"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-valuenow="percent"
      :aria-labelledby="'fuel-title'"
      data-test="fuel-bar"
    >
      <i v-for="cell in CELLS" :key="cell" :class="cell <= cells ? (percent < 15 ? 'lo' : 'on') : ''" />
    </div>

    <p class="text-sm text-muted" data-test="fuel-range">
      {{ laps > 0 ? t('run.fuel.range', { laps: t('run.laps', { n: number(laps) }, laps), time }) : t('run.fuel.empty') }}
    </p>

    <RefuelControl :run="run" />
  </section>
</template>
