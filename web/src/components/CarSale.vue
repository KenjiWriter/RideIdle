<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

import type { FleetCar } from '@/api/types'
import { useFormat } from '@/i18n/useFormat'

defineProps<{
  car: FleetCar
  disabled: boolean
}>()

const emit = defineEmits<{ sell: [] }>()

const { t } = useI18n()
const { money } = useFormat()

const asking = ref(false)

function sell(): void {
  asking.value = false
  emit('sell')
}
</script>

<template>
  <!-- A sale cannot be taken back, so the driver is asked, with the price, before the car goes. -->
  <div v-if="asking" class="flex w-full basis-full flex-col gap-1.5 border-t border-line pt-2" role="alert" :data-test="`sell-sure-${car.id}`">
    <p class="text-[13px] font-semibold text-ink">{{ t('fleet.sellSure', { car: car.vehicle.name, price: money(car.sale_cents) }) }}</p>
    <p class="sub">{{ t('fleet.sellParts') }}</p>
    <div class="flex gap-2">
      <button type="button" class="btn btn-danger btn-sm" :disabled="disabled" data-test="sell-confirm" @click="sell">{{ t('fleet.sellConfirm') }}</button>
      <button type="button" class="btn btn-secondary btn-sm" data-test="sell-keep" @click="asking = false">{{ t('fleet.sellKeep') }}</button>
    </div>
  </div>
  <template v-else>
    <button type="button" class="btn btn-secondary btn-sm flex-1" :disabled="disabled || car.sell_blocked !== null" data-test="sell" @click="asking = true">
      {{ t('fleet.sell', { price: money(car.sale_cents) }) }}
    </button>
    <p v-if="car.sell_blocked" class="sub basis-full" data-test="sell-blocked">{{ t(`fleet.sellBlocked.${car.sell_blocked}`) }}</p>
  </template>
</template>
