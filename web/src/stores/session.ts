import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { ApiError, api } from '@/api/client'
import type { Me, MeResponse, RecoveryCodeResponse, SessionResponse } from '@/api/types'
import { clearToken, readToken, storeToken } from '@/auth/token'
import { clearLinkPromptDismissed, readLinkPromptDismissed, storeLinkPromptDismissed } from '@/features/account/linkPrompt'
import { currentLocale } from '@/i18n'
import { normalizeLocale } from '@/i18n/locales'

import { useRunStore } from './run'
import { useSettingsStore } from './settings'
import { useTutorialStore } from './tutorial'

/** Who is playing on this device: the profile of the driver and the device token. */
export const useSessionStore = defineStore('session', () => {
  const me = ref<Me | null>(null)
  const token = ref<string | null>(readToken())
  /** The driver told the window "Save your garage" to wait. It belongs to the driver on this device, not to the device. */
  const linkPromptDismissed = ref(readLinkPromptDismissed())

  /** The account of this device was deleted a moment ago, which the start page says once. */
  const accountDeleted = ref(false)

  const isAuthenticated = computed(() => token.value !== null)

  function start(profile: Me, deviceToken: string): void {
    storeToken(deviceToken)
    token.value = deviceToken
    me.value = profile
    resetLinkPrompt()
  }

  function resetLinkPrompt(): void {
    clearLinkPromptDismissed()
    linkPromptDismissed.value = false
  }

  function dismissLinkPrompt(): void {
    storeLinkPromptDismissed()
    linkPromptDismissed.value = true
  }

  /** Signs in on this device with a token the server gave. The interface switches to the language of the account. */
  async function acceptLogin(profile: Me, deviceToken: string): Promise<void> {
    start(profile, deviceToken)

    const accountLocale = normalizeLocale(profile.locale)

    if (accountLocale && accountLocale !== currentLocale()) {
      await useSettingsStore().applyLocale(accountLocale)
    }
  }

  /** Creates a guest driver. The language of the interface becomes the language of the account. */
  async function createDriver(name: string): Promise<void> {
    const response = await api<SessionResponse>('/v1/players', {
      method: 'POST',
      body: JSON.stringify({ name, locale: currentLocale() }),
    })

    start(response.data, response.token)
  }

  /** Signs in on this device with a recovery code. */
  async function recover(code: string): Promise<void> {
    const response = await api<SessionResponse>('/v1/account/recover', {
      method: 'POST',
      body: JSON.stringify({ code }),
    })

    await acceptLogin(response.data, response.token)
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

  /** The account was deleted on the server: this device forgets it and says so on the start page. */
  function forgetDeletedAccount(): void {
    signOut()
    accountDeleted.value = true
  }

  /** The start page said it. */
  function acknowledgeDeletion(): void {
    accountDeleted.value = false
  }

  function signOut(): void {
    clearToken()
    token.value = null
    me.value = null
    resetLinkPrompt()
    // The next driver on this device must not see this driver's run, or the summary of their absence.
    useRunStore().reset()
    useTutorialStore().reset()
  }

  return {
    me,
    token,
    isAuthenticated,
    linkPromptDismissed,
    accountDeleted,
    createDriver,
    recover,
    acceptLogin,
    fetchMe,
    issueRecoveryCode,
    dismissLinkPrompt,
    forgetDeletedAccount,
    acknowledgeDeletion,
    signOut,
  }
})
