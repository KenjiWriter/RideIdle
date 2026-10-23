<script setup lang="ts">
import { useI18n } from 'vue-i18n'

import { useSessionStore } from '@/stores/session'

/** The ways the account can be signed in to. The address is masked by the server: enough to tell which one it is. */
const { t } = useI18n()
const session = useSessionStore()
</script>

<template>
  <section v-if="session.me && session.me.identities.length > 0" class="card flex flex-col gap-3" aria-labelledby="linked-title" data-test="linked">
    <h2 id="linked-title" class="text-xl font-bold">{{ t('linked.title') }}</h2>
    <ul class="flex flex-col gap-2">
      <li v-for="identity in session.me.identities" :key="identity.provider" class="flex flex-wrap items-baseline gap-x-3" :data-test="`identity-${identity.provider}`">
        <span class="font-semibold">{{ t(`provider.${identity.provider}`) }}</span>
        <span v-if="identity.email" class="text-sm text-muted">{{ identity.email }}</span>
      </li>
    </ul>
  </section>
</template>
