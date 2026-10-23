<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import type { SocialProvider } from '@/api/types'
import { MissingVerifierError, useAccountStore } from '@/stores/account'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const account = useAccountStore()
const session = useSessionStore()

const PROVIDERS: SocialProvider[] = ['google', 'facebook', 'apple']

/** `linked`: the provider was added to the account of the driver. */
const outcome = ref<'checking' | 'linked' | 'cancelled' | 'failed'>('checking')
const provider = ref<SocialProvider | null>(null)
const failure = ref<string | null>(null)

const providerName = computed(() => (provider.value ? t(`provider.${provider.value}`) : ''))
const failureText = computed(() => failure.value ?? t('callback.failed', { provider: providerName.value }))

function describe(error: unknown): string {
  if (error instanceof MissingVerifierError) {
    return t('callback.missingVerifier')
  }

  if (error instanceof ApiError) {
    switch (error.status) {
      case 401:
        return t('callback.signedOut')
      case 422:
        return t('callback.expired')
      case 429:
        return t('common.tooManyRequests')
      // 403, 404 and 409: another garage, no garage for this provider, the wrong garage. The server explains in the
      // language of the driver.
      case 403:
      case 404:
      case 409:
        return error.message
    }
  }

  return t('common.error')
}

onMounted(async () => {
  // The API sends the browser here with the outcome in the fragment, which no server sees.
  const fields = new URLSearchParams(route.hash.replace(/^#/, ''))
  const named = fields.get('provider')
  provider.value = PROVIDERS.find((candidate) => candidate === named) ?? null
  const ticket = fields.get('ticket')
  const result = fields.get('result')

  // The ticket works once. It should not stay in the address bar or the history.
  await router.replace({ name: 'auth-callback' })

  if (result === 'cancelled') {
    outcome.value = 'cancelled'

    return
  }

  if (result !== 'ready' || !ticket) {
    outcome.value = 'failed'

    return
  }

  try {
    if ((await account.finishProvider(ticket)) === 'login') {
      await router.replace({ name: 'garage' })

      return
    }

    outcome.value = 'linked'
  } catch (error) {
    failure.value = describe(error)
    outcome.value = 'failed'
  }
})
</script>

<template>
  <main class="mx-auto flex max-w-md flex-col gap-6 px-4 py-12">
    <p v-if="outcome === 'checking'" class="text-muted" role="status" data-test="checking">{{ t('callback.checking') }}</p>

    <template v-else-if="outcome === 'linked'">
      <h1 class="text-3xl font-bold tracking-tight" data-test="title">{{ t('callback.linkedTitle', { provider: providerName }) }}</h1>
      <p class="text-muted" data-test="body">{{ t('callback.linkedBody', { provider: providerName }) }}</p>
      <RouterLink to="/garage" class="btn btn-primary" data-test="next">{{ t('callback.garage') }}</RouterLink>
    </template>

    <template v-else-if="outcome === 'cancelled'">
      <h1 class="text-3xl font-bold tracking-tight" data-test="title">{{ t('callback.cancelledTitle') }}</h1>
      <p class="text-muted" data-test="body">{{ t('callback.cancelledBody', { provider: providerName }) }}</p>
      <RouterLink :to="session.isAuthenticated ? '/garage' : '/recover'" class="btn btn-primary" data-test="next">
        {{ session.isAuthenticated ? t('callback.garage') : t('callback.signIn') }}
      </RouterLink>
    </template>

    <template v-else>
      <h1 class="text-3xl font-bold tracking-tight" data-test="title">{{ t('callback.failedTitle') }}</h1>
      <p class="text-danger" role="alert" data-test="body">{{ failureText }}</p>
      <RouterLink :to="session.isAuthenticated ? '/garage' : '/recover'" class="btn btn-primary" data-test="next">
        {{ session.isAuthenticated ? t('callback.garage') : t('callback.signIn') }}
      </RouterLink>
    </template>
  </main>
</template>
