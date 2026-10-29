import type { GarageSlot } from '@/api/types'
import type { SlotRole } from '@/model'

/** A place on the picture of a car, in percent of the picture: from the left and from the top. */
export interface Spot {
  x: number
  y: number
}

/** Where the parts are on a picture, by what the slot is on the car (its role, or its own key where it has none). */
export type SpotLayout = Partial<Record<string, Spot>>

/**
 * The pictures of the cars that the app brings are all drawn from the front left, a little above the hood, so a part is where it is on the car: the hood for the
 * engine and the booster, the grille for the cooling, the arch over the front wheel for the suspension, the tread of the front tire, the rear wheel for the brakes,
 * the sill for the gearbox, the rear of the body for the exhaust and the tank.
 */
const FRONT_LEFT_HATCH: SpotLayout = {
  tires: { x: 57, y: 80 },
  brakes: { x: 83, y: 61 },
  suspension: { x: 53, y: 62 },
  engine: { x: 34, y: 49 },
  boost: { x: 27, y: 43 },
  cooling: { x: 24, y: 59 },
  gearbox: { x: 68, y: 68 },
  exhaust: { x: 77, y: 73 },
  tank: { x: 80, y: 49 },
}

/** The layouts of the cars whose pictures the app brings, by slug of the car. */
export const LAYOUTS: Record<string, SpotLayout> = {
  'rdzak-gt-4': FRONT_LEFT_HATCH,
  'bizon-m-2': {
    tires: { x: 46, y: 73 },
    brakes: { x: 80, y: 58 },
    suspension: { x: 48, y: 56 },
    engine: { x: 39, y: 45 },
    boost: { x: 26, y: 47 },
    cooling: { x: 21, y: 55 },
    gearbox: { x: 65, y: 65 },
    exhaust: { x: 76, y: 68 },
    tank: { x: 73, y: 47 },
  },
  'kuna-4x4': {
    tires: { x: 55, y: 76 },
    brakes: { x: 77, y: 68 },
    suspension: { x: 53, y: 57 },
    engine: { x: 41, y: 40 },
    boost: { x: 31, y: 38 },
    cooling: { x: 26, y: 49 },
    gearbox: { x: 62, y: 66 },
    exhaust: { x: 70, y: 73 },
    tank: { x: 78, y: 50 },
  },
}

/** The layout of a picture that has none of its own (one a pack gives): the one of a car seen from the front left, which is how the pictures of the game are drawn. */
export const DEFAULT_LAYOUT: SpotLayout = FRONT_LEFT_HATCH

export function layoutOf(vehicleSlug: string): SpotLayout {
  return LAYOUTS[vehicleSlug] ?? DEFAULT_LAYOUT
}

/** What a slot is on the car: its role where it has one (a slot may be called anything), else its own key (the exhaust has no role). */
export function spotKeyOf(slot: GarageSlot, roleOf: (slot: string) => SlotRole | null): string {
  return slot.role ?? roleOf(slot.slot) ?? slot.slot
}

export interface PlacedSlot {
  slot: GarageSlot
  key: string
  spot: Spot
}

/**
 * The slots that have a place on the picture, with their place, and the ones that have none (a slot of another game, or every slot of a car that has no picture):
 * those are offered as a row under the stage, so no part is out of reach.
 */
export function splitSlots(slots: GarageSlot[], layout: SpotLayout, roleOf: (slot: string) => SlotRole | null): { placed: PlacedSlot[]; unplaced: GarageSlot[] } {
  const placed: PlacedSlot[] = []
  const unplaced: GarageSlot[] = []

  for (const slot of slots) {
    const key = spotKeyOf(slot, roleOf)
    const spot = layout[key]

    if (spot) {
      placed.push({ slot, key, spot })
    } else {
      unplaced.push(slot)
    }
  }

  return { placed, unplaced }
}

/** What a part is like, for the ring of its spot: none on the slot, worn out (under a fifth of its life), or as it should be. */
export function slotState(slot: GarageSlot): 'empty' | 'worn' | 'ok' {
  if (!slot.part) {
    return 'empty'
  }

  return slot.part.condition_percent < 20 ? 'worn' : 'ok'
}

/** A circle as the path of an icon. */
const circle = (cx: number, cy: number, r: number): string => `M${cx - r} ${cy}a${r} ${r} 0 1 0 ${2 * r} 0a${r} ${r} 0 1 0 ${-2 * r} 0`

/** The icons of the parts (24 x 24, drawn with a line), by what a slot is on the car. */
export const ICONS: Record<string, string[]> = {
  tires: [circle(12, 12, 9), circle(12, 12, 4), 'M12 3v2M12 19v2M3 12h2M19 12h2'],
  suspension: ['M12 3v2M12 19v2', 'M12 5l5 1.7-10 2.6 10 2.6-10 2.6 5 1.7'],
  brakes: [circle(12, 12, 9), circle(12, 12, 2), 'M17.5 6.5a8 8 0 0 1 0 11'],
  engine: ['M5 9h14v9H5z', 'M9 9V6h6v3', 'M19 12h2v3h-2M5 12H3v3h2'],
  boost: [circle(12, 12, 1.6), 'M12 10.4C12 6 15 4 18 5M13.6 12C18 12 20 15 19 18M12 13.6C12 18 9 20 6 19M10.4 12C6 12 4 9 5 6'],
  gearbox: [
    'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z',
    circle(12, 12, 3),
  ],
  exhaust: ['M3 14h11l3-3h4', 'M3 11v6', circle(19.5, 6.5, 1.2), circle(22, 3.5, 0.9)],
  cooling: ['M12 3v18', 'M4.2 7.5l15.6 9', 'M4.2 16.5l15.6-9', 'M9 4l3 2 3-2', 'M9 20l3-2 3 2'],
  tank: ['M12 3c4 5 6 8 6 11a6 6 0 0 1-12 0c0-3 2-6 6-11z'],
}

/** The icon of a slot that is none of the above (a part of another game): a dot in a ring. */
export const OTHER_ICON: string[] = [circle(12, 12, 8), circle(12, 12, 2.5)]

export const iconOf = (key: string): string[] => ICONS[key] ?? OTHER_ICON
