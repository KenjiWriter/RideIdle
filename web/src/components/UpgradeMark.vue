<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{ level: number }>()

const { t } = useI18n()
</script>

<template>
  <!-- The upgrade (+N) of a part, which stays with it: nothing for a part that is not upgraded. -->
  <span v-if="level > 0" class="tag tag-am" :aria-label="t('part.upgrade', { level })" data-test="upgrade-mark">+{{ level }}</span>
</template>
