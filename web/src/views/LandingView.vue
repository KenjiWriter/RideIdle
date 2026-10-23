<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const session = useSessionStore()

// The account was deleted on this device a moment ago: say so, once.
const accountDeleted = ref(session.accountDeleted)

// A returning driver: load the profile so the button can greet them by name.
onMounted(() => {
  session.acknowledgeDeletion()

  if (session.isAuthenticated && !session.me) {
    void session.fetchMe().catch(() => undefined)
  }
})

const continueLabel = computed(() =>
  session.me ? t('landing.continue', { name: session.me.name }) : t('landing.continueGeneric'),
)
</script>

<template>
  <main class="mx-auto flex max-w-xl flex-col items-center gap-8 px-4 py-16 text-center">
    <div class="flex flex-col gap-4">
      <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">{{ t('landing.title') }}</h1>
      <p class="text-lg text-muted">{{ t('landing.subtitle') }}</p>
    </div>

    <p v-if="accountDeleted" class="rounded-lg border border-line bg-surface-2 p-4 text-sm" role="status" data-test="account-deleted">
      {{ t('landing.accountDeleted') }}
    </p>

    <div class="flex w-full flex-col gap-3 sm:w-auto sm:min-w-72">
      <RouterLink v-if="session.isAuthenticated" to="/garage" class="btn btn-primary" data-test="continue">
        {{ continueLabel }}
      </RouterLink>
      <template v-else>
        <RouterLink to="/create" class="btn btn-primary" data-test="create">{{ t('landing.create') }}</RouterLink>
        <RouterLink to="/recover" class="btn btn-secondary" data-test="have-driver">
          {{ t('landing.haveDriver') }}
        </RouterLink>
      </template>
    </div>
  </main>
</template>
