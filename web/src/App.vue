<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink, RouterView } from 'vue-router'

import LanguageSwitcher from '@/components/LanguageSwitcher.vue'

const { t } = useI18n()
</script>

<template>
  <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-surface px-4 py-3">
    <RouterLink to="/" class="text-xl font-bold text-amber">{{ t('app.name') }}</RouterLink>
    <LanguageSwitcher />
  </header>
  <RouterView />
</template>
