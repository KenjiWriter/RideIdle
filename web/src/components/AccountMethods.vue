<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import type { SocialProvider } from '@/api/types'
import { useAccountStore } from '@/stores/account'

/**
 * The ways to get into an account: Google, Facebook or Apple when the server has them on, and a link sent by e-mail.
 * To `link` them to the guest account of the driver, the terms must be accepted and the age confirmed first. To
 * `login` on a device with no account nothing is asked, because the account already exists.
 */
const props = defineProps<{ mode: 'link' | 'login' }>()

const { t } = useI18n()
const account = useAccountStore()

const linking = computed(() => props.mode === 'link')

const terms = ref(false)
const age = ref(false)
const email = ref('')
const busy = ref(false)
const opening = ref<SocialProvider | null>(null)
const sent = ref<string | null>(null)
const error = ref<string | null>(null)

onMounted(() => {
  if (!account.providersLoaded) {
    void account.loadProviders()
  }
})

function describe(failure: unknown): string {
  if (failure instanceof ApiError) {
    if (failure.status === 422) {
      return failure.firstError('email') ?? failure.firstError('accept_terms') ?? failure.firstError('confirm_age') ?? failure.message
    }

    // 404 provider_unavailable, 409 the account has this already: the server says so in the language of the driver.
    if (failure.status === 404 || failure.status === 409) {
      return failure.message
    }

    if (failure.status === 429) {
      return failure.message || t('common.tooManyRequests')
    }
  }

  return t('common.error')
}

/** Linking is for a driver who accepted the terms and confirmed their age; the server asks for it too. */
function consentGiven(): boolean {
  if (!linking.value || (terms.value && age.value)) {
    return true
  }

  error.value = t('account.consentRequired')

  return false
}

async function useProvider(provider: SocialProvider): Promise<void> {
  error.value = null

  if (busy.value || !consentGiven()) {
    return
  }

  busy.value = true
  opening.value = provider

  try {
    // The browser leaves for the provider: nothing is left to do here when this succeeds.
    await account.startProvider(provider, linking.value)
  } catch (failure) {
    error.value = describe(failure)
    busy.value = false
    opening.value = null
  }
}

async function sendLink(): Promise<void> {
  error.value = null

  const address = email.value.trim()

  if (busy.value) {
    return
  }

  if (address === '') {
    error.value = t('account.errors.emailRequired')

    return
  }

  if (!address.includes('@')) {
    error.value = t('account.errors.emailInvalid')

    return
  }

  if (!consentGiven()) {
    return
  }

  busy.value = true

  try {
    sent.value = linking.value ? await account.requestEmailLink(address) : await account.requestLoginLink(address)
  } catch (failure) {
    error.value = describe(failure)
  } finally {
    busy.value = false
  }
}

function another(): void {
  sent.value = null
  error.value = null
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <fieldset v-if="linking" class="flex flex-col gap-3">
      <legend class="sr-only">{{ t('account.consentLegend') }}</legend>
      <label class="flex items-start gap-3">
        <input v-model="terms" type="checkbox" class="mt-1 size-5 shrink-0 accent-amber" data-test="terms" />
        <span class="text-sm">{{ t('account.terms') }}</span>
      </label>
      <label class="flex items-start gap-3">
        <input v-model="age" type="checkbox" class="mt-1 size-5 shrink-0 accent-amber" data-test="age" />
        <span class="text-sm">{{ t('account.age') }}</span>
      </label>
    </fieldset>

    <div v-if="account.providers.length > 0" class="flex flex-col gap-3" data-test="providers">
      <button
        v-for="provider in account.providers"
        :key="provider"
        type="button"
        class="btn btn-secondary"
        :disabled="busy"
        :data-test="`provider-${provider}`"
        @click="useProvider(provider)"
      >
        {{
          opening === provider
            ? t('account.opening', { provider: t(`provider.${provider}`) })
            : t('account.continueWith', { provider: t(`provider.${provider}`) })
        }}
      </button>
      <p class="text-center text-sm text-muted">{{ t('account.or') }}</p>
    </div>

    <div v-if="sent" class="flex flex-col gap-3 rounded-lg border border-cyan bg-surface-2 p-4" role="status" data-test="sent">
      <p>{{ sent }}</p>
      <div>
        <button type="button" class="btn btn-secondary" data-test="another" @click="another">{{ t('account.another') }}</button>
      </div>
    </div>

    <form v-else class="flex flex-col gap-3" novalidate @submit.prevent="sendLink">
      <label :for="`account-email-${mode}`" class="font-semibold">{{ t('account.emailLabel') }}</label>
      <input
        :id="`account-email-${mode}`"
        v-model="email"
        class="field"
        type="email"
        name="email"
        inputmode="email"
        autocomplete="email"
        autocapitalize="none"
        spellcheck="false"
        maxlength="191"
        placeholder="name@example.com"
        data-test="email"
      />
      <button type="submit" class="btn btn-primary" :disabled="busy" data-test="send">
        {{ busy && opening === null ? t('account.sending') : t('account.sendLink') }}
      </button>
    </form>

    <p v-if="error" class="text-sm text-danger" role="alert" data-test="error">{{ error }}</p>
  </div>
</template>
