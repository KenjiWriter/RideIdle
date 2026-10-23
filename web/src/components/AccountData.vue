<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { useAccountStore } from '@/stores/account'
import { useSessionStore } from '@/stores/session'

/**
 * What a driver can do with the data of their own account: take a copy of it, and have the account deleted. Deleting
 * is for good, so it is a step of its own: the driver reads what goes, and writes the name of their driver to say they
 * mean it. The server checks the name too.
 */
const { t } = useI18n()
const session = useSessionStore()
const account = useAccountStore()

const downloading = ref(false)
const downloaded = ref(false)
const downloadError = ref<string | null>(null)

const confirming = ref(false)
const typed = ref('')
const deleting = ref(false)
const deleteError = ref<string | null>(null)
const nameField = ref<HTMLInputElement | null>(null)

const name = computed(() => session.me?.name ?? '')
/** The name written is the name of the driver, whatever the case and the spaces around it. */
const matches = computed(() => name.value !== '' && typed.value.trim().toLowerCase() === name.value.toLowerCase())

function describe(failure: unknown): string {
  if (failure instanceof ApiError) {
    // 422: the name is not the one of the driver. 429: too many tries.
    if (failure.status === 422) {
      return failure.firstError('confirm_name') ?? failure.message
    }

    if (failure.status === 429) {
      return failure.message || t('common.tooManyRequests')
    }
  }

  return t('common.error')
}

async function download(): Promise<void> {
  if (downloading.value) {
    return
  }

  downloading.value = true
  downloaded.value = false
  downloadError.value = null

  try {
    await account.downloadData()
    downloaded.value = true
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      downloadError.value = describe(failure)
    }
  } finally {
    downloading.value = false
  }
}

async function openConfirmation(): Promise<void> {
  confirming.value = true
  await nextTick()
  nameField.value?.focus()
}

function cancel(): void {
  confirming.value = false
  typed.value = ''
  deleteError.value = null
}

async function confirmDeletion(): Promise<void> {
  if (!matches.value || deleting.value) {
    return
  }

  deleting.value = true
  deleteError.value = null

  try {
    // When the server has deleted the account this device forgets it, and the garage sends the driver to the start.
    await account.deleteAccount(typed.value.trim())
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      deleteError.value = describe(failure)
    }

    deleting.value = false
  }
}
</script>

<template>
  <section v-if="session.me" class="card flex flex-col gap-4" aria-labelledby="data-title" data-test="data">
    <h2 id="data-title" class="text-xl font-bold">{{ t('data.title') }}</h2>
    <p class="text-muted">{{ t('data.description') }}</p>

    <div class="flex flex-col items-start gap-2">
      <button type="button" class="btn btn-secondary" :disabled="downloading" data-test="download" @click="download">
        {{ downloading ? t('data.preparing') : t('data.download') }}
      </button>
      <p v-if="downloaded" class="text-sm text-muted" role="status" data-test="downloaded">{{ t('data.downloaded') }}</p>
      <p v-if="downloadError" class="text-sm text-danger" role="alert" data-test="download-error">{{ downloadError }}</p>
    </div>

    <div class="flex flex-col gap-3 border-t border-line pt-4">
      <div v-if="!confirming">
        <button type="button" class="btn btn-danger" data-test="delete" @click="openConfirmation">{{ t('data.delete') }}</button>
      </div>

      <form v-else class="flex flex-col gap-3" aria-labelledby="delete-title" novalidate data-test="delete-form" @submit.prevent="confirmDeletion">
        <h3 id="delete-title" class="text-lg font-bold text-danger">{{ t('data.deleteTitle') }}</h3>
        <p data-test="warning">{{ t('data.deleteWarning') }}</p>

        <label for="confirm-name" class="font-semibold">{{ t('data.confirmLabel', { name }) }}</label>
        <input
          id="confirm-name"
          ref="nameField"
          v-model="typed"
          class="field"
          type="text"
          name="confirm_name"
          autocomplete="off"
          autocapitalize="none"
          spellcheck="false"
          maxlength="64"
          data-test="confirm-name"
        />

        <p v-if="deleteError" class="text-sm text-danger" role="alert" data-test="delete-error">{{ deleteError }}</p>

        <div class="flex flex-wrap gap-3">
          <button type="submit" class="btn btn-danger" :disabled="!matches || deleting" data-test="confirm-delete">
            {{ deleting ? t('data.deleting') : t('data.confirm') }}
          </button>
          <button type="button" class="btn btn-secondary" :disabled="deleting" data-test="cancel" @click="cancel">{{ t('data.cancel') }}</button>
        </div>
      </form>
    </div>
  </section>
</template>
