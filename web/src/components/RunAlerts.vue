<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type { Run } from '@/api/types'
import { useRunStatus } from '@/features/run/useRunStatus'
import { formatDuration } from '@/i18n/format'

import RepairControl from './RepairControl.vue'
import TowControl from './TowControl.vue'

defineProps<{ run: Run }>()

const { t } = useI18n()
const { stopped, towing, towMs } = useRunStatus()
</script>

<template>
  <!-- Why the car stands, and the way out: the repair, the tow, and the wait of the tow. -->
  <div class="flex flex-col gap-3" data-test="run-alerts">
    <p v-if="stopped && run.stop_reason" class="card card-hi text-sm" role="status" data-test="banner">
      {{ t(`run.banner.${run.stop_reason}`) }}
    </p>

    <RepairControl :run="run" />

    <TowControl :run="run" />

    <p v-if="towing" class="card card-hi text-sm" role="status" data-test="towing">
      {{ t('run.tow.underway', { time: formatDuration(towMs) }) }}
    </p>
  </div>
</template>
