<script setup lang="ts">
import { onMounted } from 'vue'

import TutorialGoal from '@/components/TutorialGoal.vue'
import TutorialOverlay from '@/components/TutorialOverlay.vue'
import { useTutorialReward } from '@/features/run/useTutorialReward'
import { useTutorialSync } from '@/features/tutorial/useTutorialSync'
import { useGarageStore } from '@/stores/garage'
import { useRunStore } from '@/stores/run'
import { useTutorialStore } from '@/stores/tutorial'

import GameHud from './GameHud.vue'
import GameNav from './GameNav.vue'

const props = withDefaults(defineProps<{ loadRun?: boolean; loadTutorial?: boolean }>(), { loadRun: true, loadTutorial: true })

const run = useRunStore()
const garage = useGarageStore()
const tutorial = useTutorialStore()

onMounted(() => {
  // The HUD shows the cash and the experience of the run. A screen that does not load the run itself asks for it once.
  if (props.loadRun && !run.loaded) {
    run.refresh().catch(() => undefined)
  }

  // The mentor leads the driver on every screen of the game, so a screen that does not ask for the tutorial itself (the garage and the race screen do, in their
  // own order) asks for it once. It is no reason for an error: without it the screen is the screen.
  if (props.loadTutorial && !tutorial.asked) {
    tutorial.load().catch(() => undefined)
  }
})

// The goal of the step that is on is kept true on every screen of the game, without the driver having to go to another tab to bring it up to date.
useTutorialSync()

/**
 * What the mentor paid for a step is said once, on whichever screen the driver is on. The experience that went to the car shows in the bar of the garage, which is asked for again.
 */
const { rewardMessage } = useTutorialReward(() => {
  if (garage.loaded) {
    void garage.loadGarage().catch(() => undefined)
  }
})
</script>

<template>
  <div class="mx-auto flex min-h-screen w-full max-w-[480px] flex-col border-x border-line bg-bg/70">
    <GameHud />
    <div class="flex flex-1 flex-col gap-3 px-3.5 py-3">
      <TutorialGoal />
      <p v-if="rewardMessage" class="card card-hi text-sm" role="status" data-test="tutorial-reward">{{ rewardMessage }}</p>
      <slot />
    </div>
    <GameNav />
    <TutorialOverlay />
  </div>
</template>
