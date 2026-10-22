import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '@/api/client'
import { readToken } from '@/auth/token'
import { currentLocale, setLocale } from '@/i18n'
import type { AppLocale } from '@/i18n/locales'

export const useSettingsStore = defineStore('settings', () => {
  const locale = ref<AppLocale>(currentLocale())

  /** Changes the language of the interface only, for example to the one saved on a restored account. */
  async function applyLocale(next: AppLocale): Promise<void> {
    await setLocale(next)
    locale.value = next
  }

  /**
   * The driver picked a language: apply it and, when signed in, save it on the
   * account so every device and every e-mail from the server uses it.
   */
  async function changeLocale(next: AppLocale): Promise<void> {
    await applyLocale(next)

    if (!readToken()) {
      return
    }

    try {
      await api('/v1/me/locale', { method: 'PATCH', body: JSON.stringify({ locale: next }) })
    } catch {
      // The interface already changed. The account is updated with the next change.
    }
  }

  return { locale, applyLocale, changeLocale }
})
