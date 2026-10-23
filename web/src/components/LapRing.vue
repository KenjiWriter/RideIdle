<script setup lang="ts">
import { computed } from 'vue'

/** The circle of the board: a lap round it, the time to the end in the middle. The radius is 48 in a box of 112. */
const RADIUS = 48
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

const props = defineProps<{
  /** How much of the lap is done, from 0 to 1. */
  fraction: number
  /** What stands in the middle, big. */
  value: string
  /** The line under it, small capitals. */
  caption: string
  /** What a screen reader hears. */
  label: string
}>()

const dash = computed(() => `${(Math.min(1, Math.max(0, props.fraction)) * CIRCUMFERENCE).toFixed(1)} ${CIRCUMFERENCE.toFixed(1)}`)
</script>

<template>
  <svg width="112" height="112" viewBox="0 0 112 112" role="img" :aria-label="label" data-test="ring">
    <circle cx="56" cy="56" r="48" fill="none" stroke="#262C36" stroke-width="9" />
    <!-- With nothing done there is no arc: a round cap would still draw a dot at the top. -->
    <circle v-if="fraction > 0" cx="56" cy="56" r="48" fill="none" stroke="#FFB224" stroke-width="9" stroke-linecap="round" :stroke-dasharray="dash" transform="rotate(-90 56 56)" data-test="ring-arc" />
    <text x="56" y="62" text-anchor="middle" class="ring-value" data-test="ring-value">{{ value }}</text>
    <text x="56" y="78" text-anchor="middle" class="ring-caption">{{ caption }}</text>
  </svg>
</template>

<style scoped>
.ring-value {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 32px;
  fill: var(--color-ink);
}

.ring-caption {
  font-family: var(--font-mono);
  font-size: 9px;
  letter-spacing: 0.1em;
  fill: var(--color-faint);
}
</style>
