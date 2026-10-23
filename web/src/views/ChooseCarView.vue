<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import { ApiError } from '@/api/client'
import StarterCarCard from '@/components/StarterCarCard.vue'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { useRunStore } from '@/stores/run'

const { t } = useI18n()
const router = useRouter()
const run = useRunStore()

useLeaveOnSignOut()

const loading = ref(true)
const loadError = ref(false)
const starting = ref<string | null>(null)
const error = ref<string | null>(null)

async function open(): Promise<void> {
  loading.value = true
  loadError.value = false

  try {
    // A driver who already has a car on the track has nothing to choose.
    await run.load()

    if (run.run) {
      await router.replace({ name: 'garage' })

      return
    }

    await run.loadStarters()
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = true
    }
  } finally {
    loading.value = false
  }
}

onMounted(open)

async function choose(slug: string): Promise<void> {
  if (starting.value) {
    return
  }

  starting.value = slug
  error.value = null

  try {
    await run.start(slug)
    await router.replace({ name: 'garage' })
  } catch (failure) {
    if (endSessionIfUnauthorized(failure)) {
      return
    }

    if (failure instanceof ApiError && failure.code === 'run_exists') {
      // The car was started in another tab or on another device: that run is the driver's one.
      await run.load()
      await router.replace({ name: 'garage' })

      return
    }

    error.value = failure instanceof ApiError && failure.status === 429 ? t('common.tooManyRequests') : t('common.error')
  } finally {
    starting.value = null
  }
}
</script>

<template>
  <main class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-12">
    <header class="flex flex-col gap-2">
      <p class="text-sm font-semibold uppercase tracking-wider text-amber" data-test="step">{{ t('choose.step') }}</p>
      <h1 class="text-3xl font-bold tracking-tight" data-test="title">{{ t('choose.title') }}</h1>
      <p class="text-muted">{{ t('choose.subtitle') }}</p>
      <p v-if="run.startTrack" class="text-sm text-muted" data-test="track">
        {{ t('choose.track', { track: run.startTrack.name }) }}
      </p>
    </header>

    <p v-if="loading" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <div v-else-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('choose.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="open">{{ t('common.retry') }}</button>
    </div>

    <div v-else class="grid gap-4 md:grid-cols-3">
      <StarterCarCard
        v-for="(car, index) in run.starters"
        :key="car.slug"
        :car="car"
        :recommended="index === 0"
        :starting="starting === car.slug"
        :disabled="starting !== null"
        @choose="choose"
      />
    </div>

    <p class="min-h-5 text-sm text-danger" role="alert" data-test="error">{{ error }}</p>
  </main>
</template>
