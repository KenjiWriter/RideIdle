<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import LandingActions from '@/components/LandingActions.vue'
import MediaPicture from '@/components/MediaPicture.vue'
import { pickText, type LandingBlock } from '@/game/landing'

const props = defineProps<{ blocks: LandingBlock[] }>()

const { locale } = useI18n()

/** The text of a field in the language of the visitor, or the English text of the pack. Plain text: it is never read as markup. */
function say(text: LandingBlock['title']): string {
  return pickText(text, locale.value)
}

/** A long text as its paragraphs (a blank line starts a new one). */
function paragraphs(text: LandingBlock['body']): string[] {
  return say(text)
    .split(/\n\s*\n/)
    .map((paragraph) => paragraph.trim())
    .filter((paragraph) => paragraph !== '')
}

// A page has one main title: the first hero. A later hero is a heading of the second level.
const firstHero = computed(() => props.blocks.findIndex((block) => block.type === 'hero'))
</script>

<template>
  <div data-test="landing-blocks">
    <template v-for="(block, index) in blocks" :key="index">
      <section v-if="block.type === 'hero'" class="pb-2 pt-10 sm:pt-[72px]" data-test="block-hero">
        <p v-if="block.eyebrow" class="font-mono text-xs uppercase text-amber" style="letter-spacing: 0.14em">{{ say(block.eyebrow) }}</p>
        <component
          :is="index === firstHero ? 'h1' : 'h2'"
          class="mb-5 mt-3.5 max-w-[16ch] font-display text-[44px] font-bold uppercase leading-[0.94] sm:text-[76px]"
        >
          {{ say(block.title) }}
        </component>
        <p v-if="block.subtitle" class="max-w-[60ch] text-lg leading-normal text-muted sm:text-[21px]">{{ say(block.subtitle) }}</p>

        <LandingActions class="mt-8" />

        <div class="hz mt-10" />

        <MediaPicture
          :src="block.image_url"
          :alt="say(block.alt)"
          class="pic mt-12 h-[220px] shadow-[0_10px_34px_rgba(0,0,0,0.55)] sm:h-[340px]"
          data-test="block-hero-image"
        />
      </section>

      <div
        v-else-if="block.type === 'features'"
        class="mt-11 grid grid-cols-[repeat(auto-fit,minmax(min(300px,100%),1fr))] gap-5"
        data-test="block-features"
      >
        <section v-for="(item, position) in block.items" :key="position" class="card-flat" data-test="block-feature">
          <p v-if="item.kicker" class="kick mb-2.5">{{ say(item.kicker) }}</p>
          <h2 class="mb-2 font-display text-[25px] font-semibold uppercase leading-[1.1] tracking-[0.02em] text-ink">{{ say(item.title) }}</h2>
          <p class="text-[15px] text-muted">{{ say(item.text) }}</p>
        </section>
      </div>

      <section v-else-if="block.type === 'text'" class="mt-11 max-w-[70ch]" data-test="block-text">
        <h2 v-if="block.title" class="mb-3 font-display text-[32px] font-semibold uppercase leading-[1.1] tracking-[0.02em] text-ink">{{ say(block.title) }}</h2>
        <p v-for="(paragraph, position) in paragraphs(block.body)" :key="position" class="mb-4 whitespace-pre-line text-[17px] leading-relaxed text-muted">{{ paragraph }}</p>
      </section>

      <figure v-else-if="block.type === 'image'" class="mt-11" data-test="block-image">
        <MediaPicture :src="block.image_url" :alt="say(block.alt)" class="pic w-full" />
        <figcaption v-if="block.caption" class="sub mt-2 text-sm">{{ say(block.caption) }}</figcaption>
      </figure>

      <section v-else-if="block.type === 'cta'" class="card card-hi mt-11" data-test="block-cta">
        <h2 v-if="block.title" class="mb-2 font-display text-[32px] font-semibold uppercase leading-[1.1] tracking-[0.02em] text-ink">{{ say(block.title) }}</h2>
        <p v-if="block.text" class="mb-5 max-w-[60ch] text-[17px] text-muted">{{ say(block.text) }}</p>
        <LandingActions />
      </section>
    </template>
  </div>
</template>
