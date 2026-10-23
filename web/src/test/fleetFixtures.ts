import type { FleetCar, FleetGarage, FleetResponse } from '@/api/types'

import { NOW } from './garageFixtures'

export function aFleetCar(overrides: Partial<FleetCar> = {}): FleetCar {
  return {
    id: 5,
    level: 3,
    selected: true,
    vehicle: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
    city: { slug: 'zlomowo', name: 'Złomowo' },
    run: { id: 7, status: 'running', stop_reason: null, laps_done: 12, fuel_ml: 31_000, tank_ml: 45_000, track: 'Złomowo Street Sprint' },
    travel: null,
    ...overrides,
  }
}

export function aFleetGarage(overrides: Partial<FleetGarage> = {}): FleetGarage {
  return { city: 'zlomowo', name: 'Złomowo', level_required: 1, open: true, owned: true, capacity: 3, used: 1, free: 2, price_cents: null, ...overrides }
}

/** Two cars in two cities, a garage to buy in a third, and a yard of two wrecks. */
export function aFleet(data: Partial<FleetResponse['data']> = {}, cashCents = 600_000, nowMs = NOW): FleetResponse {
  return {
    data: {
      selected: 5,
      cars: [
        aFleetCar(),
        aFleetCar({
          id: 6,
          level: 4,
          selected: false,
          vehicle: { slug: 'bizon-m-2', name: 'Bizon M-2' },
          city: { slug: 'kruszyn', name: 'Kruszyn' },
          run: { id: 8, status: 'stopped', stop_reason: 'fuel_empty', laps_done: 3, fuel_ml: 0, tank_ml: 60_000, track: 'Kruszyn Quarry' },
        }),
      ],
      garages: [
        aFleetGarage(),
        aFleetGarage({ city: 'kruszyn', name: 'Kruszyn', level_required: 3 }),
        aFleetGarage({ city: 'autodrom', name: 'Autodrome', level_required: 12, open: false, owned: false, used: 0, free: 0, price_cents: 500_000 }),
      ],
      yard: [
        { slug: 'rdzak-gt-4', name: 'Rdzak GT-4', class: 'hatchback', price_cents: 114_000 },
        { slug: 'bizon-m-2', name: 'Bizon M-2', class: 'pickup', price_cents: 139_000 },
      ],
      ...data,
    },
    wallet: { cash_cents: cashCents, xp: 1_000 },
    now_ms: nowMs,
  }
}
