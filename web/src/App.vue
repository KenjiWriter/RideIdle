<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink, RouterView, useRoute } from 'vue-router'

import BrandMark from '@/components/BrandMark.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'

const { t } = useI18n()
const route = useRoute()
</script>

<template>
  <!-- A screen that draws its own top bar (the landing page, the two steps of the start) has no header here, nor has a screen of the game, whose top bar is the HUD. -->
  <header v-if="!route.meta.bare && !route.meta.game" class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-surface px-4 py-3" data-test="app-header">
    <RouterLink to="/" class="no-underline" :aria-label="t('app.name')"><BrandMark class="!text-[22px]" /></RouterLink>
    <LanguageSwitcher />
  </header>
  <RouterView />
  <footer
    class="mx-auto flex max-w-[1120px] flex-wrap items-center gap-x-6 gap-y-2 px-5 pb-8 pt-4 font-mono text-xs text-faint"
    :class="route.meta.bare ? 'justify-between' : 'justify-center'"
    data-test="footer"
  >
    <!-- Without the header the language is chosen here. -->
    <LanguageSwitcher v-if="route.meta.bare" />
    <div class="flex gap-x-6">
      <RouterLink to="/terms" class="text-muted underline" data-test="footer-terms">{{ t('legal.terms') }}</RouterLink>
      <RouterLink to="/privacy" class="text-muted underline" data-test="footer-privacy">{{ t('legal.privacy') }}</RouterLink>
    </div>
  </footer>
</template>
