<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AccountMethods from '@/components/AccountMethods.vue'

/**
 * The window "Save your garage", shown after the first earning: link an account, so the garage survives a lost device
 * or cleared browser data. It is a dialog: the focus moves into it and stays there, Esc and a click outside close it,
 * and the focus goes back to where it was.
 */
const emit = defineEmits<{
  /** The driver wants to wait. */
  later: []
  /** The driver prefers the recovery code, which is on the page below. */
  recovery: []
}>()

const { t } = useI18n()

const dialog = ref<HTMLElement | null>(null)
let returnFocusTo: Element | null = null

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'

onMounted(() => {
  returnFocusTo = document.activeElement
  dialog.value?.focus()
})

onBeforeUnmount(() => {
  if (returnFocusTo instanceof HTMLElement) {
    returnFocusTo.focus()
  }
})

/** Keeps Tab inside the dialog. */
function keep(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('later')

    return
  }

  if (event.key !== 'Tab' || !dialog.value) {
    return
  }

  const items = [...dialog.value.querySelectorAll<HTMLElement>(FOCUSABLE)]
  const first = items[0]
  const last = items[items.length - 1]

  if (!first || !last) {
    return
  }

  if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}
</script>

<template>
  <div
    class="fixed inset-0 z-50 flex items-end justify-center bg-black/70 p-0 sm:items-center sm:p-4"
    data-test="backdrop"
    @click.self="emit('later')"
  >
    <div
      ref="dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="link-account-title"
      tabindex="-1"
      class="card flex max-h-full w-full max-w-md flex-col gap-4 overflow-y-auto rounded-b-none outline-none sm:rounded-b-xl"
      data-test="dialog"
      @keydown="keep"
    >
      <h2 id="link-account-title" class="text-2xl font-bold">{{ t('linkAccount.title') }}</h2>
      <p class="text-muted">{{ t('linkAccount.description') }}</p>

      <AccountMethods mode="link" />

      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
        <button type="button" class="text-muted underline" data-test="recovery" @click="emit('recovery')">
          {{ t('linkAccount.useCode') }}
        </button>
        <button type="button" class="btn btn-secondary" data-test="later" @click="emit('later')">{{ t('linkAccount.later') }}</button>
      </div>
    </div>
  </div>
</template>
