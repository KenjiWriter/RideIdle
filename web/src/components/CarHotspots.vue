<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import SlotIcon from '@/components/SlotIcon.vue'
import { slotState, type PlacedSlot } from '@/features/car/hotspots'
import { useSlotText } from '@/features/car/useSlotText'

defineProps<{
  /** The slots that have a place on the picture. */
  placed: PlacedSlot[]
  /** The slot that is looked at. */
  picked: string | null
  /** The engine of the body, which is what stands on the engine slot until a part does. */
  engine: string | null
}>()

const emit = defineEmits<{ pick: [slot: string] }>()

const { t } = useI18n()
const { slotName, partName } = useSlotText()
</script>

<template>
  <!-- The parts of the car, each where it is on the picture: a tap says what is on it. A part that is worn out has a red ring, a slot with nothing on it a dashed one. -->
  <div class="spots" role="group" :aria-label="t('garage.parts.label')" data-test="spots">
    <button
      v-for="{ slot, key, spot } in placed"
      :key="slot.slot"
      type="button"
      class="spot"
      :class="[
        `spot-${slotState(slot)}`,
        slot.part && slot.part.rarity_rank > 1 ? `r${slot.part.rarity_rank}` : '',
        { 'spot-on': picked === slot.slot },
      ]"
      :style="{ left: `${spot.x}%`, top: `${spot.y}%` }"
      :aria-label="t('garage.parts.spot', { slot: slotName(slot.slot), part: partName(slot, engine) })"
      :aria-pressed="picked === slot.slot"
      :data-test="`spot-${slot.slot}`"
      @click="emit('pick', slot.slot)"
    >
      <SlotIcon :name="key" />
      <span v-if="slot.part && slot.part.upgrade > 0" class="spot-up" aria-hidden="true">+{{ slot.part.upgrade }}</span>
      <span v-if="picked === slot.slot" class="spot-tag" aria-hidden="true">{{ slotName(slot.slot) }}</span>
    </button>
  </div>
</template>
