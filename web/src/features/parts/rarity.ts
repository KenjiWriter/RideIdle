import type { PartRarity } from '@/api/types'

/** The rarities from the most common to the rarest: the place of a rarity is its rank (1 to 5), the number of the filled squares. */
export const RARITIES: readonly PartRarity[] = ['common', 'uncommon', 'rare', 'epic', 'legendary']

export function rankOf(rarity: PartRarity): number {
  return RARITIES.indexOf(rarity) + 1
}
