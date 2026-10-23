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
  <footer class="mx-auto flex max-w-2xl flex-wrap justify-center gap-x-6 gap-y-2 px-4 pb-10 pt-4 text-sm text-muted" data-test="footer">
    <RouterLink to="/terms" class="underline" data-test="footer-terms">{{ t('legal.terms') }}</RouterLink>
    <RouterLink to="/privacy" class="underline" data-test="footer-privacy">{{ t('legal.privacy') }}</RouterLink>
  </footer>
</template>
