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
const { money, number } = useFormat()
const store = useRunStore()

const busy = ref(false)
const { message: done, show: showDone, clear: clearDone } = useFlash()
const error = ref<string | null>(null)

const price = computed(() => props.run.prices.fuel_cents_per_liter)
const cash = computed(() => store.wallet?.cash_cents ?? 0)
const missingMl = computed(() => Math.max(0, (props.run.tank_ml ?? 0) - props.run.fuel_ml))

// The server rounds the price of a fill-up up to a whole cent; so does this.
const fullCost = computed(() => Math.ceil((missingMl.value * price.value) / 1000))
const canFill = computed(() => fullCost.value <= cash.value)

// A driver who cannot pay for a full tank can still buy the whole liters they can afford.
const affordableLiters = computed(() => Math.min(Math.floor(missingMl.value / 1000), Math.floor(cash.value / price.value)))

function liters(value: number): string {
  return t('car.liters', { value: number(value, 1) })
}

async function buy(amount: number | null): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  clearDone()
  error.value = null

  try {
    const result = await store.refuel(amount)

    showDone(
      result.repeated
        ? t('run.refuel.repeated')
        : result.added_ml > 0
          ? t('run.refuel.done', { liters: liters(result.added_ml / 1000), cost: money(result.cost_cents, 2) })
          : t('run.refuel.nothing'),
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
  <div data-test="refuel">
    <template v-if="missingMl > 0">
      <div class="mt-3 flex flex-wrap gap-3" data-tutorial="refuel">
        <button type="button" class="btn btn-primary" :disabled="busy || !canFill" data-test="refuel-full" @click="buy(null)">
          {{ busy ? t('run.refuel.busy') : t('run.refuel.full', { cost: money(fullCost, 2) }) }}
        </button>
        <button
          v-if="!canFill && affordableLiters >= 1"
          type="button"
          class="btn btn-secondary"
          :disabled="busy"
          data-test="refuel-partial"
          @click="buy(affordableLiters)"
        >
          {{ t('run.refuel.partial', { liters: liters(affordableLiters), cost: money(affordableLiters * price, 2) }) }}
        </button>
      </div>
      <p v-if="!canFill && affordableLiters < 1" class="mt-3 text-sm text-muted" data-test="no-cash">{{ t('run.refuel.noCash') }}</p>
    </template>
    <p v-else class="mt-3 text-sm text-muted" data-test="tank-full">{{ t('run.refuel.tankFull') }}</p>

    <p class="mt-3 text-xs text-muted" data-test="price">{{ t('run.refuel.price', { price: money(price, 2) }) }}</p>
    <!-- The regions stay in the page when empty, so what is put into them is announced. -->
    <div role="status" aria-live="polite">
      <p v-if="done" class="mt-3 text-sm text-cyan" data-test="refuel-done">{{ done }}</p>
    </div>
    <div role="alert">
      <p v-if="error" class="mt-3 text-sm text-danger" data-test="refuel-error">{{ error }}</p>
    </div>
  </div>
</template>
