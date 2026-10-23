<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import { useAccountStore } from '@/stores/account'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const account = useAccountStore()
const session = useSessionStore()

/** `claim`: the link secured the account it was asked for. */
const outcome = ref<'checking' | 'claim' | 'failed'>('checking')
const failure = ref<string | null>(null)

const failureText = computed(() => failure.value ?? t('confirm.invalid'))

function describe(error: unknown): string | null {
  if (error instanceof ApiError) {
    // 422: the link is unknown, used or expired. 409: the address or the provider belongs to another garage, and the
    // server says so in the language of the driver. 429: too many tries.
    if (error.status === 409) {
      return error.message
    }

    if (error.status === 429) {
      return t('common.tooManyRequests')
    }

    return error.status === 422 ? null : t('common.error')
  }

  return t('common.error')
}

onMounted(async () => {
  const secret = typeof route.query.token === 'string' ? route.query.token : ''

  // The link works once. Its secret should not stay in the address bar, the history or what the page sends on.
  await router.replace({ name: 'account-confirm' })

  if (secret === '') {
    outcome.value = 'failed'

    return
  }

  try {
    if ((await account.confirmEmail(secret)) === 'login') {
      await router.replace({ name: 'garage' })

      return
    }

    outcome.value = 'claim'
  } catch (error) {
    failure.value = describe(error)
    outcome.value = 'failed'
  }
})
</script>

<template>
  <main class="mx-auto flex max-w-md flex-col gap-6 px-4 py-12">
    <p v-if="outcome === 'checking'" class="text-muted" role="status" data-test="checking">{{ t('confirm.checking') }}</p>

    <template v-else-if="outcome === 'claim'">
      <h1 class="text-3xl font-bold tracking-tight" data-test="title">{{ t('confirm.claimTitle') }}</h1>
      <p class="text-muted" data-test="body">{{ t('confirm.claimBody') }}</p>
      <RouterLink :to="session.isAuthenticated ? '/garage' : '/'" class="btn btn-primary" data-test="next">
        {{ session.isAuthenticated ? t('confirm.garage') : t('confirm.home') }}
      </RouterLink>
    </template>

    <template v-else>
      <h1 class="text-3xl font-bold tracking-tight" data-test="title">{{ t('confirm.failedTitle') }}</h1>
      <p class="text-danger" role="alert" data-test="body">{{ failureText }}</p>
      <RouterLink :to="session.isAuthenticated ? '/garage' : '/recover'" class="btn btn-primary" data-test="next">
        {{ session.isAuthenticated ? t('confirm.garage') : t('confirm.newLink') }}
      </RouterLink>
      <RouterLink v-if="!session.isAuthenticated" to="/" class="text-muted underline" data-test="home">{{ t('confirm.home') }}</RouterLink>
    </template>
  </main>
</template>
