<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import { LOCALE_NAMES, SUPPORTED_LOCALES, normalizeLocale } from '@/i18n/locales'
import { useSettingsStore } from '@/stores/settings'

const { t } = useI18n()
const settings = useSettingsStore()

function onChange(event: Event): void {
  const next = normalizeLocale((event.target as HTMLSelectElement).value)

  if (next) {
    void settings.changeLocale(next)
  }
}
</script>

<template>
  <label class="flex items-center gap-2 text-sm text-muted">
    <span>{{ t('language.label') }}</span>
    <select
      class="min-h-9 rounded-md border border-line bg-surface px-2 text-ink"
      :value="settings.locale"
      @change="onChange"
    >
      <option v-for="code in SUPPORTED_LOCALES" :key="code" :value="code">
        {{ LOCALE_NAMES[code] }}
      </option>
    </select>
  </label>
</template>
