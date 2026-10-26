<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { PartInfo, SurfaceKey } from '@/api/types'
import { useFormat } from '@/i18n/useFormat'

const props = defineProps<{ part: Pick<PartInfo, 'stats' | 'surfaces'> }>()

const { t, te } = useI18n()
const { fixed } = useFormat()

/** The surfaces in the order the matrix of the rules lists them. */
const SURFACES: SurfaceKey[] = ['asphalt_dry', 'asphalt_wet', 'gravel_dry', 'mud', 'snow']

/** What the part does, in words: "Power +2%", "Grip 100". A statistic the messages do not know is shown as it is. */
const lines = computed(() =>
  Object.entries(props.part.stats).map(([key, value]) => {
    // A rarity multiplies the statistics, so 98 can be 135.24 and a power gain of 2% can be 2.4%: shown to a tenth.
    const shown = key === 'power_mult' ? Math.round((value - 1) * 1000) / 10 : Math.round(value * 10) / 10

    return te(`part.stat.${key}`) ? t(`part.stat.${key}`, { value: shown }) : `${key} ${shown}`
  }),
)

/** Tires only: how they grip on each surface, the thing that tells one kind from another. The surfaces they do best on are brighter. */
const surfaces = computed(() => {
  const grip = props.part.surfaces

  return grip
    ? SURFACES.map((surface) => ({
        key: surface,
        text: t('part.surfaceLine', { surface: t(`part.surface.${surface}`), value: fixed(grip[surface], 2) }),
        good: grip[surface] >= 1,
      }))
    : []
})
</script>

<template>
  <ul class="flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-faint" data-test="part-stats">
    <li v-for="line in lines" :key="line">{{ line }}</li>
  </ul>
  <ul v-if="surfaces.length" class="mt-1 flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-faint" data-test="part-surfaces">
    <li v-for="surface in surfaces" :key="surface.key" :class="{ 'text-cyan': surface.good }" :data-test="`surface-${surface.key}`">{{ surface.text }}</li>
  </ul>
</template>
