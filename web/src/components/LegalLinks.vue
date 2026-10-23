<script setup lang="ts">
import { I18nT, useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

/**
 * A sentence that names the terms and the privacy policy, with a link to each. They open in another tab: the reader
 * is in the middle of something (a window that asks for consent, a form), and coming back must find it as it was.
 * The sentence is a message of its own, with {terms} and {privacy}, because where the names go differs by language.
 */
defineProps<{ keypath: string }>()

const { t } = useI18n()
</script>

<template>
  <I18nT :keypath="keypath" tag="span" scope="global">
    <template #terms>
      <RouterLink to="/terms" target="_blank" rel="noopener" class="text-amber underline" data-test="terms-link">{{ t('legal.termsLink') }}</RouterLink>
    </template>
    <template #privacy>
      <RouterLink to="/privacy" target="_blank" rel="noopener" class="text-amber underline" data-test="privacy-link">{{ t('legal.privacyLink') }}</RouterLink>
    </template>
  </I18nT>
</template>
