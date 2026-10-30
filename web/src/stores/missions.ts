import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api } from '@/api/client'
import type { Mission, MissionKind, MissionPeriodKind, MissionsClaimed, MissionsResponse, MissionsView } from '@/api/types'

/**
 * The missions of the driver: the sets of a day, a week and a month, and the main missions. The server decides what is done and what a set pays; this only asks,
 * keeps the last answer for the bar at the top (the number of missions that can be taken) and for the screen, and tells which missions turned done since it last looked,
 * so that the screen can say so. Taking a mission asks the server, which takes it once: a request that comes twice takes nothing the second time.
 */
export const useMissionsStore = defineStore('missions', () => {
  const view = ref<MissionsView | null>(null)
  /** Whether the server was asked: once is enough to know that a game has no missions, so it is not asked again on every screen. */
  const asked = ref(false)
  /** The missions that turned done since the driver last saw them said (a toast), newest last. */
  const justDone = ref<Mission[]>([])
  /** What the last call that took missions gave, until the screen has told it. */
  const lastClaim = ref<{ claimed: MissionsClaimed | null; bonusCoins: number } | null>(null)

  /** The game has missions and the driver has got far enough to have them. */
  const available = computed(() => view.value?.available === true)
  const claimable = computed(() => view.value?.claimable ?? 0)
  const periods = computed(() => view.value?.periods ?? null)
  const main = computed(() => view.value?.main ?? [])
  const collected = computed(() => view.value?.auto_collected ?? [])
  /** The reason the sets are not there yet (the tutorial), or null. */
  const locked = computed(() => view.value?.locked ?? null)

  /** The main mission the bar of the goal shows when the tutorial is over: one that is done and waits to be taken, else the first that is open. */
  const goalMission = computed<Mission | null>(() => {
    const all = main.value.flatMap((chain) => chain.missions)

    return all.find((mission) => mission.status === 'completed') ?? all.find((mission) => mission.status === 'open') ?? null
  })

  /** When the answer was read (the clock of this device): what is left of a set is worked out from it. */
  const seenAt = ref(0)

  /** The missions of the views by id and status, to tell what changed. */
  function statuses(of: MissionsView | null): Map<number, Mission['status']> {
    const found = new Map<number, Mission['status']>()

    for (const set of Object.values(of?.periods ?? {})) {
      set.missions.forEach((mission) => found.set(mission.id, mission.status))
    }

    for (const chain of of?.main ?? []) {
      chain.missions.forEach((mission) => found.set(mission.id, mission.status))
    }

    return found
  }

  function missionsOf(of: MissionsView): Mission[] {
    return [...Object.values(of.periods ?? {}).flatMap((set) => set.missions), ...(of.main ?? []).flatMap((chain) => chain.missions)]
  }

  function apply(next: MissionsView): void {
    const before = statuses(view.value)

    // A mission that was open the last time and is done now. The first answer says nothing: they were done before the driver came.
    if (view.value !== null) {
      const turned = missionsOf(next).filter((mission) => mission.status === 'completed' && before.get(mission.id) === 'open')

      justDone.value = [...justDone.value, ...turned]
    }

    view.value = next
    seenAt.value = Date.now()
  }

  /** The call that is on its way, so that two screens that ask together (the bar at the top and the missions) make one. */
  let pending: Promise<void> | null = null

  function load(): Promise<void> {
    asked.value = true
    pending ??= api<MissionsResponse>('/v1/missions')
      .then((response) => apply(response.data))
      .finally(() => {
        pending = null
      })

    return pending
  }

  /** Reads the missions once: the bar at the top shows how many can be taken on every screen. */
  async function ensure(): Promise<void> {
    if (view.value === null && !asked.value) {
      await load().catch(() => undefined)
    }
  }

  /** Takes the reward of one mission. */
  async function claim(id: number): Promise<MissionsClaimed | null> {
    const response = await api<MissionsResponse>(`/v1/missions/${id}/claim`, { method: 'POST' })

    return took(response)
  }

  /** Takes every mission of a kind that is done. */
  async function claimAll(kind: MissionKind | 'all'): Promise<MissionsClaimed | null> {
    const response = await api<MissionsResponse>('/v1/missions/claim', { method: 'POST', body: JSON.stringify({ kind }) })

    return took(response)
  }

  /** Takes the premium coins for having done every mission of a set. Returns the coins. */
  async function claimBonus(kind: MissionPeriodKind): Promise<number> {
    const response = await api<MissionsResponse>(`/v1/missions/${kind}/bonus`, { method: 'POST' })

    took(response)

    return response.repeated ? 0 : (response.bonus_coins ?? 0)
  }

  function took(response: MissionsResponse): MissionsClaimed | null {
    apply(response.data)
    lastClaim.value = { claimed: response.repeated ? null : (response.claimed ?? null), bonusCoins: response.repeated ? 0 : (response.bonus_coins ?? 0) }

    return lastClaim.value.claimed
  }

  /** The toast has said it. */
  function clearDone(): void {
    justDone.value = []
  }

  function clearClaim(): void {
    lastClaim.value = null
  }

  return { view, asked, justDone, lastClaim, available, claimable, periods, main, collected, locked, goalMission, seenAt, apply, load, ensure, claim, claimAll, claimBonus, clearDone, clearClaim }
})
