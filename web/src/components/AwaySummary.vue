<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { RunSummary } from '@/api/types'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'

const props = defineProps<{
  summary: RunSummary
  /** The clock of the server now, to tell how long the driver was away. */
  nowMs: number
}>()

defineEmits<{ dismiss: [] }>()

const { t } = useI18n()
const { money, number } = useFormat()

const awayMs = computed(() => props.nowMs - (props.summary.from_ms ?? props.nowMs))
</script>

<template>
  <section class="card flex flex-col gap-4 border-amber" aria-labelledby="away-title" data-test="away">
    <h2 id="away-title" class="text-xl font-bold">{{ t('run.away.title') }}</h2>

    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
      <div>
        <dt class="text-sm text-muted">{{ t('run.away.duration') }}</dt>
        <dd class="text-lg font-bold" data-test="away-duration">{{ formatDuration(awayMs) }}</dd>
      </div>
      <div>
        <dt class="text-sm text-muted">{{ t('run.stat.laps') }}</dt>
        <dd class="text-lg font-bold" data-test="away-laps">{{ number(summary.laps) }}</dd>
      </div>
      <div>
        <dt class="text-sm text-muted">{{ t('run.away.earned') }}</dt>
        <dd class="text-lg font-bold text-amber" data-test="away-cash">+{{ money(summary.cash_cents, 2) }}</dd>
      </div>
      <div>
        <dt class="text-sm text-muted">{{ t('run.stat.xp') }}</dt>
        <dd class="text-lg font-bold" data-test="away-xp">{{ t('car.xp', { value: number(summary.xp) }) }}</dd>
      </div>
    </dl>

    <p v-if="summary.stop_reason" class="text-muted" data-test="away-reason">{{ t(`run.away.${summary.stop_reason}`) }}</p>

    <div>
      <button type="button" class="btn btn-primary" data-test="away-dismiss" @click="$emit('dismiss')">
        {{ t('run.away.dismiss') }}
      </button>
    </div>
  </section>
</template>
