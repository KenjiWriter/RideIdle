<script setup lang="ts">
import CarSilhouette from '@/components/CarSilhouette.vue'
import MediaPicture from '@/components/MediaPicture.vue'

defineProps<{
  /** The name of the car: drawn in outline behind it, and what its picture says to a reader. */
  name: string
  /** The picture the pack gives the car (a path of the API), and the one the app brings. */
  src?: string | null
  fallback?: string | null
  /** The smaller stage of a card in a list. */
  compact?: boolean
}>()
</script>

<template>
  <div class="stage" :class="{ 'stage-sm': compact }" data-test="stage">
    <span class="stage-name" aria-hidden="true">{{ name }}</span>
    <MediaPicture :src="src" :fallback="fallback" :alt="name" class="stage-car" data-test="picture">
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
