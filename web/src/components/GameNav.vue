<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

const { t } = useI18n()

const links = [
  { name: 'garage', label: 'nav.garage' },
  { name: 'workshop', label: 'nav.workshop' },
  { name: 'shop', label: 'nav.shop' },
] as const
</script>

<template>
  <nav class="grid grid-cols-3 gap-1 rounded-xl border border-line bg-surface p-1 text-center text-sm font-bold" :aria-label="t('nav.label')" data-test="game-nav">
    <RouterLink
      v-for="link in links"
      :key="link.name"
      :to="{ name: link.name }"
      class="rounded-lg px-3 py-3 text-muted"
      active-class="bg-surface-2 text-amber"
      :data-test="`nav-${link.name}`"
    >
      {{ t(link.label) }}
    </RouterLink>
  </nav>
</template>
