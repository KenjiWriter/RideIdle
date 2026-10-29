import type { AutoRefillOffer, InfinityOffer, PremiumView, VipOffer } from '@/api/types'

/** The auto-refill as the rules of the repository sell it (packs of 1, 5 and 20 refills, a stock of at most 100), for a driver who has nothing of it. */
export function refillOffer(changes: Partial<AutoRefillOffer> = {}): AutoRefillOffer {
  return {
    enabled: true,
    preference: true,
    stock: 0,
    stock_cap: 100,
    packs: [
      { slug: 'refill-1', charges: 1, coins: 2 },
      { slug: 'refill-5', charges: 5, coins: 9 },
      { slug: 'refill-20', charges: 20, coins: 32 },
    ],
    ...changes,
  }
}

/** The VIP account as the rules sell it (30 days for 150 coins, 20% more cash and XP), for a driver who has none. */
export function vipOffer(changes: Partial<VipOffer> = {}): VipOffer {
  return { enabled: true, active: false, until_ms: null, remaining_ms: 0, days: 30, coins: 150, cash_bonus_ppm: 200_000, xp_bonus_ppm: 200_000, ...changes }
}

/** The pass of the endless refill as the rules sell it (30 days for 19.99), which cannot be bought in the game while there is no payment provider. */
export function passOffer(changes: Partial<InfinityOffer> = {}): InfinityOffer {
  return { enabled: true, active: false, until_ms: null, remaining_ms: 0, days: 30, price_minor: 1_999, purchasable: false, ...changes }
}

/** What GET /premium says of a driver who has 12 coins and nothing of the paid extras, with the changes. */
export function premiumView(changes: Partial<PremiumView> = {}): PremiumView {
  return {
    balance: 12,
    currency: { code: 'ridecoin', name: 'RideCoin' },
    price_currency: 'PLN',
    now_ms: Date.now(),
    autorefill: refillOffer(),
    vip: vipOffer(),
    infinity: passOffer(),
    ...changes,
  }
}
