<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { Mission } from '@/api/types'
import { useMissionText } from '@/features/missions/useMissionText'

/**
 * A mission: what it asks, how far the driver is (a bar with cells and the numbers), what it pays, and the button that takes the pay when it is done. A mission
 * of a set has no words of its own: its title is the goal and the number. A main mission has a title and a sentence.
 */
const props = defineProps<{ mission: Mission; busy?: boolean }>()

defineEmits<{ claim: [mission: Mission] }>()

const { t } = useI18n()
const { title, text, progress, percent, rewards } = useMissionText()

const done = computed(() => props.mission.status !== 'open')
const lines = computed(() => rewards(props.mission.reward))
</script>

<template>
  <article class="card flex flex-col gap-2" :class="{ 'card-hi': mission.status === 'completed' }" :data-test="`mission-${mission.id}`" :data-status="mission.status">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <h3 class="text-[15px] font-bold leading-tight" data-test="mission-title">{{ title(mission) }}</h3>
        <p v-if="text(mission)" class="mt-0.5 text-[12.5px] text-muted" data-test="mission-text">{{ text(mission) }}</p>
      </div>
      <span v-if="mission.status === 'claimed'" class="tag tag-ok" data-test="mission-claimed">{{ t('missions.claimed') }}</span>
      <span v-else-if="mission.status === 'completed'" class="tag tag-am" data-test="mission-done">{{ t('missions.done') }}</span>
    </div>

    <div class="flex items-center gap-2">
      <div class="bar flex-1" role="progressbar" :aria-label="title(mission)" :aria-valuenow="percent(mission)" aria-valuemin="0" aria-valuemax="100" data-test="mission-bar">
        <i :style="{ width: `${done ? 100 : percent(mission)}%` }" />
      </div>
      <span class="mono whitespace-nowrap text-[12px] text-muted" data-test="mission-progress">{{ progress(mission) }}</span>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2">
      <ul class="flex flex-wrap gap-1.5" :aria-label="t('missions.reward.label')" data-test="mission-rewards">
        <li v-for="line in lines" :key="line" class="chip chip-am !h-6 !px-2">{{ line }}</li>
      </ul>
      <button v-if="mission.status === 'completed'" type="button" class="btn btn-primary" :disabled="busy" data-test="mission-claim" @click="$emit('claim', mission)">
        {{ t('missions.claim') }}
      </button>
    </div>
  </article>
</template>
