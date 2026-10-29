<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import SlotIcon from '@/components/SlotIcon.vue'
import { slotState, type PlacedSlot } from '@/features/car/hotspots'
import { useSlotText } from '@/features/car/useSlotText'

const props = defineProps<{
  /** The slots that have a place on the picture. */
  placed: PlacedSlot[]
  /** The slot that is looked at. */
  picked: string | null
  /** The engine of the body, which is what stands on the engine slot until a part does. */
  engine: string | null
  /** The slots that the workshop has a job on, by slot: how far the job has got in percent while the mechanic is at it, null while it waits in the queue. */
  marks?: Record<string, number | null>
}>()

const emit = defineEmits<{ pick: [slot: string] }>()

const { t } = useI18n()
const { slotName, partName } = useSlotText()

/** What the workshop is doing on a slot, if anything: working (the progress is drawn round the spot), or waiting its turn. */
function jobOf(slot: string): 'working' | 'queued' | null {
  if (!props.marks || !(slot in props.marks)) {
    return null
  }

  return props.marks[slot] === null ? 'queued' : 'working'
}

function label(slot: PlacedSlot['slot']): string {
  const job = jobOf(slot.slot)
  const text = t('garage.parts.spot', { slot: slotName(slot.slot), part: partName(slot, props.engine) })

  return job === null ? text : `${text} (${t(`garage.parts.${job}`)})`
}
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
      :aria-label="label(slot)"
      :aria-pressed="picked === slot.slot"
      :data-test="`spot-${slot.slot}`"
      @click="emit('pick', slot.slot)"
    >
      <SlotIcon :name="key" />
      <span v-if="jobOf(slot.slot) === 'working'" class="spot-ring" :style="{ '--p': marks?.[slot.slot] ?? 0 }" aria-hidden="true" data-test="spot-working" />
      <span v-else-if="jobOf(slot.slot) === 'queued'" class="spot-queued" aria-hidden="true" data-test="spot-queued">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v7l4 2" /></svg>
      </span>
      <span v-if="slot.part && slot.part.upgrade > 0" class="spot-up" aria-hidden="true">+{{ slot.part.upgrade }}</span>
      <span v-if="picked === slot.slot" class="spot-tag" aria-hidden="true">{{ slotName(slot.slot) }}</span>
    </button>
  </div>
</template>
