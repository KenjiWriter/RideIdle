<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import AccountMethods from '@/components/AccountMethods.vue'
import { useAccountStore } from '@/stores/account'
import { useSessionStore } from '@/stores/session'

/**
 * The ways the account can be signed in to, with a way to add another one, so that losing one does not lock the
 * driver out. The address is masked by the server: enough to tell which one it is.
 */
const { t } = useI18n()
const session = useSessionStore()
const account = useAccountStore()

const adding = ref(false)

const identities = computed(() => session.me?.identities ?? [])
const linked = computed(() => new Set(identities.value.map((identity) => identity.provider)))

/** Something is left to add: an e-mail, or a provider the server has on that the account does not have. */
const canAdd = computed(() => !linked.value.has('email') || account.providers.some((provider) => !linked.value.has(provider)))

// Only a driver with an account has anything to add, so only then is the server asked which providers are on.
watch(
  () => identities.value.length > 0,
  (hasAccount) => {
    if (hasAccount && !account.providersLoaded) {
      void account.loadProviders()
    }
  },
  { immediate: true },
)
</script>

<template>
  <section v-if="identities.length > 0" class="card flex flex-col gap-3" aria-labelledby="linked-title" data-test="linked">
    <h2 id="linked-title" class="text-xl font-bold">{{ t('linked.title') }}</h2>

    <ul class="flex flex-col gap-2">
      <li v-for="identity in identities" :key="identity.provider" class="flex flex-wrap items-baseline gap-x-3" :data-test="`identity-${identity.provider}`">
        <span class="font-semibold">{{ t(`provider.${identity.provider}`) }}</span>
        <span v-if="identity.email" class="text-sm text-muted">{{ identity.email }}</span>
      </li>
    </ul>

    <template v-if="canAdd">
      <div>
        <button
          type="button"
          class="btn btn-secondary"
          :aria-expanded="adding"
          aria-controls="add-sign-in"
          data-test="add-toggle"
          @click="adding = !adding"
        >
          {{ adding ? t('linked.close') : t('linked.add') }}
        </button>
      </div>

      <div v-if="adding" id="add-sign-in" class="flex flex-col gap-4 border-t border-line pt-4" data-test="add">
        <p class="text-sm text-muted">{{ t('linked.addDescription') }}</p>
        <AccountMethods mode="add" />
      </div>
    </template>
  </section>
</template>
