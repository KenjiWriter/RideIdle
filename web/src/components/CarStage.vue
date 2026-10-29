<script setup lang="ts">
import { computed } from 'vue'

import CarSilhouette from '@/components/CarSilhouette.vue'
import MediaPicture from '@/components/MediaPicture.vue'
import { stageArt } from '@/features/art'

const props = defineProps<{
  /** The name of the car: drawn in outline behind it, and what its picture says to a reader. */
  name: string
  /** The picture the pack gives the car (a path of the API). */
  src?: string | null
  /** The slug of the car, by which the app finds the picture it brings when the pack gives none. */
  slug?: string | null
  /** The smaller stage of a card in a list. */
  compact?: boolean
}>()

/** A picture with a ground of its own (a pack's, or the app's without a cut-out) stays on the dark stage and is faded into it; a cut-out stands on the light one. */
const art = computed(() => stageArt(props.src, props.slug))
</script>

<template>
  <div class="stage" :class="{ 'stage-sm': compact, 'stage-light': art.cutout }" data-test="stage">
    <span class="stage-name" aria-hidden="true">{{ name }}</span>
    <MediaPicture :src="src" :fallback="art.picture" :alt="name" class="stage-car" data-test="picture">
      <!-- A car that has no picture stands on the stage as the outline of a body. -->
      <template #placeholder>
        <span class="stage-empty" data-test="picture-empty" role="img" :aria-label="name">
          <CarSilhouette />
        </span>
      </template>
    </MediaPicture>
    <slot name="spots" />
    <div v-if="$slots.default" class="stage-tags"><slot /></div>
    <div v-if="$slots.price" class="stage-price"><slot name="price" /></div>
  </div>
</template>
