import type { GarageCar, GarageResponse, MergeOffer, MergeResult, MountedPart, OpenedCrate, OwnedPart, RepairNext, ServiceState, ShopCrates, ShopOffer, UpgradeNext, WorkshopJob } from '@/api/types'

/** The time of the server in the fixtures. */
export const NOW = 1_792_692_000_000

export function aPart(overrides: Partial<OwnedPart> = {}): OwnedPart {
  return {
    id: 11,
    slug: 'sport-exhaust-t2',
    name: 'Sport exhaust',
    slot: 'exhaust',
    tier: 2,
    price_cents: 47_500,
    stats: { power_mult: 1.02 },
    rarity: 'common',
    rarity_rank: 1,
    upgrade: 0,
    upgrade_max: 3,
    condition_percent: 100,
    mounted: false,
    car_id: null,
    ...overrides,
  }
}

/** What the next step of an upgrade is on a car of level 3: +1 for $199.50 and 4 min 54 s (the street tires of tier 2, as the server prices them). */
export function anUpgradeStep(overrides: Partial<UpgradeNext> = {}): UpgradeNext {
  return { to: 1, cost_cents: 19_950, duration_ms: 294_000, locked: null, ...overrides }
}

/** What putting a worn part right would cost and take: $142.50 and 1 min 57 s (street tires of tier 2 at half their life). A part that is not worn has nothing to put right. */
export function aRepairStep(overrides: Partial<RepairNext> = {}): RepairNext {
  return { cost_cents: 14_250, duration_ms: 117_600, locked: null, ...overrides }
}

/** The part is as good as new: there is nothing to repair. */
export const NOTHING_TO_REPAIR: RepairNext = { cost_cents: null, duration_ms: null, locked: { reason: 'fine' } }

/** The service of a car that has driven 1 580 km since the last one: 3 420 km to go, $45.60 and 14 min 42 s. */
export function aService(overrides: Partial<ServiceState> = {}): ServiceState {
  return { every_km: 5_000, since_km: 1_580, due_in_km: 3_420, overdue_km: 0, penalty_percent: 0, cost_cents: 4_560, duration_ms: 882_000, ...overrides }
}

/** What merging a part of the inventory would do: common to uncommon with the 80% and the 1 point of the workshop, $57.00 (a tenth of the model) and 7 min 50 s, with `with` the other parts it can go with. */
export function aMergeOffer(overrides: Partial<MergeOffer> = {}): MergeOffer {
  return { to: 'uncommon', chance_ppm: 810_000, pity_ppm: 0, cost_cents: 5_700, duration_ms: 470_400, scrap_cents: 14_250, with: [], ...overrides }
}

/** What became of an attempt of merging: a success by default, which made the uncommon street tires of tier 2. */
export function aMergeResult(overrides: Partial<MergeResult> = {}): MergeResult {
  return { id: 1, success: true, slot: 'tires', tier: 2, to: 'uncommon', name: 'Street tires T2', chance_ppm: 810_000, scrap_cents: 0, part: null, finished_ms: NOW - 60_000, ...overrides }
}

/** A part that sits on the car, with the next step of its upgrade. */
export function aMountedPart(overrides: Partial<MountedPart> = {}): MountedPart {
  return { ...aPart({ mounted: true, car_id: 5 }), upgrade_next: anUpgradeStep(), repair_next: NOTHING_TO_REPAIR, ...overrides }
}

export function anOffer(overrides: Partial<ShopOffer> = {}): ShopOffer {
  return { slug: 'sport-exhaust-t2', name: 'Sport exhaust', slot: 'exhaust', tier: 2, price_cents: 47_500, stats: { power_mult: 1.02 }, rarity: 'common', rarity_rank: 1, upgrade: 0, upgrade_max: 3, required_car_level: 3, mountable: true, ...overrides }
}

const SLOTS = ['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank']

export function aCar(overrides: Partial<GarageCar> = {}, mounted: Record<string, OwnedPart | MountedPart> = {}): GarageCar {
  return {
    id: 5,
    level: 3,
    // Level 3 runs from 600 to 1 200 XP, and this car is 410 into it, as on the board of the garage.
    xp: { total: 1_010, level_start: 600, next_level_at: 1_200 },
    tier_unlock: { tier: 3, level: 6 },
    vehicle: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4', engine: 'R4 1.8', power_kw: 97, drivetrain: 'fwd' },
    stats: { accel: 97, top_speed: 175, grip: 90, handling: 88, braking: 90, clearance: 20 },
    base_stats: { accel: 97, top_speed: 175, grip: 90, handling: 88, braking: 90, clearance: 20 },
    ceilings: { accel: 400, top_speed: 450, grip: 250, handling: 250, braking: 250, clearance: 200 },
    tank_ml: 45_000,
    mileage_km: 260_123,
    service: aService(),
    // One breakdown in about 1 000 laps (about 21 hours of laps of 75 s).
    breakdown: { chance_ppm: 1_000 },
    // A part that sits on the car comes with the next step of its upgrade and of its repair, as the server sends it.
    slots: SLOTS.map((slot) => ({ slot, part: mounted[slot] ? { upgrade_next: anUpgradeStep(), repair_next: NOTHING_TO_REPAIR, ...mounted[slot] } : null, built_in: slot === 'engine' })),
    lap_ms: { dry: 75_000 },
    run: { id: 7, status: 'running', stop_reason: null },
    ...overrides,
  }
}

export function aJob(overrides: Partial<WorkshopJob> = {}): WorkshopJob {
  return { id: 1, kind: 'mount', cost_cents: 0, upgrade_to: null, merge_with: null, merge_to: null, status: 'working', part: aPart(), start_ms: NOW - 60_000, finish_ms: NOW + 175_000, ...overrides }
}

export function aGarage(data: Partial<GarageResponse['data']> = {}, nowMs = NOW): GarageResponse {
  return {
    data: {
      car: aCar(),
      inventory: [],
      merges: [],
      workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [] },
      ...data,
    },
    now_ms: nowMs,
  }
}

/**
 * The crates for a driver of player level 3: the crate of a slot and the mixed one are open (tires and suspension of tier 2 for the first, and
 * the mixed one costs $1,043), the premium one needs level 6. The standard group is 4 crates from its guarantee and 130 from the legendary one.
 */
export function aCrates(overrides: Partial<ShopCrates> = {}): ShopCrates {
  const tires = { slot: 'tires', tier: 2, price_cents: 59_500, models: [{ slug: 'street-tires-t2', name: 'Street tires' }, { slug: 'offroad-tires-t2', name: 'Off-road tires' }] }
  const suspension = { slot: 'suspension', tier: 2, price_cents: 95_000, models: [{ slug: 'rally-suspension-t2', name: 'Rally suspension' }] }

  return {
    level: 3,
    groups: {
      standard: { odds: { common: 62, uncommon: 25, rare: 10, epic: 2.7, legendary: 0.3 }, every: 10, min_rarity: 'rare', until: 4, legendary_every: 150, legendary_until: 130 },
      premium: { odds: { common: 30, uncommon: 35, rare: 24, epic: 9, legendary: 2 }, every: 40, min_rarity: 'epic', until: 40, legendary_every: 150, legendary_until: 150 },
    },
    kinds: [
      { key: 'slot', group: 'standard', min_player_level: 1, open: true, choose_slot: true, price_cents: null, slots: [tires, suspension] },
      { key: 'mixed', group: 'standard', min_player_level: 3, open: true, choose_slot: false, price_cents: 104_300, slots: [{ ...tires, price_cents: null }, { ...suspension, price_cents: null }] },
      { key: 'premium', group: 'premium', min_player_level: 6, open: false, choose_slot: true, price_cents: null, slots: [] },
    ],
    free_first: false,
    ...overrides,
  }
}

/** What a crate gave: epic street tires, which a car of level 3 can have mounted. */
export function anOpenedCrate(overrides: Partial<OpenedCrate> = {}): OpenedCrate {
  return {
    ...aPart({ id: 31, slug: 'street-tires-t2', name: 'Street tires', slot: 'tires', tier: 2, price_cents: 57_000, stats: { grip: 138, handling: 135.24, braking: 138 }, rarity: 'epic', rarity_rank: 4 }),
    cost_cents: 59_500,
    required_car_level: 3,
    mountable: true,
    ...overrides,
  }
}
