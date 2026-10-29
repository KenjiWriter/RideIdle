import { describe, expect, it } from 'vitest'

import type { GarageSlot } from '@/api/types'
import { DEFAULT_MODEL, roleOf as roleIn } from '@/model'
import { aMountedPart } from '@/test/garageFixtures'

import { DEFAULT_LAYOUT, ICONS, LAYOUTS, iconOf, layoutOf, slotState, spotKeyOf, splitSlots } from './hotspots'

const roleOf = (slot: string) => roleIn(DEFAULT_MODEL, slot)
const slot = (key: string, extra: Partial<GarageSlot> = {}): GarageSlot => ({ slot: key, part: null, built_in: false, ...extra })

describe('the places of the parts on a picture', () => {
  it('has a place for every part of the car game on every picture the app brings, inside the picture', () => {
    const keys = ['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank']

    for (const layout of [...Object.values(LAYOUTS), DEFAULT_LAYOUT]) {
      expect(Object.keys(layout).sort()).toEqual([...keys].sort())

      for (const spot of Object.values(layout)) {
        expect(spot!.x).toBeGreaterThan(5)
        expect(spot!.x).toBeLessThan(95)
        expect(spot!.y).toBeGreaterThan(5)
        expect(spot!.y).toBeLessThan(95)
      }
    }
  })

  it('keeps the spots of a picture far enough from each other to be tapped one by one', () => {
    for (const [name, layout] of Object.entries(LAYOUTS)) {
      const spots = Object.entries(layout)

      for (const [i, [keyA, a]] of spots.entries()) {
        for (const [keyB, b] of spots.slice(i + 1)) {
          // On a stage 340 px wide and 227 px high, 8 % of it is about 27 px: a spot is 30 px, and it has a larger place to be tapped on.
          const distance = Math.hypot(((a!.x - b!.x) * 340) / 100, ((a!.y - b!.y) * 227) / 100)

          expect(distance, `${name}: ${keyA} and ${keyB}`).toBeGreaterThan(24)
        }
      }
    }
  })

  it('has the layout of the car where it has one, and the layout of the pictures of the game for any other', () => {
    expect(layoutOf('kuna-4x4')).toBe(LAYOUTS['kuna-4x4'])
    expect(layoutOf('bizon-m-2')).toBe(LAYOUTS['bizon-m-2'])
    expect(layoutOf('zubr-v8')).toBe(DEFAULT_LAYOUT)
  })
})

describe('what a slot is on the car', () => {
  it('is its role where it has one, so a slot that is called anything is the part it holds', () => {
    expect(spotKeyOf(slot('wheels', { role: 'tires' }), roleOf)).toBe('tires')
  })

  it('is the role that the model gives it, for a garage that does not say, and its own key where it has none', () => {
    expect(spotKeyOf(slot('brakes'), roleOf)).toBe('brakes')
    expect(spotKeyOf(slot('exhaust'), roleOf)).toBe('exhaust')
    expect(spotKeyOf(slot('flux-capacitor'), roleOf)).toBe('flux-capacitor')
  })
})

describe('splitting the slots into the ones with a place and the ones without', () => {
  const slots = [slot('tires'), slot('exhaust'), slot('flux-capacitor'), slot('wheels', { role: 'brakes' })]

  it('places the slots the layout knows, in the order of the slots, and leaves the rest', () => {
    const { placed, unplaced } = splitSlots(slots, DEFAULT_LAYOUT, roleOf)

    expect(placed.map((entry) => [entry.slot.slot, entry.key])).toEqual([
      ['tires', 'tires'],
      ['exhaust', 'exhaust'],
      ['wheels', 'brakes'],
    ])
    expect(placed[0]!.spot).toEqual(DEFAULT_LAYOUT.tires)
    expect(unplaced.map((entry) => entry.slot)).toEqual(['flux-capacitor'])
  })

  it('leaves every slot without a place when there is no picture to put them on', () => {
    const { placed, unplaced } = splitSlots(slots, {}, roleOf)

    expect(placed).toEqual([])
    expect(unplaced).toHaveLength(4)
  })
})

describe('the state of a slot', () => {
  it('is empty with no part on it, worn out under a fifth of the life of the part, and otherwise as it should be', () => {
    expect(slotState(slot('tires'))).toBe('empty')
    expect(slotState(slot('tires', { part: aMountedPart({ condition_percent: 100 }) }))).toBe('ok')
    expect(slotState(slot('tires', { part: aMountedPart({ condition_percent: 20 }) }))).toBe('ok')
    expect(slotState(slot('tires', { part: aMountedPart({ condition_percent: 19 }) }))).toBe('worn')
  })
})

describe('the icons', () => {
  it('are there for every part of the car game, and a dot in a ring for a part that is not', () => {
    for (const key of ['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank']) {
      expect(ICONS[key]!.length).toBeGreaterThan(0)
      expect(iconOf(key)).toBe(ICONS[key])
    }

    expect(iconOf('flux-capacitor')).not.toBe(ICONS.tires)
    expect(iconOf('flux-capacitor').length).toBeGreaterThan(0)
  })
})
