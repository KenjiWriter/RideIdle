<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import type { GarageSlot } from '@/api/types'
import PartAffixes from '@/components/PartAffixes.vue'
import PartCondition from '@/components/PartCondition.vue'
import PartStats from '@/components/PartStats.vue'
import RarityMark from '@/components/RarityMark.vue'
import SlotIcon from '@/components/SlotIcon.vue'
import UpgradeMark from '@/components/UpgradeMark.vue'
import { spotKeyOf } from '@/features/car/hotspots'
import { useSlotText } from '@/features/car/useSlotText'
import { useModel } from '@/model'

const props = withDefaults(
  defineProps<{
  /** The slot that was tapped, or null when none was. */
  slot: GarageSlot | null
  /** The engine of the body, which is what stands on the engine slot until a part does. */
  engine: string | null
  /** How many of the slots have a part on them, and how many there are. */
  mounted: number
  total: number
  /** The way to the workshop under the card: a screen that has the actions of the workshop of its own leaves it out. */
  link?: boolean
}>(),
  { link: true },
)

const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const { slotName, partName } = useSlotText()
const { roleOf } = useModel()

const part = computed(() => props.slot?.part ?? null)
const icon = computed(() => (props.slot ? spotKeyOf(props.slot, roleOf) : 'other'))
/** A part of the body is the engine that came with it: the slot says so, and there is a better one to be put on. */
const factory = computed(() => !!props.slot && !props.slot.part)
</script>

<template>
  <!-- Nothing tapped yet: what the car has on it, as a whole, and how to look closer. -->
  <section v-if="!slot" class="card flex items-center gap-3 !p-3" data-test="slot-hint">
    <span class="slot-badge" aria-hidden="true"><SlotIcon name="other" /></span>
    <div class="min-w-0">
      <p class="text-sm text-ink">{{ t('garage.parts.hint') }}</p>
      <p class="sub" data-test="slot-summary">{{ t('garage.parts.summary', { mounted, total }) }}</p>
    </div>
  </section>

  <section v-else class="card flex flex-col gap-2.5 !p-3" :class="part && part.rarity_rank > 1 ? `card-r${part.rarity_rank}` : ''" :aria-label="slotName(slot.slot)" :data-slot="slot.slot" data-test="slot-detail">
    <div class="flex items-start gap-3">
      <span class="slot-badge" :class="part && part.rarity_rank > 1 ? `r${part.rarity_rank}` : ''" aria-hidden="true"><SlotIcon :name="icon" /></span>
      <div class="min-w-0 flex-1">
        <p class="kick !mb-0.5" data-test="slot-name">{{ slotName(slot.slot) }}</p>
        <p class="text-[17px] font-semibold leading-tight text-ink" data-test="slot-part">{{ partName(slot, engine) }}</p>
        <div v-if="part" class="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <RarityMark v-if="part.rarity_rank > 1" :rarity="part.rarity" />
          <span class="mono text-xs text-faint" data-test="slot-tier">{{ t('part.tier', { tier: part.tier ?? 0 }) }}</span>
          <UpgradeMark :level="part.upgrade" />
        </div>
      </div>
      <button type="button" class="-mr-1 -mt-1 flex h-9 w-9 flex-none items-center justify-center rounded-md text-faint hover:text-ink" :aria-label="t('garage.parts.close')" data-test="slot-close" @click="emit('close')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" /></svg>
      </button>
    </div>

    <template v-if="part">
      <PartCondition :percent="part.condition_percent" />
      <PartStats :part="part" />
      <PartAffixes :part="part" />
    </template>
    <p v-else class="sub" data-test="slot-factory">{{ slot.built_in ? t('garage.parts.builtIn') : t('garage.parts.factory') }}</p>

    <!-- What a screen has to do with the slot: the actions of the workshop on the part, the parts that fit. -->
    <slot />

    <RouterLink v-if="link" :to="{ name: 'workshop' }" class="btn btn-secondary btn-sm self-start no-underline" data-test="slot-workshop">
      {{ factory ? t('garage.parts.mount') : t('garage.parts.change') }}
    </RouterLink>
  </section>
</template>
