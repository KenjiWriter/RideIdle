<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import BrandMark from './BrandMark.vue'

const props = withDefaults(defineProps<{ step: number; total?: number; dense?: boolean }>(), { total: 2, dense: false })

const { t } = useI18n()

const fill = computed(() => Math.round((props.step / props.total) * 100))
</script>

<template>
  <div class="mx-auto flex min-h-[calc(100svh-5rem)] w-full max-w-[480px] flex-col border-x border-line bg-bg/70">
    <div class="flex flex-1 flex-col" :class="dense ? 'gap-2.5 px-3.5 pb-4 pt-[22px]' : 'gap-4 px-5 py-7'">
      <div class="flex items-center justify-between">
        <BrandMark class="!text-[22px]" />
        <span class="sub" data-test="step">{{ t('onboarding.step', { step, total }) }}</span>
      </div>
      <div
        class="bar"
        role="progressbar"
        :aria-label="t('onboarding.step', { step, total })"
        :aria-valuenow="fill"
        aria-valuemin="0"
        aria-valuemax="100"
      >
        <i :style="{ width: `${fill}%` }" />
      </div>

      <slot />
    </div>
  </div>
</template>
