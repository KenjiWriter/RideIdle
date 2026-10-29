<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { compareStats, type StatChange } from '@/features/parts/compare'
import { useFormat } from '@/i18n/useFormat'

const props = defineProps<{
  /** The part for sale. */
  offer: { stats: Record<string, number> }
  /** The part that is fitted on the same slot of the car, or null when there is none (the slot holds the factory part, which has no statistics to set against). */
  fitted: { name: string; stats: Record<string, number> } | null
}>()

const { t, te } = useI18n()
const { fixed } = useFormat()

const comparison = computed(() => (props.fitted ? compareStats(props.offer.stats, props.fitted.stats) : null))

/** The name of a statistic without its value, or its key where the messages do not know it. */
const label = (key: string): string => (te(`shop.compare.stat.${key}`) ? t(`shop.compare.stat.${key}`) : key)

/** The change as the part writes its numbers: with its sign (the minus is the real one) and its unit. */
const text = (change: StatChange): string => `${change.delta > 0 ? '+' : '−'}${fixed(Math.abs(change.delta), change.digits)}${change.unit}`
</script>

<template>
  <!-- A part for sale against the one on the car: what is better in green and what is worse in red, the arrow says whether the number goes up or down. -->
  <div v-if="fitted && comparison && comparison.shared > 0" class="mt-1" data-test="part-compare">
    <p class="kick !mb-1 !text-[10px]" data-test="compare-title">{{ t('shop.compare.title', { name: fitted.name }) }}</p>
    <ul v-if="comparison.changes.length > 0" class="flex flex-wrap gap-1">
      <li v-for="change in comparison.changes" :key="change.key" class="cmp" :class="change.better ? 'cmp-up' : 'cmp-down'" :data-test="`compare-${change.key}`">
        <span aria-hidden="true">{{ change.delta > 0 ? '▲' : '▼' }}</span>
        {{ label(change.key) }} {{ text(change) }}
        <span class="sr-only">({{ change.better ? t('shop.compare.better') : t('shop.compare.worse') }})</span>
      </li>
    </ul>
    <p v-else class="sub" data-test="compare-same">{{ t('shop.compare.same') }}</p>
  </div>
</template>
