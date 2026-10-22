<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import RecoveryPanel from '@/components/RecoveryPanel.vue'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

onMounted(() => {
  if (!session.me) {
    void session.fetchMe().catch(() => undefined)
  }
})

// Leaving the session, by signing out or because the server no longer knows the token, returns to the start.
watch(
  () => session.isAuthenticated,
  (signedIn) => {
    if (!signedIn) {
      void router.push({ name: 'landing' })
    }
  },
)

const title = computed(() => (session.me ? t('garage.welcome', { name: session.me.name }) : t('common.loading')))
</script>

<template>
  <main class="mx-auto flex max-w-xl flex-col gap-6 px-4 py-12">
    <h1 class="text-3xl font-bold tracking-tight" data-test="title">{{ title }}</h1>
    <p class="text-muted">{{ t('garage.placeholder') }}</p>

    <RecoveryPanel v-if="session.me?.is_guest" />

    <div>
      <button type="button" class="btn btn-secondary" data-test="sign-out" @click="session.signOut()">
        {{ t('garage.signOut') }}
      </button>
    </div>
  </main>
</template>
