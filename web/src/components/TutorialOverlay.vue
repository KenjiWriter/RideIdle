<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { currentLocale } from '@/i18n'
import { useTutorialStore } from '@/stores/tutorial'

/** The room round the highlighted thing, in px. */
const SPOTLIGHT_PADDING = 6

/** How often the place of the highlighted thing is measured again: the page moves under it (it loads, it scrolls, a message appears). */
const MEASURE_MS = 250

const { t } = useI18n()
const store = useTutorialStore()

const busy = ref(false)
const error = ref(false)
const confirming = ref(false)
/** The driver asked who is writing. The mentor does not say. */
const asked = ref(false)
const rect = ref<{ top: number; left: number; width: number; height: number } | null>(null)
/** Where the button that skips goes: just under the HUD, wherever the page has scrolled it to. */
const skipTop = ref(76)
const replyButton = ref<HTMLButtonElement | null>(null)
const keepButton = ref<HTMLButtonElement | null>(null)

const step = computed(() => store.current)
const clock = computed(() => new Intl.DateTimeFormat(currentLocale(), { hour: '2-digit', minute: '2-digit' }).format(new Date()))

/**
 * What a step points at, in the order it is looked for: the thing itself on the screen the driver is on, and where it is not there, the tab of the navigation
 * that leads to the screen where it is (the Start is on the tournament screen, the tires are put on in the workshop, the crate is opened in the shop, the journey
 * begins on the map).
 */
const TARGETS: Record<string, string[]> = {
  start: ['start', 'race'],
  laps: ['laps', 'garage'],
  refuel: ['refuel', 'garage'],
  repair: ['repair', 'garage'],
  mount: ['mount', 'workshop'],
  crate: ['crate', 'shop'],
  travel: ['travel', 'map'],
  merge: ['merge', 'workshop'],
  exchange: ['exchange', 'shop'],
  service: ['service', 'workshop'],
  away: ['away', 'garage'],
  upgrade: ['upgrade', 'workshop'],
  equipment: ['equipment', 'workshop'],
  profile: ['profile', 'garage'],
  garages: ['garages', 'map'],
}

function targetElement(): HTMLElement | null {
  for (const name of step.value?.target ? (TARGETS[step.value.target] ?? [step.value.target]) : []) {
    const element = document.querySelector<HTMLElement>(`[data-tutorial="${name}"]`)

    if (element) {
      return element
    }
  }

  return null
}

/** The step whose target has been brought into view: once for each, so that the driver can scroll away. */
let scrolledFor: string | null = null

function measure(): void {
  const element = targetElement()
  const box = element?.getBoundingClientRect()

  // The page may still be loading when the step comes, so the target is brought into view when it is first found.
  if (element && store.showing && step.value && scrolledFor !== step.value.key) {
    scrolledFor = step.value.key
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false

    element.scrollIntoView?.({ block: 'start', behavior: reduced ? 'auto' : 'smooth' })
  }

  rect.value = box ? { top: box.top, left: box.left, width: box.width, height: box.height } : null
  skipTop.value = Math.round((document.querySelector('[data-test="hud"]')?.getBoundingClientRect().bottom ?? 68) + 8)
}

let timer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  measure()
  timer = setInterval(measure, MEASURE_MS)
  window.addEventListener('resize', measure)
  window.addEventListener('scroll', measure, { passive: true })
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  clearInterval(timer)
  window.removeEventListener('resize', measure)
  window.removeEventListener('scroll', measure)
  window.removeEventListener('keydown', onKeydown)
})

/** A new step starts afresh, and puts the answer in reach of the keyboard. */
watch(
  () => [step.value?.key, store.showing] as const,
  async ([key, showing]) => {
    asked.value = false
    error.value = false
    scrolledFor = null

    if (!key) {
      return
    }

    await nextTick()
    measure()

    if (showing) {
      replyButton.value?.focus()
    }
  },
  { immediate: true },
)

const ringStyle = computed(() => {
  const box = rect.value

  return box
    ? {
        top: `${box.top - SPOTLIGHT_PADDING}px`,
        left: `${box.left - SPOTLIGHT_PADDING}px`,
        width: `${box.width + SPOTLIGHT_PADDING * 2}px`,
        height: `${box.height + SPOTLIGHT_PADDING * 2}px`,
      }
    : {}
})

/** The dim layer with a hole in it: a tap outside the hole is stopped by the layer, a tap inside reaches the thing. */
const dimStyle = computed(() => {
  const box = rect.value

  if (!box) {
    return {}
  }

  const left = box.left - SPOTLIGHT_PADDING
  const top = box.top - SPOTLIGHT_PADDING
  const right = box.left + box.width + SPOTLIGHT_PADDING
  const bottom = box.top + box.height + SPOTLIGHT_PADDING

  return {
    clipPath: `polygon(evenodd, 0 0, 100% 0, 100% 100%, 0 100%, 0 0, ${left}px ${top}px, ${left}px ${bottom}px, ${right}px ${bottom}px, ${right}px ${top}px, ${left}px ${top}px)`,
  }
})

async function run(action: () => Promise<void>): Promise<void> {
  if (busy.value) {
    return
  }

  busy.value = true
  error.value = false

  try {
    await action()
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      error.value = true
    }
  } finally {
    busy.value = false
  }
}

const reply = (): Promise<void> => run(() => store.reply())
const decline = (): Promise<void> => run(() => store.decline())

async function skip(): Promise<void> {
  await run(() => store.skip())
  confirming.value = false
}

async function askToSkip(): Promise<void> {
  confirming.value = true
  await nextTick()
  keepButton.value?.focus()
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && confirming.value) {
    confirming.value = false
  }
}
</script>

<template>
  <div v-if="step" data-test="tutorial">
    <!-- The thing the step points at: ringed while the phone is out and after it is put away, until the driver does it. -->
    <div v-if="rect" class="pointer-events-none fixed z-[41] rounded-lg" :class="store.showing ? 'ring-hl' : 'ring-hl ring-soft'" :style="ringStyle" aria-hidden="true" data-test="tutorial-ring" />

    <template v-if="store.showing">
      <div class="fixed inset-0 z-40 bg-[rgba(6,8,10,0.82)]" :style="dimStyle" aria-hidden="true" data-test="tutorial-dim" />

      <button type="button" class="btn btn-secondary btn-sm fixed right-3.5 z-[43]" :style="{ top: `${skipTop}px` }" data-test="tutorial-skip" @click="askToSkip">
        {{ t('tutorial.skip') }}
      </button>

      <div class="pointer-events-none fixed inset-x-0 bottom-[-26px] z-[42] flex justify-center px-[34px]">
        <section
          class="phone phone-in pointer-events-auto w-[264px] max-w-full"
          style="height: min(500px, 64svh); transform: rotate(-2deg)"
          role="dialog"
          aria-modal="true"
          :aria-label="t('tutorial.from', { sender: step.sender })"
          data-test="tutorial-phone"
        >
          <div class="spk" />
          <div class="scr2">
            <div class="ps">
              <span>{{ t('tutorial.network') }}</span>
              <b>{{ clock }}</b>
              <span class="flex items-center gap-1">
                78%
                <i class="relative inline-block h-[9px] w-[18px] rounded-[2px] border border-[#e8eaf0]"><i class="absolute inset-y-px left-px right-1 bg-[#7be05a]" /></i>
              </span>
            </div>
            <div class="ph-hd">
              <span class="ph-btn !px-2">
                <span aria-hidden="true">‹</span>
                <span class="sr-only">{{ t('tutorial.messages') }}</span>
              </span>
              <span class="min-w-0 flex-1 truncate text-center text-[11px]" data-test="tutorial-sender">{{ step.sender }}</span>
              <span class="ph-btn !px-1.5 !text-[11px]" data-test="tutorial-step">{{ t('tutorial.step', { number: step.number, total: store.tutorial?.total ?? 0 }) }}</span>
            </div>

            <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-2 py-2.5" aria-live="polite" data-test="tutorial-thread">
              <div class="sms-t">{{ t('tutorial.today', { time: clock }) }}</div>
              <div v-for="line in step.sms" :key="line" class="sms-in" data-test="tutorial-sms">{{ line }}</div>
              <template v-if="asked">
                <div class="sms-out">{{ t('tutorial.who') }}</div>
                <div class="sms-in" data-test="tutorial-deflect">{{ t('tutorial.deflect') }}</div>
              </template>
              <p v-if="error" class="sms-t !text-[#b3261e]" role="alert" data-test="tutorial-error">{{ t('common.error') }}</p>
            </div>

            <div class="flex flex-none flex-wrap gap-1.5 px-2 pb-2">
              <button ref="replyButton" type="button" class="ph-send !min-h-11 !border" :disabled="busy" data-test="tutorial-reply" @click="reply">{{ step.reply }}</button>
              <button v-if="step.optional && step.decline" type="button" class="ph-btn !min-h-11" :disabled="busy" data-test="tutorial-decline" @click="decline">{{ step.decline }}</button>
              <button v-if="!asked" type="button" class="ph-btn !min-h-11" data-test="tutorial-who" @click="asked = true">{{ t('tutorial.who') }}</button>
            </div>
            <div class="ph-input" aria-hidden="true">
              <div class="fx">{{ t('tutorial.input') }}</div>
              <span class="ph-send">{{ t('tutorial.send') }}</span>
            </div>

            <!-- The screen is cracked: the phone is old, and it has been dropped. Only for the eye, it never gets in the way of a tap. -->
            <svg class="crack" viewBox="0 0 100 200" preserveAspectRatio="none" aria-hidden="true" data-test="tutorial-crack">
              <g class="crack-shade">
                <path d="M80 34 L66 30 L52 36 L41 31 L22 44 L9 41" />
                <path d="M80 34 L72 47 L74 63 L63 78 L66 96 L52 120 L55 148" />
                <path d="M80 34 L88 46 L94 58 L91 76 L100 88" />
                <path d="M80 34 L84 20 L79 8 L86 0" />
                <path d="M80 34 L92 28 L100 22" />
                <path d="M80 34 L61 52 L40 56 L26 74 L0 80" />
                <path d="M52 36 L47 46 L38 49" />
                <path d="M63 78 L74 84 L83 99" />
                <path d="M74 36 L79 43 L87 41 L88 31 L80 26 L73 30 Z" />
                <path d="M64 36 L72 51 L88 54 L96 40" />
              </g>
              <g class="crack-line">
                <path d="M80 34 L66 30 L52 36 L41 31 L22 44 L9 41" />
                <path d="M80 34 L72 47 L74 63 L63 78 L66 96 L52 120 L55 148" />
                <path d="M80 34 L88 46 L94 58 L91 76 L100 88" />
                <path d="M80 34 L84 20 L79 8 L86 0" />
                <path d="M80 34 L92 28 L100 22" />
                <path d="M80 34 L61 52 L40 56 L26 74 L0 80" />
                <path d="M52 36 L47 46 L38 49" />
                <path d="M63 78 L74 84 L83 99" />
                <path d="M74 36 L79 43 L87 41 L88 31 L80 26 L73 30 Z" />
                <path d="M64 36 L72 51 L88 54 L96 40" />
              </g>
              <path class="crack-shard" d="M80 34 L66 30 L61 52 Z M80 34 L88 46 L72 47 Z" />
            </svg>
          </div>
          <div class="home" />
        </section>
      </div>
    </template>

    <div v-if="confirming" class="fixed inset-0 z-50 flex items-center justify-center bg-[rgba(6,8,10,0.7)] p-5" data-test="tutorial-confirm">
      <section class="card card-hi flex w-full max-w-sm flex-col gap-3" role="alertdialog" aria-modal="true" aria-labelledby="tutorial-skip-title" aria-describedby="tutorial-skip-text">
        <h2 id="tutorial-skip-title" class="ttl !text-2xl">{{ t('tutorial.skipTitle') }}</h2>
        <p id="tutorial-skip-text" class="text-sm text-muted">{{ t('tutorial.skipText') }}</p>
        <button ref="keepButton" type="button" class="btn btn-primary" data-test="tutorial-keep" @click="confirming = false">{{ t('tutorial.keep') }}</button>
        <button type="button" class="btn btn-secondary" :disabled="busy" data-test="tutorial-skip-confirm" @click="skip">{{ t('tutorial.skipConfirm') }}</button>
      </section>
    </div>
  </div>
</template>
