<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

const { t } = useI18n()
</script>

<template>
  <nav class="seg" :aria-label="t('fleet.tabs')" data-test="garage-tabs">
    <RouterLink :to="{ name: 'garage' }" exact-active-class="on" data-test="tab-garage">{{ t('fleet.tabCar') }}</RouterLink>
    <RouterLink :to="{ name: 'fleet' }" exact-active-class="on" data-test="tab-fleet">{{ t('fleet.tabFleet') }}</RouterLink>
  </nav>
</template>
