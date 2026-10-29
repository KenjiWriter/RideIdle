import { computed } from 'vue'

import { useGarageStore } from '@/stores/garage'
import { useMapStore } from '@/stores/map'
import { usePremiumStore } from '@/stores/premium'

/**
 * The premium coins of the driver for the bar at the top: what the newest answer says, whichever call it came from (the garage and the map carry the balance with
 * their answers, as every screen that spends coins needs it), or null until one has. `ensure` asks for the premium shop once in the session: the bar at the top shows
 * the badges of the paid extras next to the coins, and the coins when no other answer has said them.
 */
export function usePremiumCoins() {
  const garage = useGarageStore()
  const map = useMapStore()
  const premium = usePremiumStore()

  const coins = computed<number | null>(() => {
    const newest = [
      { coins: garage.premiumCoins, at: garage.premiumSeenAt },
      { coins: map.premiumCoins, at: map.premiumSeenAt },
      { coins: premium.balance, at: premium.seenAt },
    ].reduce((best, source) => (source.at > best.at ? source : best))

    return newest.at > 0 ? newest.coins : null
  })

  async function ensure(): Promise<void> {
    await premium.ensure()
  }

  return { coins, ensure }
}
