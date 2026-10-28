<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { AffixEffect, PartInfo } from '@/api/types'
import { useFormat } from '@/i18n/useFormat'

const props = defineProps<{ part: Pick<PartInfo, 'affixes' | 'unique'>; compact?: boolean }>()

const { t } = useI18n()
const { percent } = useFormat()

interface Line {
  key: string
  text: string
}

/** What an affix says, in words: "Grip +3.5%", "Durability: wear −12%", "Wet grip +5% (Wet, Mud)". The name is the one of the rules, in the language of the driver. */
function words(effect: AffixEffect, name: string | null, value: number, surfaces: string[] | null): string {
  return t(`part.affix.${effect}`, { name: name ?? t(`part.affix.unnamed.${effect}`), percent: percent(value), surfaces: (surfaces ?? []).map((surface) => t(`part.surface.${surface}`)).join(', ') })
}

const affixes = computed<Line[]>(() => (props.part.affixes ?? []).map((affix) => ({ key: affix.key, text: words(affix.effect, affix.name, affix.percent, affix.surfaces) })))

/** The effect of a legendary part: its name, and what it does (a sentence for a special effect, the modifiers as lines like the affixes). */
const unique = computed(() => {
  const effect = props.part.unique

  if (!effect) {
    return null
  }

  return {
    name: effect.name,
    text: effect.special ? t(`part.unique.${effect.special}`, { percent: percent(effect.percent ?? 0) }) : null,
    mods: (effect.mods ?? []).map((mod, index) => ({ key: `${mod.effect}-${mod.stat ?? index}`, text: words(mod.effect, mod.name, mod.percent, null) })),
  }
})
</script>

<template>
  <ul v-if="affixes.length" class="flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-cyan" :class="compact ? 'mt-0.5' : 'mt-1'" :aria-label="t('part.affix.title')" data-test="part-affixes">
    <li v-for="affix in affixes" :key="affix.key" :data-test="`affix-${affix.key}`">&#x2726; {{ affix.text }}</li>
  </ul>
  <!-- The effect of a legendary part is its own: its name, and what it does. -->
  <div v-if="unique" class="mt-1 border-l-2 border-amber pl-2 font-mono text-[11px] text-faint" data-test="part-unique">
    <p class="text-amber">
      <span class="sr-only">{{ t('part.unique.label') }}:</span>
      {{ unique.name }}
    </p>
    <p v-if="unique.text" data-test="unique-text">{{ unique.text }}</p>
    <ul v-if="unique.mods.length" class="flex flex-wrap gap-x-3">
      <li v-for="mod in unique.mods" :key="mod.key">{{ mod.text }}</li>
    </ul>
  </div>
</template>
