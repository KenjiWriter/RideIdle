import { defineStore } from 'pinia'
import { ref } from 'vue'

import { ApiError, api } from '@/api/client'
import type {
  EmailConfirmation,
  EmailLinkResponse,
  ProviderFinish,
  ProviderStart,
  ProvidersResponse,
  SocialProvider,
} from '@/api/types'
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

  /** Asks the server which providers are on. If it cannot be asked, the e-mail link is still there to offer. */
  async function loadProviders(): Promise<void> {
    try {
      providers.value = (await api<ProvidersResponse>('/v1/auth/providers')).data
    } catch {
      providers.value = []
    }
  }

  /**
   * Asks for a link that secures this guest account with an e-mail. Nothing changes until the link is opened. The
   * answer is the same whether the address already has a garage or not. Needs the terms accepted and the age
   * confirmed, which the caller has asked the driver for.
   */
  async function requestEmailLink(email: string): Promise<string> {
    const response = await api<EmailLinkResponse>('/v1/account/email', {
      method: 'POST',
      body: JSON.stringify({ email, accept_terms: true, confirm_age: true }),
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
   * sign in. The verifier of the sign-in is kept here until the browser comes back. Linking needs the terms accepted
   * and the age confirmed, which the caller has asked the driver for.
   */
  async function startProvider(provider: SocialProvider, link: boolean): Promise<void> {
    const start = link
      ? await api<ProviderStart>(`/v1/account/identities/${provider}/start`, {
          method: 'POST',
          body: JSON.stringify({ accept_terms: true, confirm_age: true }),
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

  return { providers, loadProviders, requestEmailLink, requestLoginLink, startProvider, finishProvider, confirmEmail }
})
