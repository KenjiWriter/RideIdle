<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'

const { t } = useI18n()
const route = useRoute()

/** The icons are the ones of the design canvas (24 x 24, drawn with a line). */
const links = [
  { name: 'map', label: 'nav.map', icon: 'M9 4 3 6v14l6-2 6 2 6-2V4l-6 2zM9 4v14M15 6v14' },
  // The fleet is a part of the garage, so the garage stays lit there, as on the board.
  { name: 'garage', label: 'nav.garage', icon: 'M3 20V9l9-5 9 5v11M7 20v-7h10v7M7 16h10', also: ['fleet'] },
  { name: 'race', label: 'nav.race', icon: 'M5 21V4M5 4h12l-2 4 2 4H5' },
  {
    name: 'workshop',
    label: 'nav.workshop',
    icon: 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z',
  },
  { name: 'shop', label: 'nav.shop', icon: 'M3 21h18M5 21V9l5-3v15M10 21V4l6 3v14M16 21V11l4 2v8' },
] as const

const lit = (link: (typeof links)[number]): boolean => 'also' in link && (link.also as readonly string[]).includes(String(route.name))
</script>

<template>
  <nav class="navb" :aria-label="t('nav.label')" data-test="game-nav">
    <RouterLink v-for="link in links" :key="link.name" :to="{ name: link.name }" active-class="on" :class="{ on: lit(link) }" :data-test="`nav-${link.name}`">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="link.icon" />
      </svg>
      {{ t(link.label) }}
    </RouterLink>
  </nav>
</template>
