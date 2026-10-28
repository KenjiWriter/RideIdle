<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useFormat } from '@/i18n/useFormat'

/**
 * Finishes a timer at once for premium coins: a job of the workshop, a journey. The button says what it costs, and the coins are taken only after the driver says yes.
 * When the driver has too few the button is not offered, and says how many they have.
 */
const props = defineProps<{
  /** What finishing costs, in coins. */
  coins: number
  /** What the driver has. */
  balance: number
  /** What the button says before the price: "Finish now", "Arrive now". */
  label: string
  disabled: boolean
  /** The name of the control in the tests: `{testId}`, `{testId}-sure`, `{testId}-confirm`, `{testId}-keep` and `{testId}-poor`. */
  testId: string
}>()

const emit = defineEmits<{ confirm: [] }>()

const { t } = useI18n()
const { number } = useFormat()

const asking = ref(false)
const affordable = computed(() => props.balance >= props.coins)

function confirm(): void {
  asking.value = false
  emit('confirm')
}
</script>

<template>
  <div v-if="asking" class="flex w-full basis-full flex-col gap-1.5 border-t border-line pt-2" role="alert" :data-test="`${testId}-sure`">
    <p class="text-[13px] font-semibold text-ink">{{ t('speedup.sure', { coins: number(coins), balance: number(balance) }) }}</p>
    <div class="flex gap-2">
      <button type="button" class="btn btn-primary btn-sm" :disabled="disabled" :data-test="`${testId}-confirm`" @click="confirm">
        {{ t('speedup.confirm') }}
      </button>
      <button type="button" class="btn btn-secondary btn-sm" :data-test="`${testId}-keep`" @click="asking = false">
        {{ t('speedup.keep') }}
      </button>
    </div>
  </div>
  <div v-else class="flex flex-col items-start gap-1">
    <button type="button" class="btn btn-primary btn-sm shrink-0" :disabled="disabled || !affordable" :data-test="testId" @click="asking = true">
      {{ t('speedup.button', { label, coins: number(coins) }) }}
    </button>
    <p v-if="!affordable" class="sub" :data-test="`${testId}-poor`">{{ t('speedup.tooFew', { balance: number(balance) }) }}</p>
  </div>
</template>
