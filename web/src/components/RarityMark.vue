<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { PartRarity } from '@/api/types'
import { rankOf } from '@/features/parts/rarity'

const props = defineProps<{ rarity: PartRarity }>()

const { t } = useI18n()

const rank = computed(() => rankOf(props.rarity))
</script>

<template>
  <!-- The rarity is the name and the number of the filled squares, and the color only helps (as on the boards). -->
  <span class="rn" :class="`r${rank}`" :data-rarity="rarity" data-test="rarity">
    {{ t(`rarity.${rarity}`) }}
    <span class="pips" aria-hidden="true"><b v-for="n in 5" :key="n" :class="{ on: n <= rank }" /></span>
  </span>
</template>
