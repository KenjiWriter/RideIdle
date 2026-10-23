<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import type { Run } from '@/api/types'
import { useFlash } from '@/features/run/useFlash'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatDuration } from '@/i18n/format'
import { useRunStore } from '@/stores/run'

const props = defineProps<{ run: Run }>()

const { t } = useI18n()
const store = useRunStore()

const busy = ref(false)
const { message: notice, show: showNotice, clear: clearNotice } = useFlash()
const error = ref<string | null>(null)

/** The way out for a driver who cannot pay to get the car going: the server says when it is theirs to ask for. */
const offered = computed(() => store.tow?.available === true && props.run.stop_reason !== null)
const wait = computed(() => formatDuration(store.tow?.delay_ms ?? 0))

async function tow(): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  clearNotice()
  error.value = null

  try {
    // When the car is towed the offer and the stopped state go away and the garage shows that it is on its way.
    if (!(await store.towCar())) {
      showNotice(t('run.tow.notNeeded'))
    }
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    error.value = failure instanceof ApiError && failure.status === 429 ? t('common.tooManyRequests') : t('common.error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div data-test="tow">
    <div v-if="offered && run.stop_reason" class="mt-4 flex flex-col gap-3 rounded-lg border border-line bg-surface-2 p-3">
      <p class="text-sm" data-test="tow-offer">{{ t(`run.tow.${run.stop_reason}`, { time: wait }) }}</p>
      <div>
        <button type="button" class="btn btn-secondary" :disabled="busy" data-test="tow-button" @click="tow">
          {{ busy ? t('run.tow.busy') : t('run.tow.button') }}
        </button>
      </div>
    </div>

    <div role="status" aria-live="polite">
      <p v-if="notice" class="mt-3 text-sm text-cyan" data-test="tow-notice">{{ notice }}</p>
    </div>
    <div role="alert">
      <p v-if="error" class="mt-3 text-sm text-danger" data-test="tow-error">{{ error }}</p>
    </div>
  </div>
</template>
