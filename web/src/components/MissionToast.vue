<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'

import { useMissionText } from '@/features/missions/useMissionText'
import { useMissionsStore } from '@/stores/missions'

/**
 * Says on any screen of the game that a mission was done, once, for a few seconds: the server counts the missions when it is asked, so a mission that the car finished
 * while the driver was elsewhere is told when the next answer shows it.
 */
const { t } = useI18n()
const { title } = useMissionText()
const missions = useMissionsStore()
const route = useRoute()

/** How long the line stays: long enough to read. */
const SHOWN_MS = 8_000

const message = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined

watch(
  () => missions.justDone.length,
  (count) => {
    if (count === 0) {
      return
    }

    const first = missions.justDone[0]
    message.value = count === 1 ? t('missions.toast.one', { title: title(first) }) : t('missions.toast.many', { count }, count)
    missions.clearDone()

    clearTimeout(timer)
    timer = setTimeout(() => (message.value = null), SHOWN_MS)
  },
)

onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <RouterLink v-if="message && route.name !== 'missions'" :to="{ name: 'missions' }" class="card card-hi block text-sm no-underline" role="status" data-test="mission-toast">{{ message }}</RouterLink>
</template>
