<script setup lang="ts">
import { onMounted } from 'vue'

import { useRunStore } from '@/stores/run'

import GameHud from './GameHud.vue'
import GameNav from './GameNav.vue'

const props = withDefaults(defineProps<{ loadRun?: boolean }>(), { loadRun: true })

const run = useRunStore()

// The HUD shows the cash and the experience of the run. A screen that does not load the run itself asks for it once.
onMounted(() => {
  if (props.loadRun && !run.loaded) {
    run.refresh().catch(() => undefined)
  }
})
</script>

<template>
  <div class="mx-auto flex min-h-[calc(100vh-61px)] w-full max-w-[480px] flex-col border-x border-line bg-bg/70">
    <GameHud />
    <div class="flex flex-1 flex-col gap-3 px-3.5 py-3">
      <slot />
    </div>
    <GameNav />
  </div>
</template>
