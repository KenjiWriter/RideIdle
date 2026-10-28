<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { PoliceChoice, PoliceStop } from '@/api/types'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'

/**
 * The police have stopped the car on a boosted journey. While the driver has not chosen it says the fine and what each choice costs and risks (pay and stand a little,
 * offer a bribe that may not work, have a lawyer for premium coins), and counts down the minute that is left before the fine is paid for them. After it says what
 * came of it.
 */
const props = defineProps<{
  police: PoliceStop
  /** What time it is for the server (ms). */
  now: number
  /** The premium coins of the driver. */
  coins: number
  disabled: boolean
}>()

const emit = defineEmits<{ choose: [choice: PoliceChoice] }>()

const { t } = useI18n()
const { money, number } = useFormat()

const pending = computed(() => props.police.status === 'pending' && props.police.options !== null)
const left = computed(() => formatDuration(Math.max(0, props.police.decide_by_ms - props.now)))
const lawyerTooFew = computed(() => props.police.options?.lawyer != null && props.coins < props.police.options.lawyer.coins)

/** What came of the stop, in words (nothing for a stop that was waived because the car was brought to the city at once). */
const outcome = computed(() => {
  const amount = money(props.police.paid_cents ?? 0, 2)

  switch (props.police.outcome) {
    case 'paid':
      return t('police.paid', { amount, stop: formatDuration(props.police.options?.pay.stop_ms ?? 120_000) })
    case 'bribed':
      return t('police.bribed', { amount })
    case 'bribe_failed':
      return t('police.bribeFailed', { amount, stop: formatDuration(300_000) })
    case 'lawyered':
      return t('police.lawyered')
    case 'defaulted':
      return t('police.defaulted', { amount })
    default:
      return null
  }
})
</script>

<template>
  <div v-if="pending && police.options" class="flex flex-col gap-2 rounded border border-line p-2.5" role="alert" data-test="police">
    <p class="font-display text-base font-bold text-ink" data-test="police-title">{{ t('police.title') }}</p>
    <p class="text-[13px] text-ink" data-test="police-fine">{{ t('police.fine', { amount: money(police.fine_cents, 2) }) }}</p>
    <p class="sub" data-test="police-left">{{ t('police.decideIn', { time: left }) }}</p>

    <div class="flex flex-col gap-1">
      <button type="button" class="btn btn-secondary btn-sm" :disabled="disabled" data-test="police-pay" @click="emit('choose', 'pay')">
        {{ t('police.pay', { amount: money(police.options.pay.cents, 2) }) }}
      </button>
      <p class="sub">{{ t('police.payNote', { stop: formatDuration(police.options.pay.stop_ms) }) }}</p>
    </div>

    <div class="flex flex-col gap-1">
      <button type="button" class="btn btn-secondary btn-sm" :disabled="disabled" data-test="police-bribe" @click="emit('choose', 'bribe')">
        {{ t('police.bribe', { amount: money(police.options.bribe.cents, 2) }) }}
      </button>
      <p class="sub">
        {{
          t('police.bribeNote', {
            risk: number(police.options.bribe.risk_ppm / 10_000),
            failAmount: money(police.options.bribe.fail_cents, 2),
            failStop: formatDuration(police.options.bribe.fail_stop_ms),
          })
        }}
      </p>
    </div>

    <div v-if="police.options.lawyer" class="flex flex-col gap-1">
      <button type="button" class="btn btn-primary btn-sm" :disabled="disabled || lawyerTooFew" data-test="police-lawyer" @click="emit('choose', 'lawyer')">
        {{ t('police.lawyer', { coins: police.options.lawyer.coins }) }}
      </button>
      <p v-if="lawyerTooFew" class="sub" data-test="police-lawyer-poor">{{ t('police.lawyerTooFew', { balance: number(coins) }) }}</p>
      <p v-else class="sub">{{ t('police.lawyerNote') }}</p>
    </div>
  </div>
  <p v-else-if="outcome" class="rounded border border-line p-2.5 text-[13px] text-ink" role="status" data-test="police-outcome">{{ outcome }}</p>
</template>
