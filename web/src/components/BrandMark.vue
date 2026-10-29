<script setup lang="ts">
import { computed } from 'vue'

import { brandParts } from '@/game/brand'
import { useGame } from '@/game'

/** The name of the game in the type of the titles: the first part in white, the second in amber (RideIdle: Ride and Idle). */
const { name } = useGame()
const parts = computed(() => brandParts(name.value))
</script>

<template>
  <span class="brand" data-test="brand">{{ parts.first }}{{ parts.spaced ? ' ' : '' }}<i v-if="parts.second">{{ parts.second }}</i></span>
</template>
