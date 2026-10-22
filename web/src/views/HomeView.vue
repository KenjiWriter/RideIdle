<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <section class="mx-auto max-w-xl px-4 py-16 text-center">
    <h1 class="text-4xl font-bold tracking-tight">{{ t('home.title') }}</h1>
    <p class="mt-4 text-muted">{{ t('home.subtitle') }}</p>
  </section>
</template>
