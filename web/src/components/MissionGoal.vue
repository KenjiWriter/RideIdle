<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'

import { useMissionText } from '@/features/missions/useMissionText'
import { useMissionsStore } from '@/stores/missions'
import { useTutorialStore } from '@/stores/tutorial'

/**
 * The goal under the bar at the top once the tutorial is over: the main mission the driver is nearest to, or the one that is done and waits for them to take it. The
 * guided path of the tutorial goes on in the main missions, so the driver always knows what to do next and what it pays.
 */
const { t } = useI18n()
const { title, progress, rewards } = useMissionText()
const missions = useMissionsStore()
const tutorial = useTutorialStore()
const route = useRoute()

/** The tutorial speaks first: it is asked for, and it has no goal or phone to show. */
const mission = computed(() => (tutorial.asked && tutorial.goal === null && !tutorial.showing ? missions.goalMission : null))
const done = computed(() => mission.value?.status === 'completed')
const chain = computed(() => missions.main.find((candidate) => candidate.key === mission.value?.chain)?.title ?? null)
const reward = computed(() => (mission.value ? rewards(mission.value.reward) : []))
</script>

<template>
  <section v-if="mission" class="goal" :class="{ 'goal-wait': !done }" :aria-label="t('missions.goal.aria')" data-test="mission-goal">
    <div class="goal-top">
      <span class="goal-step">{{ t('missions.goal.label') }}</span>
      <span v-if="chain" class="goal-opt" data-test="mission-goal-chain">{{ chain }}</span>
    </div>
    <p class="goal-title" data-test="mission-goal-title">
      <small>{{ done ? t('missions.goal.ready') : t('missions.goal.next') }}</small>
      {{ title(mission) }}
    </p>
    <p v-if="!done" class="goal-note mono" data-test="mission-goal-progress">{{ progress(mission) }}</p>
    <div class="goal-foot">
      <p v-if="reward.length > 0" class="goal-reward" data-test="mission-goal-reward">
        <span>{{ t('tutorial.goalReward') }}</span>
        <b v-for="line in reward" :key="line" class="chip chip-am !h-6 !px-2 !text-[11.5px]">{{ line }}</b>
      </p>
      <RouterLink v-if="route.name !== 'missions'" :to="{ name: 'missions' }" class="btn btn-secondary btn-sm flex-none" data-test="mission-goal-show">{{ done ? t('missions.goal.take') : t('missions.goal.show') }}</RouterLink>
    </div>
  </section>
</template>
