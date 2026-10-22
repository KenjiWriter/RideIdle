import { defineStore } from 'pinia'
import { ref } from 'vue'

import { currentLocale, setLocale } from '@/i18n'
import type { AppLocale } from '@/i18n/locales'

export const useSettingsStore = defineStore('settings', () => {
  const locale = ref<AppLocale>(currentLocale())

  async function changeLocale(next: AppLocale): Promise<void> {
    await setLocale(next)
    locale.value = next
  }

  return { locale, changeLocale }
})
