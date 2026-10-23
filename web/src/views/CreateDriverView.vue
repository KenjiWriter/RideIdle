<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import LegalLinks from '@/components/LegalLinks.vue'
import StepShell from '@/components/StepShell.vue'
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

/** The name has the right shape: it is not yet known whether somebody else has it. */
const wellFormed = computed(() => name.value !== '' && clientError.value === null && serverError.value === null)

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
    // A new driver is told the story first, and then chooses a wreck.
    await router.push({ name: 'story' })
  } catch (failure) {
    serverError.value = describe(failure)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <StepShell :step="1">
    <form class="flex flex-1 flex-col gap-4" novalidate @submit.prevent="submit">
      <div class="mt-7">
        <h1 class="ttl !text-[40px] !leading-[0.95]">{{ t('create.title') }}</h1>
        <p class="mt-2.5 text-[15px] text-muted">{{ t('create.intro') }}</p>
      </div>

      <div class="flex flex-col gap-1.5">
        <label for="driver-name" class="fld-label">{{ t('create.nameLabel') }}</label>
        <input
          id="driver-name"
          v-model="name"
          class="field !min-h-14 !text-xl"
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
      </div>

      <div class="flex flex-wrap items-center gap-2.5">
        <!-- The server settles whether the name is free, when the driver goes on: this one says only that it is well formed. -->
        <span v-if="wellFormed" class="tag tag-ok" data-test="well-formed">{{ t('create.valid') }}</span>
        <span id="driver-name-hint" class="sub">{{ t('create.nameHint') }}</span>
      </div>
      <p id="driver-name-error" class="min-h-5 text-sm text-danger" role="alert" data-test="error">{{ error }}</p>

      <div class="card flex flex-col gap-2 !px-3.5 !py-3">
        <p class="sub !leading-[1.45]">{{ t('create.noAccount') }}</p>
        <!-- A guest asks for no account and gives no personal data, so there is no box to tick: the way in says what it means. -->
        <p class="sub !leading-[1.45]" data-test="consent"><LegalLinks keypath="create.consent" /></p>
      </div>

      <div class="flex-1" />

      <button type="submit" class="btn btn-primary !min-h-14 !text-2xl" :disabled="submitting" data-test="submit">
        {{ submitting ? t('create.submitting') : t('create.submit') }}
      </button>
      <RouterLink to="/" class="flex min-h-11 items-center justify-center text-sm text-faint no-underline hover:text-ink" data-test="back">
        {{ t('create.back') }}
      </RouterLink>
    </form>
  </StepShell>
</template>
