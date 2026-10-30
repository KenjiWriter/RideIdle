<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { formatTimeLeft } from '@/i18n/format'
import { useMissionsStore } from '@/stores/missions'

/** The missions of the day in one line, on the garage: how many are done, when the day resets, and how many wait to be taken. A click leads to the missions. */
const { t } = useI18n()
const missions = useMissionsStore()

const TICK_MS = 30_000
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  ticker = setInterval(() => (now.value = Date.now()), TICK_MS)
})

onBeforeUnmount(() => clearInterval(ticker))

const day = computed(() => (missions.available && missions.periods?.daily?.drawn ? missions.periods.daily : null))
const resets = computed(() => (day.value === null ? '' : formatTimeLeft(Math.max(0, day.value.resets_in_ms - Math.max(0, now.value - (missions.seenAt || now.value))))))
const percent = computed(() => (day.value === null || day.value.total === 0 ? 0 : Math.round((day.value.done / day.value.total) * 100)))
</script>

<template>
  <RouterLink v-if="day" :to="{ name: 'missions' }" class="card flex flex-col gap-1.5 no-underline" :class="{ 'card-hi': missions.claimable > 0 }" data-test="missions-glance">
    <div class="flex items-center justify-between gap-2">
      <span class="kick !mb-0">{{ t('missions.glance.title') }}</span>
      <span v-if="missions.claimable > 0" class="tag tag-am" data-test="missions-glance-take">{{ t('missions.glance.take', { count: missions.claimable }, missions.claimable) }}</span>
      <span v-else class="mono text-[12px] text-muted" data-test="missions-glance-reset">{{ t('missions.resets', { time: resets }) }}</span>
    </div>
    <div class="flex items-center gap-2">
      <div class="bar bar-cy flex-1" role="progressbar" :aria-label="t('missions.glance.title')" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
        <i :style="{ width: `${percent}%` }" />
      </div>
      <span class="mono whitespace-nowrap text-[12px] text-muted" data-test="missions-glance-count">{{ day.done }} / {{ day.total }}</span>
    </div>
  </RouterLink>
</template>
