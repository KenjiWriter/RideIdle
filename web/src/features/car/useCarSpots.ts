import { computed, ref } from 'vue'

import type { GarageSlot } from '@/api/types'
import { useModel } from '@/model'

import { layoutOf, splitSlots } from './hotspots'

/** What a screen that puts the car on a stage knows of it: its slots, its slug (the layout of the spots depends on it) and whether the stage has a picture to put them on. */
export interface SpotCar {
  slots: GarageSlot[]
  slug: string
  hasPicture: boolean
}

/**
 * The parts of a car on its stage: the slots that have a place on the picture (the spots) and the ones that have none (a row under the stage), which slot is looked at,
 * and how many slots have a part on them. A car with no picture has no places, so all of its slots are in the row.
 */
export function useCarSpots(car: () => SpotCar | null) {
  const { roleOf } = useModel()

  const pickedSlot = ref<string | null>(null)
  const slots = computed(() => car()?.slots ?? [])
  const picked = computed(() => slots.value.find((slot) => slot.slot === pickedSlot.value) ?? null)
  const mounted = computed(() => slots.value.filter((slot) => slot.part).length)
  const parts = computed(() => {
    const current = car()

    return splitSlots(current?.slots ?? [], current?.hasPicture ? layoutOf(current.slug) : {}, roleOf)
  })

  /** A slot that is looked at is put away when it is chosen again. */
  function pick(slot: string): void {
    pickedSlot.value = pickedSlot.value === slot ? null : slot
  }

  function clear(): void {
    pickedSlot.value = null
  }

  return { pickedSlot, picked, mounted, parts, pick, clear }
}
