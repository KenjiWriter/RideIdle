<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

const { t } = useI18n()
</script>

<template>
  <nav class="seg" :aria-label="t('market.tabs')" data-test="city-tabs">
    <RouterLink :to="{ name: 'shop' }" exact-active-class="on" data-test="tab-shop">{{ t('market.tabShop') }}</RouterLink>
    <RouterLink :to="{ name: 'exchange' }" exact-active-class="on" data-test="tab-exchange">{{ t('market.tabExchange') }}</RouterLink>
    <RouterLink :to="{ name: 'dealer' }" exact-active-class="on" data-test="tab-dealer">{{ t('market.tabDealer') }}</RouterLink>
    <RouterLink :to="{ name: 'storage' }" exact-active-class="on" data-test="tab-storage">{{ t('market.tabStorage') }}</RouterLink>
  </nav>
</template>
