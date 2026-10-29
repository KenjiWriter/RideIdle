<script setup lang="ts">
import { computed } from 'vue'

import { iconOf } from '@/features/car/hotspots'

const props = defineProps<{ name: string }>()

const paths = computed(() => iconOf(props.name))
</script>

<template>
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path v-for="(d, index) in paths" :key="index" :d="d" />
  </svg>
</template>
