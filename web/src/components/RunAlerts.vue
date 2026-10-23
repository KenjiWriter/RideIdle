<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'

import type { Run } from '@/api/types'
import { useRunStatus } from '@/features/run/useRunStatus'
import { formatDuration } from '@/i18n/format'
import { useRunStore } from '@/stores/run'

import RepairControl from './RepairControl.vue'
import TowControl from './TowControl.vue'

const props = defineProps<{ run: Run }>()

const { t } = useI18n()
const route = useRoute()
const { stopped, towing, towMs } = useRunStatus()
const store = useRunStore()

/** The time the car still has on the road, counted by the clock of the server, so that the banner of a journey runs down by itself. */
const now = ref(store.serverNow())
let ticker: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  ticker = setInterval(() => (now.value = store.serverNow()), 1_000)
})

onBeforeUnmount(() => clearInterval(ticker))

const leftMs = computed(() => (props.run.travel ? Math.max(0, props.run.travel.arrive_ms - now.value) : 0))
</script>

<template>
  <!-- Why the car stands, and the way out: the repair, the tow, and the wait of the tow. -->
  <div class="flex flex-col gap-3" data-test="run-alerts">
    <p v-if="stopped && run.stop_reason === 'travel' && run.travel" class="card card-hi text-sm" role="status" data-test="banner">
      {{ t('run.banner.travel', { city: run.travel.to_name, time: formatDuration(leftMs) }) }}
    </p>
    <!-- A car that waits in the garage: on the tournament screen the Start says it, and elsewhere the way to it is here. -->
    <div v-else-if="stopped && run.stop_reason === 'parked' && route.name !== 'race'" class="card card-hi flex flex-col gap-2.5 text-sm" role="status" data-test="banner">
      <p>{{ t('run.banner.parked') }}</p>
      <RouterLink :to="{ name: 'race' }" class="btn btn-primary btn-sm no-underline" data-test="to-race">{{ t('run.goToRace') }}</RouterLink>
    </div>
    <p v-else-if="stopped && run.stop_reason && run.stop_reason !== 'parked'" class="card card-hi text-sm" role="status" data-test="banner">
      {{ t(`run.banner.${run.stop_reason}`) }}
    </p>

    <RepairControl :run="run" />

    <TowControl :run="run" />

    <p v-if="towing" class="card card-hi text-sm" role="status" data-test="towing">
      {{ t('run.tow.underway', { time: formatDuration(towMs) }) }}
    </p>
  </div>
</template>
