import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { ApiError, api } from '@/api/client'
import type { Me, MeResponse, RecoveryCodeResponse, SessionResponse } from '@/api/types'
import { clearToken, readToken, storeToken } from '@/auth/token'
import { currentLocale } from '@/i18n'
import { normalizeLocale } from '@/i18n/locales'

import { useSettingsStore } from './settings'

/** Who is playing on this device: the profile of the driver and the device token. */
export const useSessionStore = defineStore('session', () => {
  const me = ref<Me | null>(null)
  const token = ref<string | null>(readToken())

  const isAuthenticated = computed(() => token.value !== null)

  function start(profile: Me, deviceToken: string): void {
    storeToken(deviceToken)
    token.value = deviceToken
    me.value = profile
  }

  /** Creates a guest driver. The language of the interface becomes the language of the account. */
  async function createDriver(name: string): Promise<void> {
    const response = await api<SessionResponse>('/v1/players', {
      method: 'POST',
      body: JSON.stringify({ name, locale: currentLocale() }),
    })

    start(response.data, response.token)
  }

  /** Signs in on this device with a recovery code. The interface switches to the language of the account. */
  async function recover(code: string): Promise<void> {
    const response = await api<SessionResponse>('/v1/account/recover', {
      method: 'POST',
      body: JSON.stringify({ code }),
    })

    start(response.data, response.token)

    const accountLocale = normalizeLocale(response.data.locale)

    if (accountLocale && accountLocale !== currentLocale()) {
      await useSettingsStore().applyLocale(accountLocale)
    }
  }

  /** Loads the profile. A token the server no longer knows ends the session. */
  async function fetchMe(): Promise<void> {
    try {
      me.value = (await api<MeResponse>('/v1/me')).data
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        signOut()

        return
      }

      throw error
    }
  }

  /** Creates a new recovery code (the old one stops working) and returns it. */
  async function issueRecoveryCode(): Promise<string> {
    const response = await api<RecoveryCodeResponse>('/v1/account/recovery-code', { method: 'POST' })

    me.value = response.data

    return response.code
  }

  function signOut(): void {
    clearToken()
    token.value = null
    me.value = null
  }

  return { me, token, isAuthenticated, createDriver, recover, fetchMe, issueRecoveryCode, signOut }
})
