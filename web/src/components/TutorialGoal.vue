<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'

import { TARGET_SCREEN } from '@/features/tutorial/targets'
import { useFormat } from '@/i18n/useFormat'
import { useTutorialStore } from '@/stores/tutorial'

/**
 * The goal in front of the driver, at every screen of the game: which step of the tutorial is next, what to do, what it waits for before it starts and what
 * it pays. It is there while the phone is put away, and while a step has not started, so the driver always knows what to do in the game.
 */
const { t } = useI18n()
const { money, number } = useFormat()
const store = useTutorialStore()
const route = useRoute()

const goal = computed(() => (store.showing ? null : store.goal))
const screen = computed(() => (goal.value?.target ? TARGET_SCREEN[goal.value.target] : null))
/** The driver is on the screen already: there is nowhere to lead them. */
const here = computed(() => screen.value === null || String(route.name) === screen.value)

const reward = computed(() => {
  const paid = goal.value?.reward

  return paid
    ? [
        paid.cash_cents > 0 ? money(paid.cash_cents) : null,
        paid.xp > 0 ? t('tutorial.goalXp', { xp: number(paid.xp) }) : null,
        paid.car_xp > 0 ? t('tutorial.goalCarXp', { xp: number(paid.car_xp) }) : null,
        paid.part,
        paid.garage ? t('tutorial.goalGarage', { city: paid.garage }) : null,
      ].filter((line): line is string => line !== null)
    : []
})
</script>

<template>
  <section v-if="goal" class="goal" :class="{ 'goal-wait': goal.waiting }" :aria-label="t('tutorial.goalAria')" data-test="goal">
    <div class="goal-top">
      <span class="goal-step" data-test="goal-step">{{ t('tutorial.goalStep', { number: goal.number, total: store.tutorial?.total ?? 0 }) }}</span>
      <span v-if="goal.optional" class="goal-opt" data-test="goal-optional">{{ t('tutorial.goalOptional') }}</span>
      <span class="goal-dots" aria-hidden="true">
        <i v-for="phase in 4" :key="phase" :class="{ on: phase <= goal.phase }" />
      </span>
    </div>
    <p class="goal-title" data-test="goal-title">
      <small>{{ goal.waiting ? t('tutorial.goalNext') : t('tutorial.goalLabel') }}</small>
      {{ goal.title }}
    </p>
    <p v-if="goal.waiting" class="goal-note" data-test="goal-waiting">{{ goal.waiting }}</p>
    <div v-if="reward.length > 0 || (screen && !here && !goal.waiting)" class="goal-foot">
      <p v-if="reward.length > 0" class="goal-reward" data-test="goal-reward">
        <span>{{ t('tutorial.goalReward') }}</span>
        <b v-for="line in reward" :key="line" class="chip chip-am !h-6 !px-2 !text-[11.5px]">{{ line }}</b>
      </p>
      <RouterLink v-if="screen && !here && !goal.waiting" :to="{ name: screen }" class="btn btn-secondary btn-sm flex-none" data-test="goal-show">{{ t('tutorial.goalShow') }}</RouterLink>
    </div>
  </section>
</template>
