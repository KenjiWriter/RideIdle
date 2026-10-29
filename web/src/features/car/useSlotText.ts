import { useI18n } from 'vue-i18n'

import type { GarageSlot } from '@/api/types'

/** The words for the parts of a car: the name of a slot, and what is on it, as the screens of the garage say them. */
export function useSlotText() {
  const { t, te } = useI18n()

  /** The name of a slot in the language of the driver, or its key when the game gives it no word. */
  const slotName = (slot: string): string => (te(`slot.${slot}`) ? t(`slot.${slot}`) : slot)

  /** What is on a slot: the part, else the engine of the body where the slot is the engine, else the word for a part of the body, else the word for none. */
  const partName = (slot: GarageSlot, engine: string | null): string => (slot.part ? slot.part.name : slot.built_in ? (engine ?? t('workshop.builtIn')) : t('workshop.empty'))

  return { slotName, partName }
}
