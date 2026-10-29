import { computed, shallowRef } from 'vue'

import type { CarStatKey } from '@/api/types'

/** What the formulas of the game know of a slot: the slot that has a role is that part of the car, whatever its key is. */
export type SlotRole = 'tires' | 'suspension' | 'brakes' | 'engine' | 'boost' | 'gearbox' | 'cooling' | 'tank'

export interface ModelSlot {
  key: string
  role: SlotRole | null
  /** A hidden slot can hold parts, but the garage does not list it. */
  hidden: boolean
}

/** The model of the game (GET /api/v1/meta/model): its slots in the order the screens list them, and the statistics of a car that they show, in their order. */
export interface GameModel {
  slots: ModelSlot[]
  stats: CarStatKey[]
}

/** The statistics that the formulas make: a model may list some of them, in any order. */
export const CAR_STATS: readonly CarStatKey[] = ['accel', 'top_speed', 'grip', 'handling', 'braking', 'clearance']

/** The model of the car game: what the app uses until the server says another, and when it cannot be asked. */
export const DEFAULT_MODEL: GameModel = {
  slots: [
    { key: 'tires', role: 'tires', hidden: false },
    { key: 'suspension', role: 'suspension', hidden: false },
    { key: 'brakes', role: 'brakes', hidden: false },
    { key: 'engine', role: 'engine', hidden: false },
    { key: 'boost', role: 'boost', hidden: false },
    { key: 'gearbox', role: 'gearbox', hidden: false },
    { key: 'exhaust', role: null, hidden: false },
    { key: 'cooling', role: 'cooling', hidden: false },
    { key: 'tank', role: 'tank', hidden: false },
    { key: 'ecu', role: null, hidden: true },
  ],
  stats: [...CAR_STATS],
}

const ROLES: readonly string[] = ['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'cooling', 'tank']

/** The keys of every slot, in order. */
export function slotKeys(model: GameModel): string[] {
  return model.slots.map((slot) => slot.key)
}

/** The keys of the slots that the garage lists, in order. */
export function visibleSlotKeys(model: GameModel): string[] {
  return model.slots.filter((slot) => !slot.hidden).map((slot) => slot.key)
}

/** The key of the slot that has the role, or null when the game has none. */
export function slotOf(model: GameModel, role: SlotRole): string | null {
  return model.slots.find((slot) => slot.role === role)?.key ?? null
}

/** The role of a slot, or null when it has none (or is not in the model). */
export function roleOf(model: GameModel, slot: string): SlotRole | null {
  return model.slots.find((candidate) => candidate.key === slot)?.role ?? null
}

/**
 * A model from what the server answered, or null when the answer is not a model (a client that is older or newer than the server must not break on it): the
 * slots have a key, a role of the known ones or none, and the statistics are the ones that the formulas make.
 */
export function parseModel(raw: unknown): GameModel | null {
  const body = raw as { slots?: unknown; stats?: unknown } | null

  if (!body || !Array.isArray(body.slots) || body.slots.length === 0 || !Array.isArray(body.stats)) {
    return null
  }

  const slots: ModelSlot[] = []

  for (const slot of body.slots as { key?: unknown; role?: unknown; hidden?: unknown }[]) {
    if (typeof slot?.key !== 'string' || slot.key === '') {
      return null
    }

    slots.push({ key: slot.key, role: typeof slot.role === 'string' && ROLES.includes(slot.role) ? (slot.role as SlotRole) : null, hidden: slot.hidden === true })
  }

  const stats = (body.stats as unknown[]).filter((stat): stat is CarStatKey => CAR_STATS.includes(stat as CarStatKey))

  return stats.length === 0 ? null : { slots, stats }
}

/** The model that the screens read. It is a module and not a store because it is known before the app is made: the boot waits for it, a moment at most. */
const current = shallowRef<GameModel>(DEFAULT_MODEL)

/** Puts a model in force (the one of the live pack), or the model of the car game again. */
export function setModel(model: GameModel = DEFAULT_MODEL): void {
  current.value = model
}

/** What a screen asks of the model: reactive, so that a screen shows the model that is in force. */
export function useModel() {
  return {
    model: computed(() => current.value),
    /** Every slot, in order. */
    slotKeys: computed(() => slotKeys(current.value)),
    /** The slots that the garage lists, in order. */
    visibleSlotKeys: computed(() => visibleSlotKeys(current.value)),
    /** The statistics that the screens show, in their order. */
    stats: computed(() => current.value.stats),
    slotOf: (role: SlotRole): string | null => slotOf(current.value, role),
    roleOf: (slot: string): SlotRole | null => roleOf(current.value, slot),
  }
}
