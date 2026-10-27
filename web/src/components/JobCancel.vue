<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import type { WorkshopJob } from '@/api/types'
import { useFormat } from '@/i18n/useFormat'

const props = defineProps<{
  job: WorkshopJob
  /** The jobs that are cancelled with this one: the putting on of the part that was to replace the one this job takes off. */
  dependents: WorkshopJob[]
  disabled: boolean
}>()

const emit = defineEmits<{ cancel: [jobId: number] }>()

const { t } = useI18n()
const { money } = useFormat()

const asking = ref(false)

/** A job that waits costs nothing to cancel and is cancelled at once; the driver is asked when something is lost with it. */
const loses = computed(() => props.job.status === 'working')
const needsAnswer = computed(() => loses.value || props.dependents.length > 0)

/** What is lost with a job at work: the work, and the price of an upgrade or of a merge (the parts of a merge stay). */
const lostText = computed(() => {
  if (props.job.cost_cents > 0 && props.job.kind === 'upgrade') {
    return t('workshop.cancelLosesUpgrade', { price: money(props.job.cost_cents, 2) })
  }

  if (props.job.cost_cents > 0 && props.job.kind === 'merge') {
    return t('workshop.cancelLosesMerge', { price: money(props.job.cost_cents, 2) })
  }

  return t('workshop.cancelLoses')
})

function cancel(): void {
  asking.value = false
  emit('cancel', props.job.id)
}

function ask(): void {
  if (needsAnswer.value) {
    asking.value = true
  } else {
    cancel()
  }
}
</script>

<template>
  <div v-if="asking" class="flex w-full basis-full flex-col gap-1.5 border-t border-line pt-2" role="alert" :data-test="`cancel-sure-${job.id}`">
    <p class="text-[13px] font-semibold text-ink">{{ t('workshop.cancelSure') }}</p>
    <!-- The price of an upgrade or of a merge is paid back only while the mechanic has not started on it, so for one at work it is lost with the work. -->
    <p v-if="loses" class="sub">
      {{ lostText }}
    </p>
    <p v-for="other in dependents" :key="other.id" class="sub">{{ t('workshop.cancelAlso', { part: other.part.name }) }}</p>
    <div class="flex gap-2">
      <button type="button" class="btn btn-danger btn-sm" :disabled="disabled" :data-test="`cancel-confirm-${job.id}`" @click="cancel">
        {{ t('workshop.cancelConfirm') }}
      </button>
      <button type="button" class="btn btn-secondary btn-sm" :data-test="`cancel-keep-${job.id}`" @click="asking = false">
        {{ t('workshop.cancelKeep') }}
      </button>
    </div>
  </div>
  <button v-else type="button" class="btn btn-secondary btn-sm shrink-0" :disabled="disabled" :data-test="`cancel-${job.id}`" @click="ask">
    {{ t('workshop.cancel') }}
  </button>
</template>
