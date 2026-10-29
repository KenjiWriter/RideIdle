<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { ApiError } from '@/api/client'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { formatTimeLeft } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'
import { usePremiumStore } from '@/stores/premium'

/**
 * The auto-refill on the card of the fuel: the driver's wish to have the tank filled for them when the fuel would run out, the stock of refills they have (or the
 * pass that makes them free), and the way to get more. It is not there when the game does not sell the auto-refill (and the server has not been asked yet).
 */
const { t } = useI18n()
const { number } = useFormat()
const premium = usePremiumStore()

const busy = ref(false)
const error = ref<string | null>(null)

const refill = computed(() => premium.autorefill)
const passOn = computed(() => premium.infinityActive)
const passLeft = computed(() => formatTimeLeft(premium.remainingMs(premium.infinity?.until_ms)))

/** What the card says of the state of it: the wish that is off, the pass that pays, the stock that is left, or that there is nothing left. */
const state = computed(() => {
  if (refill.value === null) {
    return ''
  }

  if (!refill.value.preference) {
    return t('autoRefill.off')
  }

  if (passOn.value) {
    return t('autoRefill.pass', { left: passLeft.value })
  }

  return refill.value.stock > 0 ? t('autoRefill.stock', { n: number(refill.value.stock) }, refill.value.stock) : t('autoRefill.empty')
})

async function change(event: Event): Promise<void> {
  const enabled = (event.target as HTMLInputElement).checked

  if (busy.value) {
    return
  }

  busy.value = true
  error.value = null

  try {
    await premium.setAutoRefill(enabled)
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    error.value = failure instanceof ApiError && failure.status === 422 ? failure.message : t('common.error')
    // The box shows what the server says, also after a refusal.
    ;(event.target as HTMLInputElement).checked = !enabled
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="refill !== null && refill.enabled" class="mt-4 flex flex-col gap-2 border-t border-line pt-3" data-test="autorefill">
    <label class="flex items-center gap-3 text-ink">
      <input type="checkbox" role="switch" class="size-5 shrink-0 accent-amber" :checked="refill.preference" :disabled="busy" data-test="autorefill-switch" @change="change" />
      <span class="font-semibold">{{ t('autoRefill.label') }}</span>
    </label>
    <p class="text-sm text-muted" data-test="autorefill-state">{{ state }}</p>
    <RouterLink :to="{ name: 'premium' }" class="btn btn-secondary btn-sm self-start" data-test="autorefill-buy">{{ t('autoRefill.buy') }}</RouterLink>
    <div role="alert">
      <p v-if="error" class="text-sm text-danger" data-test="autorefill-error">{{ error }}</p>
    </div>
  </div>
</template>
