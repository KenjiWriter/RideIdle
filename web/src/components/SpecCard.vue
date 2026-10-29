<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import { useFormat } from '@/i18n/useFormat'

withDefaults(
  defineProps<{
    /** The name of the car. */
    name: string
    /** The small line over the name: the kind of body. */
    kicker?: string | null
    /** The tier of the car, as the plate beside the name; none for a car that does not say. */
    tier?: number | null
    /** The overall score, or null for a card that has no statistics to count it from. */
    score?: number | null
    /** The heading the name is: the title of a screen, or a heading inside one. */
    level?: 'h1' | 'h2'
    headingId?: string
  }>(),
  { kicker: null, tier: null, score: null, level: 'h2', headingId: undefined },
)

const { t } = useI18n()
const { number } = useFormat()
</script>

<template>
  <section class="card flex flex-col gap-3" data-test="spec">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p v-if="kicker" class="kick !mb-1.5" data-test="car-kind">{{ kicker }}</p>
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <component :is="level" :id="headingId" class="ttl" data-test="car">{{ name }}</component>
          <span v-if="tier" class="tier" :title="t('car.tierTitle', { tier })" data-test="car-tier">{{ t('car.tier', { tier }) }}</span>
        </div>
      </div>
      <div v-if="score !== null" class="score flex-none" data-test="score">
        <small>{{ t('car.score') }}</small>
        <b>{{ number(score) }}</b>
      </div>
    </div>
    <slot />
  </section>
</template>
