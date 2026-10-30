import type { Mission, MissionBonus, MissionChain, MissionSet, MissionsView, OwnedPart } from '@/api/types'

/** A mission of a set of the day: three laps, a quarter of the way, paying $6 and 4 XP. */
export function aMission(changes: Partial<Mission> = {}): Mission {
  return {
    id: 1,
    kind: 'daily',
    slot: 1,
    key: 'daily-laps',
    objective: 'laps',
    target: 12,
    progress: 3,
    status: 'open',
    reward: { cash_cents: 600, xp: 4, car_xp: 0, items: [] },
    auto_claimed: false,
    claimed_at_ms: null,
    ...changes,
  }
}

/** A main mission with words of its own. */
export function aMainMission(changes: Partial<Mission> = {}): Mission {
  return aMission({ id: 40, kind: 'main', key: 'driver-laps-50', chain: 'driver', title: 'The first 50 laps', text: 'Drive 50 laps in all.', objective: 'laps', target: 50, progress: 20, ...changes })
}

export function aSet(missions: Mission[], changes: Partial<MissionSet> = {}): MissionSet {
  return {
    key: '2026-10-30',
    from_ms: 1_793_000_000_000,
    to_ms: 1_793_086_400_000,
    resets_in_ms: 3 * 3_600_000 + 12 * 60_000,
    drawn: true,
    missions,
    done: missions.filter((mission) => mission.status !== 'open').length,
    total: missions.length,
    bonus: { coins: 2, state: 'locked' } as MissionBonus,
    ...changes,
  }
}

export function aChain(missions: Mission[], changes: Partial<MissionChain> = {}): MissionChain {
  return { key: 'driver', title: 'Driver', total: 8, done: missions.filter((mission) => mission.status !== 'open').length, taken: 0, missions, waiting: null, ...changes }
}

/**
 * What GET /missions says: a set of the day of three missions (one done), an empty week and month, and one main mission.
 * `claimable` counts the missions that are done and not taken, as the server does.
 */
export function missionsView(changes: Partial<MissionsView> = {}): MissionsView {
  const daily = aSet([aMission({ id: 1, status: 'completed', progress: 12 }), aMission({ id: 2, slot: 2, key: 'daily-cash', objective: 'cash_earned', target: 27_900, progress: 1_000 }), aMission({ id: 3, slot: 3, key: 'daily-service', objective: 'service', target: 1, progress: 0 })])

  return {
    available: true,
    now_ms: 1_793_000_100_000,
    locked: null,
    main_locked: null,
    claimable: 1,
    periods: {
      daily,
      weekly: aSet([aMission({ id: 10, kind: 'weekly', key: 'weekly-laps', target: 120, progress: 30 })], { key: '2026-W44', bonus: { coins: 8, state: 'locked' } }),
      monthly: aSet([aMission({ id: 20, kind: 'monthly', key: 'monthly-laps', target: 600, progress: 30 })], { key: '2026-10', bonus: { coins: 25, state: 'locked' } }),
    },
    main: [aChain([aMainMission()])],
    auto_collected: [],
    ...changes,
  }
}

/** A part as the shop shows one that was just opened. */
export function aPart(changes: Partial<OwnedPart> = {}): OwnedPart {
  return {
    id: 501,
    name: 'Street tires',
    slot: 'tires',
    tier: 2,
    rarity: 'rare',
    rarity_rank: 3,
    stats: { grip: 1.22 },
    ...changes,
  } as unknown as OwnedPart
}
