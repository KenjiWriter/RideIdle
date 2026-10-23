<script setup lang="ts">
import { useI18n } from 'vue-i18n'

/** A quiet reminder, for a guest who told the window "Save your garage" to wait. It never blocks the game. */
const emit = defineEmits<{ open: [] }>()

const { t } = useI18n()
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface px-4 py-3" role="note" data-test="link-banner">
    <p class="text-sm text-muted">{{ t('linkBanner.text') }}</p>
    <button type="button" class="text-sm font-bold text-amber underline" data-test="link-banner-open" @click="emit('open')">
      {{ t('linkBanner.action') }}
    </button>
  </div>
</template>
