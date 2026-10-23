<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { currentLocale } from '@/i18n'
import { useFormat } from '@/i18n/useFormat'
import { fillLegal } from '@/legal/fill'
import { loadLegalTexts } from '@/legal/texts'
import type { LegalDocumentName, LegalTexts } from '@/legal/types'
import { useAccountStore } from '@/stores/account'

/** The terms of service or the privacy policy, in the language of the interface. Open to everybody. */
const props = defineProps<{ document: LegalDocumentName }>()

const { t, locale } = useI18n()
const { day } = useFormat()
const account = useAccountStore()

const texts = shallowRef<LegalTexts | null>(null)
const failed = ref(false)

const other = computed<LegalDocumentName>(() => (props.document === 'terms' ? 'privacy' : 'terms'))
const doc = computed(() => texts.value?.[props.document] ?? null)
const legal = computed(() => account.legal)

function fill(text: string): string {
  return legal.value ? fillLegal(text, { minAge: legal.value.min_age, guestDays: legal.value.guest_expiry_days }) : text
}

/** The operator's details, each with what to show when the server has none. */
const operator = computed(() => [
  { key: 'name', label: t('legal.name'), value: legal.value?.operator.name ?? null },
  { key: 'address', label: t('legal.address'), value: legal.value?.operator.address ?? null },
  { key: 'email', label: t('legal.email'), value: legal.value?.operator.email ?? null },
])

let asked = 0

/** Loads the numbers and the texts. Only the newest question counts, so a quick change of language shows the last one. */
async function load(): Promise<void> {
  const mine = ++asked
  failed.value = false

  try {
    const [loaded] = await Promise.all([loadLegalTexts(currentLocale()), account.loadLegal()])

    if (mine === asked) {
      texts.value = loaded
    }
  } catch {
    if (mine === asked) {
      failed.value = true
    }
  }
}

onMounted(load)
watch(locale, load)
</script>

<template>
  <main class="mx-auto flex max-w-2xl flex-col gap-8 px-4 py-10">
    <p v-if="failed" class="flex flex-col items-start gap-3" data-test="load-error">
      <span class="text-danger" role="alert">{{ t('legal.loadError') }}</span>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="load">{{ t('common.retry') }}</button>
    </p>

    <p v-else-if="!doc || !legal" class="text-muted" role="status" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <header class="flex flex-col gap-3">
        <h1 class="text-3xl font-bold tracking-tight" data-test="title">{{ doc.title }}</h1>
        <p class="text-sm text-muted" data-test="version">{{ t('legal.version', { date: day(legal.version) }) }}</p>
        <p class="text-muted" data-test="intro">{{ doc.intro }}</p>
        <RouterLink :to="`/${other}`" class="w-fit text-amber underline" data-test="other">{{ t(`legal.${other}`) }}</RouterLink>
      </header>

      <section class="card flex flex-col gap-3" aria-labelledby="legal-operator" data-test="operator">
        <h2 id="legal-operator" class="text-xl font-bold">{{ t('legal.operator') }}</h2>
        <dl class="flex flex-col gap-2">
          <div v-for="item in operator" :key="item.key" class="flex flex-col gap-0.5 sm:flex-row sm:gap-3">
            <dt class="text-sm text-muted sm:w-36 sm:shrink-0">{{ item.label }}</dt>
            <dd class="break-words" :data-test="`operator-${item.key}`">
              <template v-if="item.value">
                <a v-if="item.key === 'email'" :href="`mailto:${item.value}`" class="text-amber underline">{{ item.value }}</a>
                <template v-else>{{ item.value }}</template>
              </template>
              <span v-else class="text-danger">{{ t('legal.notSet') }}</span>
            </dd>
          </div>
        </dl>
      </section>

      <nav :aria-label="t('legal.contents')" data-test="contents">
        <h2 class="mb-2 text-xl font-bold">{{ t('legal.contents') }}</h2>
        <ol class="flex list-decimal flex-col gap-1 pl-6">
          <li v-for="section in doc.sections" :key="section.id">
            <a :href="`#${section.id}`" class="text-amber underline" :data-test="`link-${section.id}`">{{ section.heading }}</a>
          </li>
        </ol>
      </nav>

      <section
        v-for="(section, index) in doc.sections"
        :id="section.id"
        :key="section.id"
        class="flex scroll-mt-4 flex-col gap-3"
        :aria-labelledby="`${section.id}-heading`"
        data-test="section"
      >
        <h2 :id="`${section.id}-heading`" class="text-xl font-bold">{{ index + 1 }}. {{ section.heading }}</h2>
        <p v-for="(paragraph, at) in section.paragraphs" :key="at" class="leading-relaxed">{{ fill(paragraph) }}</p>
      </section>

      <RouterLink to="/" class="w-fit text-muted underline" data-test="back">{{ t('legal.back') }}</RouterLink>
    </template>
  </main>
</template>
