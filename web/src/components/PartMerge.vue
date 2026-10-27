<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import type { MergeOffer, OwnedPart } from '@/api/types'
import RarityMark from '@/components/RarityMark.vue'
import UpgradeMark from '@/components/UpgradeMark.vue'
import { formatDuration } from '@/i18n/format'
import { useFormat } from '@/i18n/useFormat'

const props = defineProps<{
  /** The part that stays: its model and its upgrade go to the part that is made. */
  part: OwnedPart
  offer: MergeOffer
  /** The other parts of the inventory that it can be merged with, the ones the server listed. */
  partners: OwnedPart[]
  disabled: boolean
}>()

const emit = defineEmits<{ merge: [withId: number]; close: [] }>()

const { t } = useI18n()
const { money, percent } = useFormat()

const chosen = ref<number | null>(null)
const partner = computed(() => props.partners.find((other) => other.id === chosen.value) ?? null)

/** What the attempt has going for it in percent, and how much of that is the protection from bad luck (points). */
const chance = computed(() => props.offer.chance_ppm / 10_000)
const pity = computed(() => props.offer.pity_ppm / 10_000)
</script>

<template>
  <div class="flex w-full basis-full flex-col gap-2 border-t border-line pt-2" :data-test="`merge-${part.id}`">
    <p class="text-[13px] font-semibold text-ink">{{ t('workshop.mergeTitle') }}</p>

    <!-- Nothing to merge it with: a second part of the same slot, tier and rarity is what it takes. -->
    <p v-if="partners.length === 0" class="sub" data-test="merge-hint">
      {{ t('workshop.mergeNeedPartner', { slot: t(`slot.${part.slot}`), tier: t('part.tier', { tier: part.tier ?? 0 }), rarity: t(`rarity.${part.rarity}`) }) }}
    </p>

    <template v-else>
      <p class="sub">{{ t('workshop.mergeChoose') }}</p>
      <ul class="flex flex-col gap-1.5">
        <li v-for="other in partners" :key="other.id">
          <button
            type="button"
            class="btn btn-secondary btn-sm w-full !justify-between"
            :class="chosen === other.id ? 'ring-2 ring-cyan' : ''"
            :aria-pressed="chosen === other.id"
            :data-test="`merge-partner-${other.id}`"
            @click="chosen = other.id"
          >
            <span class="min-w-0 truncate">{{ other.name }}</span>
            <span class="flex items-center gap-1.5">
              <RarityMark :rarity="other.rarity" />
              <UpgradeMark :level="other.upgrade" />
            </span>
          </button>
        </li>
      </ul>
    </template>

    <div v-if="partner" class="flex flex-col gap-1" data-test="merge-summary">
      <p class="flex flex-wrap items-center gap-1.5 text-[13px] text-ink" data-test="merge-target">
        <RarityMark :rarity="part.rarity" />
        <span aria-hidden="true">→</span>
        <RarityMark :rarity="offer.to" />
      </p>
      <p class="text-[13px] text-ink" data-test="merge-chance">
        {{
          offer.pity_ppm > 0
            ? t('workshop.mergeChancePity', { chance: percent(chance), base: percent(chance - pity), pity: percent(pity) })
            : t('workshop.mergeChance', { chance: percent(chance) })
        }}
      </p>
      <p class="sub" data-test="merge-cost">{{ t('workshop.mergeCost', { price: money(offer.cost_cents, 2), time: formatDuration(offer.duration_ms) }) }}</p>
      <p class="sub" data-test="merge-keeps">{{ t('workshop.mergeKeeps', { part: part.name, other: partner.name }) }}</p>
      <p class="sub" data-test="merge-failure">{{ t('workshop.mergeFailure', { scrap: money(offer.scrap_cents, 2) }) }}</p>
    </div>

    <div class="flex gap-2">
      <button
        v-if="partners.length > 0"
        type="button"
        class="btn btn-primary btn-sm"
        :disabled="disabled || partner === null"
        :data-test="`merge-confirm-${part.id}`"
        @click="partner && emit('merge', partner.id)"
      >
        {{ t('workshop.mergeConfirm') }}
      </button>
      <button type="button" class="btn btn-secondary btn-sm" :data-test="`merge-close-${part.id}`" @click="emit('close')">
        {{ t('workshop.mergeClose') }}
      </button>
    </div>
  </div>
</template>
