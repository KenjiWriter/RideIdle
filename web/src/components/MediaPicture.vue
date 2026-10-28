<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { mediaUrl } from '@/api/client'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  /** The picture the pack gives, a path of the API (`image_url`). */
  src: string | null | undefined
  /** What to show when the pack gives none, or the file is not there: a picture bundled with the app. */
  fallback?: string | null
  alt: string
}>()

const failed = ref(false)
watch(
  () => props.src,
  () => (failed.value = false),
)

const shown = computed(() => (failed.value ? null : mediaUrl(props.src)) ?? props.fallback ?? null)
</script>

<template>
  <img v-if="shown" v-bind="$attrs" :src="shown" :alt="alt" loading="lazy" decoding="async" @error="failed = true" />
  <slot v-else name="placeholder" />
</template>
