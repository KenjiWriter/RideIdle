<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type { StarterCar } from '@/api/types'
import { formatLapTime } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'

defineProps<{
  car: StarterCar
  /** The car to suggest to a driver who has no idea yet. */
  recommended: boolean
  /** This card's car is being started. */
  starting: boolean
  /** Another card's car is being started, or the same: no second choice meanwhile. */
  disabled: boolean
}>()

defineEmits<{ choose: [slug: string] }>()

const { t } = useI18n()
const { money, number } = useFormat()
</script>

<template>
  <article class="card flex flex-col gap-4" :data-test="`car-${car.slug}`" :aria-labelledby="`car-${car.slug}-name`">
    <header class="flex flex-col gap-2">
      <p
        v-if="recommended"
        class="w-fit rounded-full bg-amber px-3 py-1 text-xs font-bold text-amber-ink"
        data-test="recommended"
      >
        {{ t('choose.recommended') }}
      </p>
      <h2 :id="`car-${car.slug}-name`" class="text-2xl font-bold">{{ car.name }}</h2>
      <p class="text-sm text-muted" data-test="spec">
        {{ t(`car.drivetrain.${car.drivetrain}`) }} · {{ car.engine.layout }} {{ number(car.engine.displacement_l, 1) }} ·
        {{ t('car.power', { value: car.power_kw }) }}
      </p>
    </header>

    <p v-if="car.description" class="text-muted" data-test="description">{{ car.description }}</p>

    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
      <div>
        <dt class="text-muted">{{ t('choose.stat.lap') }}</dt>
        <dd class="font-semibold" data-test="lap">{{ formatLapTime(car.lap.ms) }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ t('choose.stat.reward') }}</dt>
        <dd class="font-semibold" data-test="reward">
          {{ money(car.lap.cash_cents, 2) }} · {{ t('car.xp', { value: car.lap.xp }) }}
        </dd>
      </div>
      <div>
        <dt class="text-muted">{{ t('choose.stat.fuel') }}</dt>
        <dd class="font-semibold" data-test="fuel">{{ t('car.liters', { value: number(car.lap.fuel_ml / 1000, 1) }) }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ t('choose.stat.topSpeed') }}</dt>
        <dd class="font-semibold">{{ t('car.speed', { value: car.top_speed_kmh }) }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ t('choose.stat.mass') }}</dt>
        <dd class="font-semibold">{{ t('car.weight', { value: number(car.mass_kg) }) }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ t('choose.stat.tank') }}</dt>
        <dd class="font-semibold">{{ t('car.liters', { value: number(car.tank_l) }) }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ t('choose.stat.cargo') }}</dt>
        <dd class="font-semibold">{{ t('car.weight', { value: number(car.cargo_kg) }) }}</dd>
      </div>
    </dl>

    <button
      type="button"
      class="btn mt-auto"
      :class="recommended ? 'btn-primary' : 'btn-secondary'"
      :disabled="disabled"
      data-test="choose"
      @click="$emit('choose', car.slug)"
    >
      {{ starting ? t('choose.starting') : t('choose.choose', { name: car.name }) }}
    </button>
  </article>
</template>
