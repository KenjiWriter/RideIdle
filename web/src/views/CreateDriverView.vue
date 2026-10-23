<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import { NAME_MAX, validateName } from '@/features/driver/validateName'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

const name = ref('')
const touched = ref(false)
const submitting = ref(false)
const serverError = ref<string | null>(null)

// Shown only after the first submit, so the form does not scold while the driver is still typing.
const clientError = computed(() => {
  const problem = validateName(name.value)

  return problem ? t(`create.errors.${problem}`) : null
})

const error = computed(() => serverError.value ?? (touched.value ? clientError.value : null))

// A new attempt clears the message the server gave for the previous name.
watch(name, () => {
  serverError.value = null
})

function describe(failure: unknown): string {
  if (failure instanceof ApiError) {
    if (failure.status === 422) {
      return failure.firstError('name') ?? failure.message
    }

    if (failure.status === 429) {
      return t('common.tooManyRequests')
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
    await session.createDriver(name.value.trim())
    // A new driver has no car yet: the second step is choosing a wreck.
    await router.push({ name: 'choose' })
  } catch (failure) {
    serverError.value = describe(failure)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="mx-auto flex max-w-md flex-col gap-6 px-4 py-12">
    <h1 class="text-3xl font-bold tracking-tight">{{ t('create.title') }}</h1>

    <form class="card flex flex-col gap-4" novalidate @submit.prevent="submit">
      <div class="flex flex-col gap-2">
        <label for="driver-name" class="font-semibold">{{ t('create.nameLabel') }}</label>
        <input
          id="driver-name"
          v-model="name"
          class="field"
          type="text"
          name="name"
          autocomplete="off"
          autocapitalize="none"
          spellcheck="false"
          :maxlength="NAME_MAX"
          :aria-invalid="error !== null"
          aria-describedby="driver-name-hint driver-name-error"
          data-test="name"
        />
        <p id="driver-name-hint" class="text-sm text-muted">{{ t('create.nameHint') }}</p>
        <p id="driver-name-error" class="min-h-5 text-sm text-danger" role="alert" data-test="error">{{ error }}</p>
      </div>

      <button type="submit" class="btn btn-primary" :disabled="submitting" data-test="submit">
        {{ submitting ? t('create.submitting') : t('create.submit') }}
      </button>
    </form>

    <RouterLink to="/" class="text-muted underline" data-test="back">{{ t('create.back') }}</RouterLink>
  </main>
</template>
