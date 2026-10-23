<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import AccountMethods from '@/components/AccountMethods.vue'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

const code = ref('')
const touched = ref(false)
const submitting = ref(false)
const serverError = ref<string | null>(null)

const clientError = computed(() => (code.value.trim() === '' ? t('recover.errors.required') : null))
const error = computed(() => serverError.value ?? (touched.value ? clientError.value : null))

watch(code, () => {
  serverError.value = null
})

function describe(failure: unknown): string {
  if (failure instanceof ApiError) {
    // 422: the code is wrong. 429: too many attempts, the server explains how long to wait.
    if (failure.status === 422) {
      return failure.firstError('code') ?? failure.message
    }

    if (failure.status === 429) {
      return failure.message || t('common.tooManyRequests')
    }
  }

  return t('common.error')
}

async function submit(): Promise<void> {
  touched.value = true
  serverError.value = null

  if (clientError.value || submitting.value) {
    return
  }

  submitting.value = true

  try {
    await session.recover(code.value.trim())
    await router.push({ name: 'garage' })
  } catch (failure) {
    serverError.value = describe(failure)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="mx-auto flex max-w-md flex-col gap-6 px-4 py-12">
    <h1 class="text-3xl font-bold tracking-tight">{{ t('recover.title') }}</h1>
    <p class="text-muted">{{ t('recover.intro') }}</p>

    <section class="card flex flex-col gap-4" aria-labelledby="recover-account-title" data-test="account">
      <h2 id="recover-account-title" class="text-xl font-bold">{{ t('recover.accountTitle') }}</h2>
      <p class="text-sm text-muted">{{ t('recover.accountDescription') }}</p>
      <AccountMethods mode="login" />
    </section>

    <form class="card flex flex-col gap-4" aria-labelledby="recover-code-title" novalidate @submit.prevent="submit">
      <h2 id="recover-code-title" class="text-xl font-bold">{{ t('recover.codeTitle') }}</h2>
      <p class="text-sm text-muted">{{ t('recover.description') }}</p>

      <div class="flex flex-col gap-2">
        <label for="recovery-code" class="font-semibold">{{ t('recover.codeLabel') }}</label>
        <input
          id="recovery-code"
          v-model="code"
          class="field font-mono uppercase tracking-widest"
          type="text"
          name="code"
          placeholder="XXXX-XXXX-XXXX"
          autocomplete="off"
          autocapitalize="characters"
          spellcheck="false"
          maxlength="32"
          :aria-invalid="error !== null"
          aria-describedby="recovery-code-error"
          data-test="code"
        />
        <p id="recovery-code-error" class="min-h-5 text-sm text-danger" role="alert" data-test="error">{{ error }}</p>
      </div>

      <button type="submit" class="btn btn-primary" :disabled="submitting" data-test="submit">
        {{ submitting ? t('recover.submitting') : t('recover.submit') }}
      </button>
    </form>

    <RouterLink to="/" class="text-muted underline" data-test="back">{{ t('recover.back') }}</RouterLink>
  </main>
</template>
