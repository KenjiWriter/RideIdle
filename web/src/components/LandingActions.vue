<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const session = useSessionStore()

const continueLabel = computed(() =>
  session.me ? t('landing.continue', { name: session.me.name }) : t('landing.continueGeneric'),
)
</script>

<template>
  <!-- The way into the game: on, or to begin, as the visitor is. The page puts it where it wants it (under the title, or at the end of a page of blocks). -->
  <div class="flex flex-wrap items-center gap-x-5 gap-y-3.5" data-test="landing-actions">
    <RouterLink v-if="session.isAuthenticated" to="/garage" class="btn btn-primary !min-h-16 !px-6 !text-2xl sm:!px-10 sm:!text-[28px]" data-test="continue">
      {{ continueLabel }}
    </RouterLink>
    <template v-else>
      <RouterLink to="/create" class="btn btn-primary !min-h-16 !px-6 !text-2xl sm:!px-10 sm:!text-[28px]" data-test="create">{{ t('landing.create') }}</RouterLink>
      <span class="sub !text-sm">{{ t('landing.noEmail') }}</span>
    </template>
  </div>
</template>
