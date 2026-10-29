import { ref } from 'vue'
import { describe, expect, it } from 'vitest'

import type { GarageSlot } from '@/api/types'
import { aMountedPart } from '@/test/garageFixtures'

import { LAYOUTS } from './hotspots'
import { useCarSpots, type SpotCar } from './useCarSpots'

const slot = (key: string, part: GarageSlot['part'] = null): GarageSlot => ({ slot: key, role: key, part, built_in: false })

const car = (overrides: Partial<SpotCar> = {}): SpotCar => ({
  slots: [slot('tires', aMountedPart({ slot: 'tires' })), slot('brakes'), slot('flux-capacitor')],
  slug: 'kuna-4x4',
  hasPicture: true,
  ...overrides,
})

describe('useCarSpots', () => {
  it('puts the slots that the layout of the picture knows on the picture and leaves the rest for the row', () => {
    const { parts } = useCarSpots(() => car())

    expect(parts.value.placed.map((entry) => entry.slot.slot)).toEqual(['tires', 'brakes'])
    expect(parts.value.placed[0]!.spot).toEqual(LAYOUTS['kuna-4x4']!.tires)
    expect(parts.value.unplaced.map((entry) => entry.slot)).toEqual(['flux-capacitor'])
  })

  it('has no places for a car that has no picture, and nothing at all before there is a car', () => {
    expect(useCarSpots(() => car({ hasPicture: false })).parts.value.placed).toEqual([])
    expect(useCarSpots(() => car({ hasPicture: false })).parts.value.unplaced).toHaveLength(3)

    const none = useCarSpots(() => null)

    expect(none.parts.value).toEqual({ placed: [], unplaced: [] })
    expect(none.picked.value).toBeNull()
    expect(none.mounted.value).toBe(0)
  })

  it('counts the slots that have a part on them', () => {
    expect(useCarSpots(() => car()).mounted.value).toBe(1)
  })

  it('looks at the slot that was picked, puts it away when it is picked again or cleared, and looks at another in its place', () => {
    const spots = useCarSpots(() => car())

    expect(spots.picked.value).toBeNull()

    spots.pick('tires')
    expect(spots.pickedSlot.value).toBe('tires')
    expect(spots.picked.value?.slot).toBe('tires')

    spots.pick('brakes')
    expect(spots.picked.value?.slot).toBe('brakes')

    spots.pick('brakes')
    expect(spots.picked.value).toBeNull()

    spots.pick('tires')
    spots.clear()
    expect(spots.pickedSlot.value).toBeNull()
  })

  it('follows the car: a slot that is gone with it is no longer looked at', () => {
    const current = ref<SpotCar | null>(car())
    const spots = useCarSpots(() => current.value)

    spots.pick('tires')
    current.value = car({ slots: [slot('brakes')] })

    expect(spots.picked.value).toBeNull()
    expect(spots.parts.value.placed.map((entry) => entry.slot.slot)).toEqual(['brakes'])
  })
})
