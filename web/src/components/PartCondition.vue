<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ percent: number }>()

const { t } = useI18n()

/** Under a fifth of its life a part is unserviceable, and the car breaks down more. */
const unserviceable = computed(() => props.percent < 20)
</script>

<template>
  <!-- How much of its life a part has left: nothing for a part that is as good as new. -->
  <div v-if="percent < 100" class="flex flex-col gap-1" data-test="part-condition">
    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" :class="unserviceable ? 'text-danger' : 'text-muted'">
      <span data-test="part-condition-value">{{ t('part.condition', { percent }) }}</span>
      <span v-if="unserviceable" class="tag !border-danger !text-danger" data-test="part-unserviceable">{{ t('part.unserviceable') }}</span>
    </p>
    <div class="bar" :class="{ 'bar-bad': unserviceable }" role="progressbar" :aria-label="t('part.condition', { percent })" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent">
      <i :style="{ width: `${percent}%` }" />
    </div>
  </div>
</template>
