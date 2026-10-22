<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const session = useSessionStore()

/** The code is known only right after it was issued; the server keeps nothing but its hash. */
const code = ref<string | null>(null)
const loading = ref(false)
const copied = ref(false)
const error = ref<string | null>(null)

let copiedTimer: ReturnType<typeof setTimeout> | undefined

onBeforeUnmount(() => clearTimeout(copiedTimer))

async function issue(): Promise<void> {
  if (loading.value) {
    return
  }

  loading.value = true
  error.value = null

  try {
    code.value = await session.issueRecoveryCode()
  } catch (failure) {
    error.value = failure instanceof ApiError && failure.status === 429 ? t('common.tooManyRequests') : t('common.error')
  } finally {
    loading.value = false
  }
}

async function copy(): Promise<void> {
  if (!code.value) {
    return
  }

  try {
    await navigator.clipboard.writeText(code.value)
    copied.value = true
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => (copied.value = false), 2000)
  } catch {
    // Clipboard access can be blocked. The code stays on screen to be written down.
  }
}

function dismiss(): void {
  code.value = null
  copied.value = false
}
</script>

<template>
  <section class="card flex flex-col gap-4" aria-labelledby="recovery-title">
    <h2 id="recovery-title" class="text-xl font-bold">{{ t('recovery.title') }}</h2>
    <p class="text-muted">{{ t('recovery.description') }}</p>

    <template v-if="code">
      <div class="flex flex-col gap-2 rounded-lg border border-amber bg-surface-2 p-4">
        <span class="text-sm text-muted">{{ t('recovery.yourCode') }}</span>
        <code class="select-all break-all font-mono text-2xl font-bold tracking-widest text-amber" data-test="code">{{ code }}</code>
        <p class="text-sm text-muted">{{ t('recovery.once') }}</p>
      </div>

      <div class="flex flex-wrap gap-3">
        <button type="button" class="btn btn-secondary" data-test="copy" @click="copy">
          {{ copied ? t('recovery.copied') : t('recovery.copy') }}
        </button>
        <button type="button" class="btn btn-primary" data-test="done" @click="dismiss">{{ t('recovery.done') }}</button>
      </div>
    </template>

    <template v-else>
      <p v-if="session.me?.has_recovery_code" class="text-sm text-muted" data-test="hint">{{ t('recovery.regenerateHint') }}</p>

      <div>
        <button
          type="button"
          class="btn"
          :class="session.me?.has_recovery_code ? 'btn-secondary' : 'btn-primary'"
          :disabled="loading"
          data-test="issue"
          @click="issue"
        >
          {{
            loading
              ? t('recovery.generating')
              : session.me?.has_recovery_code
                ? t('recovery.regenerate')
                : t('recovery.generate')
          }}
        </button>
      </div>
    </template>

    <p class="min-h-5 text-sm text-danger" role="alert" data-test="error">{{ error }}</p>
  </section>
</template>
