<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import BrandMark from '@/components/BrandMark.vue'
import LandingActions from '@/components/LandingActions.vue'
import LandingBlocks from '@/components/LandingBlocks.vue'
import hero from '@/assets/art/garage-hero.jpg'
import { useLanding } from '@/game/landing'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const session = useSessionStore()

// The game may have a front page of its own (blocks that its pack says); else the page is the one of the app.
const { landing } = useLanding()
const blocks = computed(() => landing.value?.blocks ?? null)

// The account was deleted on this device a moment ago: say so, once.
const accountDeleted = ref(session.accountDeleted)

// A returning driver: load the profile so the button can greet them by name.
onMounted(() => {
  session.acknowledgeDeletion()

  if (session.isAuthenticated && !session.me) {
    void session.fetchMe().catch(() => undefined)
  }
})

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

    <LandingBlocks v-if="blocks" :blocks="blocks" />

    <template v-else>
      <header class="pb-2 pt-10 sm:pt-[72px]">
        <p class="font-mono text-xs uppercase text-amber" style="letter-spacing: 0.14em">{{ t('landing.eyebrow') }}</p>
        <h1 class="mb-5 mt-3.5 max-w-[16ch] font-display text-[44px] font-bold uppercase leading-[0.94] sm:text-[76px]">
          {{ t('landing.title') }}
        </h1>
        <p class="max-w-[60ch] text-lg leading-normal text-muted sm:text-[21px]">{{ t('landing.subtitle') }}</p>

        <LandingActions class="mt-8" />

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
    </template>
  </main>
</template>
