<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type { StarterCar } from '@/api/types'
import MediaPicture from '@/components/MediaPicture.vue'
import { carPicture } from '@/features/art'
import { useFormat } from '@/i18n/useFormat'

defineProps<{
  car: StarterCar
  /** The car to suggest to a driver who has no idea yet: its card is the highlighted one. */
  recommended: boolean
  /** This card's car is being started. */
  starting: boolean
  /** Another card's car is being started, or the same: no second choice meanwhile. */
  disabled: boolean
}>()

defineEmits<{ choose: [slug: string] }>()

const { t } = useI18n()
const { fixed } = useFormat()
</script>

<template>
  <article
    class="card grid grid-cols-[132px_1fr] items-stretch gap-2.5 !p-2"
    :class="{ 'card-hi': recommended }"
    :data-test="`car-${car.slug}`"
    :data-recommended="recommended"
    :aria-labelledby="`car-${car.slug}-name`"
  >
    <MediaPicture
      :src="car.image_url"
      :fallback="carPicture(car.slug)"
      :alt="car.name"
      class="h-full min-h-[138px] w-[132px] rounded-md border border-[#333b49] object-cover object-[50%_58%]"
      data-test="picture"
    >
      <template #placeholder>
        <div class="min-h-[138px] w-[132px] rounded-md border border-dashed border-line-2 bg-surface-2" aria-hidden="true" />
      </template>
    </MediaPicture>

    <div class="flex min-w-0 flex-col gap-1.5">
      <div class="flex flex-wrap items-center gap-2">
        <h2 :id="`car-${car.slug}-name`" class="text-[17px] font-semibold text-ink">{{ car.name }}</h2>
        <span class="tag tag-am" :title="t(`car.drivetrain.${car.drivetrain}`)" data-test="drivetrain">{{ car.drivetrain.toUpperCase() }}</span>
      </div>

      <div class="flex flex-wrap gap-1" data-test="spec">
        <span class="chip !h-[26px]">{{ car.engine.layout }} {{ fixed(car.engine.displacement_l, 1) }}</span>
        <span class="chip !h-[26px]">{{ t('car.power', { value: car.power_kw }) }}</span>
      </div>

      <p v-if="car.description" class="text-[12.5px] leading-snug text-muted" data-test="description">{{ car.description }}</p>

      <button
        type="button"
        class="btn btn-sm mt-auto"
        :class="recommended ? 'btn-primary' : 'btn-secondary'"
        :disabled="disabled"
        :aria-label="starting ? undefined : t('choose.chooseNamed', { name: car.name })"
        data-test="choose"
        @click="$emit('choose', car.slug)"
      >
        {{ starting ? t('choose.starting') : t('choose.choose') }}
      </button>
    </div>
  </article>
</template>
