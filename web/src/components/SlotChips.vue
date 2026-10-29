<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import type { GarageSlot } from '@/api/types'
import SlotIcon from '@/components/SlotIcon.vue'
import { spotKeyOf } from '@/features/car/hotspots'
import { useSlotText } from '@/features/car/useSlotText'
import { useModel } from '@/model'

defineProps<{
  /** The slots that have no place on the picture, so that every part can still be looked at. */
  slots: GarageSlot[]
  /** The slot that is looked at. */
  picked: string | null
}>()

const emit = defineEmits<{ pick: [slot: string] }>()

const { t } = useI18n()
const { slotName } = useSlotText()
const { roleOf } = useModel()
</script>

<template>
  <!-- The slots that have no place on the picture (a slot of another game, every slot of a car that has no picture), as a row under the stage. -->
  <div v-if="slots.length > 0" class="rail !pb-1" role="group" :aria-label="t('garage.parts.other')" data-test="slots">
    <button
      v-for="slot in slots"
      :key="slot.slot"
      type="button"
      class="chip flex-none cursor-pointer"
      :class="{ 'chip-am': picked === slot.slot }"
      :aria-pressed="picked === slot.slot"
      :data-test="`chip-${slot.slot}`"
      @click="emit('pick', slot.slot)"
    >
      <SlotIcon :name="spotKeyOf(slot, roleOf)" class="size-4" />
      {{ slotName(slot.slot) }}
    </button>
  </div>
</template>
