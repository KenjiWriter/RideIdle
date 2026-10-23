<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import type { Run } from '@/api/types'
import { useFlash } from '@/features/run/useFlash'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { useFormat } from '@/i18n/useFormat'
import { useRunStore } from '@/stores/run'

const props = defineProps<{ run: Run }>()

const { t } = useI18n()
const { money } = useFormat()
const store = useRunStore()

const busy = ref(false)
const { message: done, show: showDone, clear: clearDone } = useFlash()
const error = ref<string | null>(null)

const broken = computed(() => props.run.status === 'stopped' && props.run.stop_reason === 'breakdown')
const cost = computed(() => props.run.prices.repair_cents)
const canPay = computed(() => (store.wallet?.cash_cents ?? 0) >= cost.value)

async function repair(): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  clearDone()
  error.value = null

  try {
    const result = await store.repair()

    showDone(
      result.repeated
        ? t('run.repair.repeated')
        : !result.repaired
          ? t('run.repair.notNeeded')
          : t(store.run?.status === 'running' ? 'run.repair.doneRunning' : 'run.repair.done', { cost: money(result.cost_cents, 2) }),
    )
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    // The server says "not enough cash" in the language of the driver.
    error.value =
      failure instanceof ApiError && failure.code === 'insufficient_funds'
        ? failure.message
        : failure instanceof ApiError && failure.status === 429
          ? t('common.tooManyRequests')
          : t('common.error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-3" data-test="repair">
    <div v-if="broken" class="flex flex-col gap-2">
      <div>
        <button type="button" class="btn btn-primary" :disabled="busy || !canPay" data-test="repair-button" @click="repair">
          {{ busy ? t('run.repair.busy') : t('run.repair.button', { cost: money(cost, 2) }) }}
        </button>
      </div>
      <p v-if="!canPay" class="text-sm text-muted" data-test="repair-no-cash">{{ t('run.repair.noCash') }}</p>
    </div>

    <!-- The result outlives the button: once repaired, the button is gone but the driver still reads what happened. -->
    <div role="status" aria-live="polite">
      <p v-if="done" class="text-sm text-cyan" data-test="repair-done">{{ done }}</p>
    </div>
    <div role="alert">
      <p v-if="error" class="text-sm text-danger" data-test="repair-error">{{ error }}</p>
    </div>
  </div>
</template>
