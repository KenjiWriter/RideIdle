<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type { Verdict } from '@/features/parts/compare'

defineProps<{
  verdict: Verdict
}>()

const { t } = useI18n()

/** The arrow of each verdict: up and green for a part worth fitting, down and red for one that is worse, two ways for one that depends, a bar for one that is no different. */
const ARROWS = { better: '▲', worse: '▼', mixed: '⇅', same: '=' } as const
</script>

<template>
  <!-- Whether the part is worth fitting in place of the one on the car, in a word and an arrow. -->
  <p class="verdict" :class="`verdict-${verdict.kind}`" :data-test="`verdict-${verdict.kind}`">
    <span aria-hidden="true">{{ ARROWS[verdict.kind] }}</span>
    {{ t(`shop.compare.verdict.${verdict.kind}`) }}
  </p>
</template>
