import type { DealerOffer, ExchangeListing, MarketResponse, MarketVehicle } from '@/api/types'

import { NOW } from './garageFixtures'

export function aVehicle(overrides: Partial<MarketVehicle> = {}): MarketVehicle {
  return { slug: 'rdzak-gt-3', name: 'Rdzak GT-3', class: 'hatchback', drivetrain: 'fwd', engine: 'R4 1.6', power_kw: 86, top_speed_kmh: 168, mass_kg: 960, ...overrides }
}

/** A car of the exchange that nobody has inspected: the Rdzak GT-3 of the board of the exchange, $3 620. */
export function aListing(overrides: Partial<ExchangeListing> = {}): ExchangeListing {
  return {
    key: 'zlomowo.995940.0.aaaaaaaa',
    vehicle: aVehicle(),
    age_years: 14,
    mileage_km: 142_000,
    word: 'good',
    price_cents: 362_000,
    inspected: false,
    inspection_cost_cents: 10_900,
    condition: null,
    ...overrides,
  }
}

export function anOffer(overrides: Partial<DealerOffer> = {}): DealerOffer {
  return { vehicle: aVehicle({ slug: 'rdzak-gt-2', name: 'Rdzak GT-2', engine: 'R4 1.3', power_kw: 65, top_speed_kmh: 150, mass_kg: 880 }), price_cents: 1_550_000, level_required: 1, open: true, ...overrides }
}

/**
 * The market of Złomowo for a driver of level 3: three cars on the exchange (one inspected, with three defects), a dealer with one car that is
 * open and two that are not, and two wrecks. The exchange is refreshed in 18 minutes and 20 seconds.
 */
export function aMarket(data: Partial<MarketResponse['data']> = {}, cashCents = 600_000, nowMs = NOW): MarketResponse {
  return {
    data: {
      city: { slug: 'zlomowo', name: 'Złomowo' },
      level: 3,
      garage: { owned: true, capacity: 3, used: 1, free: 2 },
      exchange: {
        refresh_ms: 1_800_000,
        next_refresh_ms: NOW + 1_100_000,
        listings: [
          aListing(),
          aListing({ key: 'zlomowo.995940.1.bbbbbbbb', vehicle: aVehicle({ slug: 'kornet-v6', name: 'Kornet V6', class: 'coupe', engine: 'V6 2.5', power_kw: 160, top_speed_kmh: 210, mass_kg: 1380 }), age_years: 10, mileage_km: 96_000, word: 'fair', price_cents: 814_000, inspection_cost_cents: 24_400 }),
          aListing({
            key: 'zlomowo.995940.2.cccccccc',
            vehicle: aVehicle({ slug: 'furgon-z-1', name: 'Furgon Z-1', class: 'van', engine: 'R4 2.0', power_kw: 81, top_speed_kmh: 130, mass_kg: 1750 }),
            age_years: 11,
            mileage_km: 231_000,
            word: 'poor',
            price_cents: 344_000,
            inspected: true,
            inspection_cost_cents: null,
            condition: { percent: 40, defects: 3 },
          }),
        ],
      },
      dealer: [
        anOffer(),
        anOffer({ vehicle: aVehicle({ slug: 'rdzak-gt-5', name: 'Rdzak GT-5', engine: 'R4 1.6', power_kw: 92, top_speed_kmh: 175, mass_kg: 1020 }), price_cents: 2_450_000, level_required: 6, open: false }),
        anOffer({ vehicle: aVehicle({ slug: 'kornet-v6', name: 'Kornet V6', class: 'coupe', engine: 'V6 2.5', power_kw: 160, top_speed_kmh: 210, mass_kg: 1380 }), price_cents: 3_000_000, level_required: 9, open: false }),
      ],
      wrecks: [
        { slug: 'rdzak-gt-4', name: 'Rdzak GT-4', class: 'hatchback', price_cents: 114_000 },
        { slug: 'bizon-m-2', name: 'Bizon M-2', class: 'muscle', price_cents: 139_000 },
      ],
      ...data,
    },
    wallet: { cash_cents: cashCents, xp: 1_000 },
    now_ms: nowMs,
  }
}
