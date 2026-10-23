<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { PartInfo } from '@/api/types'

const props = defineProps<{ part: Pick<PartInfo, 'stats'> }>()

const { t, te } = useI18n()

/** What the part does, in words: "Power +2%", "Grip 100". A statistic the messages do not know is shown as it is. */
const lines = computed(() =>
  Object.entries(props.part.stats).map(([key, value]) => {
    const shown = key === 'power_mult' ? Math.round((value - 1) * 100) : value

    return te(`part.stat.${key}`) ? t(`part.stat.${key}`, { value: shown }) : `${key} ${shown}`
  }),
)
</script>

<template>
  <ul class="flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-faint" data-test="part-stats">
    <li v-for="line in lines" :key="line">{{ line }}</li>
  </ul>
</template>
