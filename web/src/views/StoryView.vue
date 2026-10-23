<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import hero from '@/assets/art/garage-hero.jpg'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { useSessionStore } from '@/stores/session'

/**
 * The story that comes between the name and the choice of a wreck, as the storyboard of the canvas draws it: four
 * scenes of one picture, a camera that moves over it, the words of the mentor under it, and his first message in the
 * last. A tap moves on, "Skip" is there after three seconds, and the button of the choice after two seconds of the last
 * scene. For a driver who asks for less motion the camera stands still and nothing moves on by itself: the words are
 * always on the screen, and reading them has no time limit.
 */
const SCENES = [
  { key: 'scene1', ms: 6_000, position: '50% 55%', from: { scale: 1.2, x: '0%', y: '0%' }, to: { scale: 1.65, x: '-4%', y: '-3%' } },
  { key: 'scene2', ms: 6_000, position: '50% 70%', from: { scale: 1.45, x: '9%', y: '0%' }, to: { scale: 1.45, x: '-9%', y: '0%' } },
  { key: 'scene3', ms: 7_000, position: '78% 60%', from: { scale: 1.35, x: '0%', y: '0%' }, to: { scale: 1.6, x: '-5%', y: '-2%' } },
  { key: 'scene4', ms: 5_000, position: '50% 12%', from: { scale: 1.6, x: '0%', y: '0%' }, to: { scale: 1.35, x: '0%', y: '3%' } },
] as const

/** The number the first message comes from. The mentor is in hiding and changes it with every step. */
const SENDER = '+48 555 010 112'

const SKIP_AFTER_MS = 3_000
const CHOOSE_AFTER_MS = 2_000

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

useLeaveOnSignOut()

const scene = ref(0)
const canSkip = ref(false)
const canChoose = ref(false)

const reducedMotion = typeof window !== 'undefined' && (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false)
const last = SCENES.length - 1
const current = computed(() => SCENES[scene.value]!)
const name = computed(() => session.me?.name ?? '')

let sceneTimer: ReturnType<typeof setTimeout> | undefined
let skipTimer: ReturnType<typeof setTimeout> | undefined
let chooseTimer: ReturnType<typeof setTimeout> | undefined

function next(): void {
  if (scene.value < last) {
    scene.value += 1
  }
}

function leave(): void {
  void router.push({ name: 'choose' })
}

watch(
  scene,
  (index) => {
    clearTimeout(sceneTimer)
    clearTimeout(chooseTimer)

    if (index < last) {
      if (!reducedMotion) {
        sceneTimer = setTimeout(next, SCENES[index]!.ms)
      }

      return
    }

    // The choice is not hurried: it waits for the driver in the last scene, and appears after a moment.
    chooseTimer = setTimeout(() => (canChoose.value = true), reducedMotion ? 0 : CHOOSE_AFTER_MS)
  },
  { immediate: true },
)

onMounted(() => {
  skipTimer = setTimeout(() => (canSkip.value = true), reducedMotion ? 0 : SKIP_AFTER_MS)

  // The name on the sign is the name of the driver: it is known at once when the driver has just been created.
  if (!session.me) {
    void session.fetchMe().catch(() => undefined)
  }
})

onBeforeUnmount(() => {
  clearTimeout(sceneTimer)
  clearTimeout(skipTimer)
  clearTimeout(chooseTimer)
})

/** The motion of the camera of the scene, as variables of the stylesheet: from, to and for how long. */
const cameraStyle = computed(() => ({
  objectPosition: current.value.position,
  '--s0': current.value.from.scale,
  '--x0': current.value.from.x,
  '--y0': current.value.from.y,
  '--s1': current.value.to.scale,
  '--x1': current.value.to.x,
  '--y1': current.value.to.y,
  '--dur': `${current.value.ms}ms`,
}))
</script>

<template>
  <div class="mx-auto flex min-h-[calc(100svh-5rem)] w-full max-w-[480px] flex-col border-x border-line bg-bg/70" data-test="story">
    <div class="relative h-[56svh] min-h-[300px] flex-none overflow-hidden bg-[#0b0d11]">
      <!-- The whole picture is the button that moves on: a tap anywhere on it. -->
      <button type="button" class="absolute inset-0 block h-full w-full cursor-pointer border-0 bg-transparent p-0" :aria-label="t('story.next')" data-test="story-stage" @click="next">
        <img :key="scene" :src="hero" alt="" class="kb h-full w-full object-cover" :style="cameraStyle" />
      </button>

      <div v-if="scene === last" class="pointer-events-none absolute inset-x-0 top-[68px] flex justify-center px-6" data-test="story-sign">
        <div class="card card-hi !px-5 !py-2 text-center">
          <p class="kick !mb-0 !text-[10px]">{{ t('story.role') }}</p>
          <p class="font-display text-[28px] font-bold uppercase leading-none tracking-wide text-amber" data-test="story-name">{{ name }}</p>
        </div>
      </div>

      <div v-if="scene === last" class="sms-in pointer-events-none absolute inset-x-3.5 bottom-3.5 !max-w-none text-[13px]" data-test="story-sms">
        <b>{{ SENDER }}:</b> {{ t('story.sms') }}
      </div>

      <button v-if="canSkip" type="button" class="btn btn-secondary btn-sm absolute right-3.5 top-3.5 z-10" data-test="story-skip" @click="leave">
        {{ t('story.skip') }}
      </button>
    </div>

    <div class="flex flex-1 flex-col gap-3 px-5 py-4">
      <div class="flex items-center justify-between">
        <span class="tag tag-am">{{ t('story.scene', { number: scene + 1, total: SCENES.length }) }}</span>
        <div class="flex gap-1" aria-hidden="true">
          <i v-for="(item, index) in SCENES" :key="item.key" class="block h-1.5 w-6 rounded-sm" :class="index <= scene ? 'bg-amber' : 'bg-surface-3'" />
        </div>
      </div>

      <p class="font-display text-[26px] font-semibold leading-tight text-ink" aria-live="polite" data-test="story-text">
        {{ t(`story.${current.key}`, { name }) }}
      </p>

      <div class="flex-1" />

      <button v-if="scene === last && canChoose" type="button" class="btn btn-primary !min-h-14 !text-2xl" data-test="story-choose" @click="leave">
        {{ t('story.choose') }}
      </button>
      <p v-else-if="scene < last" class="sub text-center">{{ t('story.next') }}</p>
    </div>
  </div>
</template>
