import { defineStore } from 'pinia'
import { ref } from 'vue'

import { ApiError, api } from '@/api/client'
import type {
  EmailConfirmation,
  EmailLinkResponse,
  LegalInfo,
  LegalResponse,
  ProviderFinish,
  ProviderStart,
  ProvidersResponse,
  SocialProvider,
} from '@/api/types'
import { dataFileName, saveAsFile } from '@/features/account/download'
import { clearPending, readPending, storePending } from '@/features/account/oauth'
import { goTo } from '@/features/account/redirect'

import { useSessionStore } from './session'

/** The sign-in cannot be finished: this browser did not start it, or it was started too long ago. */
export class MissingVerifierError extends Error {
  constructor() {
    super('This browser holds no verifier of a sign-in with a provider.')
  }
}

/** Securing an account and signing in with an e-mail link or with Google, Facebook or Apple. */
export const useAccountStore = defineStore('account', () => {
  /** The sign-ins of another service the server has keys for. Until they are loaded there are none to offer. */
  const providers = ref<SocialProvider[]>([])
  const providersLoaded = ref(false)

  /** What the legal texts need from the server. Asked once; null until then. */
  const legal = ref<LegalInfo | null>(null)

  /** Asks the server which providers are on. If it cannot be asked, the e-mail link is still there to offer. */
  async function loadProviders(): Promise<void> {
    try {
      providers.value = (await api<ProvidersResponse>('/v1/auth/providers')).data
    } catch {
      providers.value = []
    } finally {
      providersLoaded.value = true
    }
  }

  /** Asks the server for the age, the version and the operator of the legal texts, unless they are known. A failure is passed on. */
  async function loadLegal(): Promise<void> {
    if (legal.value === null) {
      legal.value = (await api<LegalResponse>('/v1/meta/legal')).data
    }
  }

  /**
   * Takes a copy of the data of the account and hands it to the browser as a file. The server says what is in it; it
   * holds no secret. A failure is passed on.
   */
  async function downloadData(): Promise<void> {
    const session = useSessionStore()
    const data = await api<unknown>('/v1/account/export')

    saveAsFile(dataFileName(session.me?.name ?? '', new Date()), `${JSON.stringify(data, null, 2)}\n`)
  }

  /**
   * Deletes the account on the server, for good, and this device forgets it. The driver writes their name to say they
   * mean it, and the server checks it: a name that is not theirs is a 422 and nothing is deleted.
   */
  async function deleteAccount(confirmName: string): Promise<void> {
    await api<{ deleted: boolean }>('/v1/account', { method: 'DELETE', body: JSON.stringify({ confirm_name: confirmName }) })

    useSessionStore().forgetDeletedAccount()
  }

  /**
   * Asks for a link that secures this guest account with an e-mail. Nothing changes until the link is opened. The
   * answer is the same whether the address already has a garage or not. A guest needs the terms accepted and the age
   * confirmed, which the caller has asked the driver for (`withConsent`); an account that did that already is not asked.
   */
  async function requestEmailLink(email: string, withConsent = true): Promise<string> {
    const response = await api<EmailLinkResponse>('/v1/account/email', {
      method: 'POST',
      body: JSON.stringify(withConsent ? { email, accept_terms: true, confirm_age: true } : { email }),
    })

    return response.message
  }

  /** Asks for a link that signs in on this device. The answer is the same whether the address has a garage or not. */
  async function requestLoginLink(email: string): Promise<string> {
    const response = await api<EmailLinkResponse>('/v1/account/login-link', {
      method: 'POST',
      body: JSON.stringify({ email }),
    })

    return response.message
  }

  /**
   * Sends the browser to a provider, to link it to the account of the driver or, with no account on this device, to
   * sign in. The verifier of the sign-in is kept here until the browser comes back. A guest linking needs the terms
   * accepted and the age confirmed, which the caller has asked the driver for (`withConsent`); an account that did that
   * already is not asked.
   */
  async function startProvider(provider: SocialProvider, link: boolean, withConsent = true): Promise<void> {
    const start = link
      ? await api<ProviderStart>(`/v1/account/identities/${provider}/start`, {
          method: 'POST',
          body: JSON.stringify(withConsent ? { accept_terms: true, confirm_age: true } : {}),
        })
      : await api<ProviderStart>(`/v1/auth/${provider}/start`, { method: 'POST' })

    storePending(provider, start.verifier)
    goTo(start.url)
  }

  /**
   * Finishes the sign-in the browser comes back with (the ticket from the address): shows the server the verifier kept
   * since the start. A link adds the provider to the account of the driver; a sign-in starts a session here.
   * The verifier is dropped once the server answered, whatever the answer: a sign-in is finished once. If the server
   * could not be reached it is kept, so the same address can be tried again.
   */
  async function finishProvider(ticket: string): Promise<'link' | 'login'> {
    const session = useSessionStore()
    const pending = readPending()

    if (pending === null) {
      throw new MissingVerifierError()
    }

    try {
      const response = await api<ProviderFinish>('/v1/auth/complete', {
        method: 'POST',
        body: JSON.stringify({ ticket, verifier: pending.verifier }),
      })

      clearPending()

      if (response.purpose === 'login' && response.token) {
        await session.acceptLogin(response.data, response.token)
      } else {
        session.me = response.data
      }

      return response.purpose
    } catch (failure) {
      if (failure instanceof ApiError) {
        clearPending()
      }

      throw failure
    }
  }

  /**
   * Uses the link of an e-mail. A link that secures an account does not sign anybody in, because it may be opened in
   * another browser than the one the guest plays in: this device just asks for its profile again. A link that signs
   * in starts a session here.
   */
  async function confirmEmail(secret: string): Promise<'claim' | 'login'> {
    const session = useSessionStore()
    const response = await api<EmailConfirmation>('/v1/account/email/confirm', {
      method: 'POST',
      body: JSON.stringify({ token: secret }),
    })

    if (response.purpose === 'login' && response.token) {
      await session.acceptLogin(response.data, response.token)
    } else if (session.isAuthenticated) {
      await session.fetchMe().catch(() => undefined)
    }

    return response.purpose
  }

  return { providers, providersLoaded, loadProviders, legal, loadLegal, downloadData, deleteAccount, requestEmailLink, requestLoginLink, startProvider, finishProvider, confirmEmail }
})
