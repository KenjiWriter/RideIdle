<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { PartInfo, SurfaceKey } from '@/api/types'
import { useUnits } from '@/game/useUnits'
import { useFormat } from '@/i18n/useFormat'

const props = defineProps<{ part: Pick<PartInfo, 'stats' | 'surfaces' | 'requires' | 'engine'> }>()

const { t, te } = useI18n()
const { fixed } = useFormat()
const { speed } = useUnits()

/** The surfaces in the order the matrix of the rules lists them. */
const SURFACES: SurfaceKey[] = ['asphalt_dry', 'asphalt_wet', 'gravel_dry', 'mud', 'snow']

/** The statistics that say more or less than nothing, shown with their sign: the load of the engine, the mass in kilograms, the launch in percent. */
const SIGNED = new Set(['engine_load', 'mass_kg', 'launch'])

/** The statistics that are nothing worth a line at 0: a booster that answers at once has no lag, a gearbox with an ordinary launch has none to speak of. */
const QUIET_AT_ZERO = new Set(['lag', 'launch'])

/** A number to a tenth, with its sign when it has one to show (the minus is the real one). */
function signed(value: number): string {
  const shown = Math.round(value * 10) / 10

  return shown > 0 ? `+${shown}` : shown < 0 ? `\u2212${Math.abs(shown)}` : '0'
}

/** What the part does, in words: "Power +2%", "Grip 100". A statistic the messages do not know is shown as it is. An engine says what it is first: "R6 3.0". */
const lines = computed(() => [
  ...(props.part.engine ? [`${props.part.engine.layout} ${props.part.engine.displacement_l.toFixed(1)}`] : []),
  ...Object.entries(props.part.stats)
    .filter(([key, value]) => !(QUIET_AT_ZERO.has(key) && value === 0))
    .map(([key, value]) => {
      // A rarity multiplies the statistics, so 98 can be 135.24 and a power gain of 2% can be 2.4%: shown to a tenth.
      // The factors (power of a booster is a gain, its fuel a multiplier) and the gain of an intercooler (a fraction) are shown the way the design writes them.
      const shown =
        key === 'power_mult' ? Math.round((value - 1) * 1000) / 10
        : key === 'power_kw' ? Math.round(value)
        : key === 'boost_gain' ? Math.round(value * 1000) / 10
        : key === 'fuel_mult' ? fixed(value, 2)
        : SIGNED.has(key) ? signed(value)
        : Math.round(value * 10) / 10

      // The limit of a gearbox is a speed (km/h for the server): the message gets it in the unit of the game, and the number as it is for a vocabulary that wrote its own.
      const named = key === 'top_speed_cap_kmh' ? { value: shown, speed: speed(value) } : { value: shown }

      return te(`part.stat.${key}`) ? t(`part.stat.${key}`, named) : `${key} ${shown}`
    }),
])

/** What the car must have for the part, in words, or null when it needs nothing. */
const requires = computed(() => {
  const needs = props.part.requires

  if (!needs) {
    return null
  }

  const words = []

  if (needs.min_cylinders) {
    words.push(t('part.requires.cylinders', { count: needs.min_cylinders }))
  }

  if (needs.booster) {
    words.push(t('part.requires.booster'))
  }

  return words.length > 0 ? words.join(' · ') : null
})

/** Tires only: how they grip on each surface, the thing that tells one kind from another. The surfaces they do best on are brighter. */
const surfaces = computed(() => {
  const grip = props.part.surfaces

  return grip
    ? SURFACES.map((surface) => ({
        key: surface,
        text: t('part.surfaceLine', { surface: t(`part.surface.${surface}`), value: fixed(grip[surface], 2) }),
        good: grip[surface] >= 1,
      }))
    : []
})
</script>

<template>
  <ul class="flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-faint" data-test="part-stats">
    <li v-for="line in lines" :key="line">{{ line }}</li>
  </ul>
  <p v-if="requires" class="mt-1 font-mono text-[11px] text-faint" data-test="part-requires">{{ requires }}</p>
  <ul v-if="surfaces.length" class="mt-1 flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-faint" data-test="part-surfaces">
    <li v-for="surface in surfaces" :key="surface.key" :class="{ 'text-cyan': surface.good }" :data-test="`surface-${surface.key}`">{{ surface.text }}</li>
  </ul>
</template>
