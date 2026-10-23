<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BrandMark from '@/components/BrandMark.vue'
import hero from '@/assets/art/garage-hero.jpg'
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

/** The three reasons to play, in the order of the canvas. */
const FEATURES = ['match', 'grow', 'idle'] as const
</script>

<template>
  <main class="mx-auto w-full max-w-[1120px] px-5 pb-10 sm:px-10">
    <div class="flex flex-wrap items-center justify-between gap-3.5 border-b border-line py-[22px]">
      <BrandMark />
      <nav v-if="!session.isAuthenticated">
        <RouterLink to="/recover" class="pill" data-test="have-driver">{{ t('landing.haveDriver') }}</RouterLink>
      </nav>
    </div>

    <p v-if="accountDeleted" class="card card-hi mt-6 text-sm" role="status" data-test="account-deleted">
      {{ t('landing.accountDeleted') }}
    </p>

    <header class="pb-2 pt-10 sm:pt-[72px]">
      <p class="font-mono text-xs uppercase text-amber" style="letter-spacing: 0.14em">{{ t('landing.eyebrow') }}</p>
      <h1 class="mb-5 mt-3.5 max-w-[16ch] font-display text-[44px] font-bold uppercase leading-[0.94] sm:text-[76px]">
        {{ t('landing.title') }}
      </h1>
      <p class="max-w-[60ch] text-lg leading-normal text-muted sm:text-[21px]">{{ t('landing.subtitle') }}</p>

      <div class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-3.5">
        <RouterLink v-if="session.isAuthenticated" to="/garage" class="btn btn-primary !min-h-16 !px-6 !text-2xl sm:!px-10 sm:!text-[28px]" data-test="continue">
          {{ continueLabel }}
        </RouterLink>
        <template v-else>
          <RouterLink to="/create" class="btn btn-primary !min-h-16 !px-6 !text-2xl sm:!px-10 sm:!text-[28px]" data-test="create">{{ t('landing.create') }}</RouterLink>
          <span class="sub !text-sm">{{ t('landing.noEmail') }}</span>
        </template>
      </div>

      <div class="hz mt-10" />
    </header>

    <img
      :src="hero"
      :alt="t('landing.imageAlt')"
      class="pic mt-12 h-[220px] object-[50%_62%] shadow-[0_10px_34px_rgba(0,0,0,0.55)] sm:h-[340px]"
      data-test="hero"
    />

    <div class="mt-11 grid grid-cols-[repeat(auto-fit,minmax(min(300px,100%),1fr))] gap-5" data-test="features">
      <section v-for="feature in FEATURES" :key="feature" class="card-flat" :data-test="`feature-${feature}`">
        <p class="kick mb-2.5">{{ t(`landing.features.${feature}.kicker`) }}</p>
        <h2 class="mb-2 font-display text-[25px] font-semibold uppercase leading-[1.1] tracking-[0.02em] text-ink">
          {{ t(`landing.features.${feature}.title`) }}
        </h2>
        <p class="text-[15px] text-muted">{{ t(`landing.features.${feature}.text`) }}</p>
      </section>
    </div>
  </main>
</template>
