import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { respond, serveApi } from '@/test/fetchServer'
import { aMainMission, aMission, aSet, missionsView } from '@/test/missionsFixtures'

import { useMissionsStore } from './missions'

const fetchMock = vi.fn()

describe('the missions store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    storeToken('device-token')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('keeps what the server says of the missions, and the number that can be taken', async () => {
    serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(200, { data: missionsView() }) })
    const store = useMissionsStore()

    await store.load()

    expect(store.available).toBe(true)
    expect(store.claimable).toBe(1)
    expect(Object.keys(store.periods ?? {})).toEqual(['daily', 'weekly', 'monthly'])
    expect(store.main[0].missions[0].key).toBe('driver-laps-50')
  })

  it('asks once for the bar at the top, and not again when the game has no missions', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(200, { data: { available: false, now_ms: 1 } }) })
    const store = useMissionsStore()

    await store.ensure()
    await store.ensure()

    expect(calls.filter((call) => call.url === '/api/v1/missions')).toHaveLength(1)
    expect(store.available).toBe(false)
    expect(store.claimable).toBe(0)
  })

  it('makes one call of two screens that ask together', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(200, { data: missionsView() }) })
    const store = useMissionsStore()

    await Promise.all([store.load(), store.load()])

    expect(calls).toHaveLength(1)
  })

  it('tells the missions that turned done since it last looked, and says nothing of the first answer', async () => {
    const open = missionsView({ claimable: 0, periods: { ...missionsView().periods!, daily: aSet([aMission({ id: 1 }), aMission({ id: 2, slot: 2 })]) } })
    const next = missionsView({ periods: { ...open.periods!, daily: aSet([aMission({ id: 1, status: 'completed', progress: 12 }), aMission({ id: 2, slot: 2 })]) } })
    let answer = open
    serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(200, { data: answer }) })
    const store = useMissionsStore()

    await store.load()
    expect(store.justDone).toEqual([])

    answer = next
    await store.load()

    expect(store.justDone.map((mission) => mission.id)).toEqual([1])

    store.clearDone()
    answer = next
    await store.load()
    expect(store.justDone).toEqual([])
  })

  it('shows the main mission that is done first, else the first that is open', async () => {
    const view = missionsView({ main: [{ ...missionsView().main![0], missions: [aMainMission({ id: 41, status: 'open' })] }, { ...missionsView().main![0], key: 'mechanic', missions: [aMainMission({ id: 42, status: 'completed', progress: 50 })] }] })
    serveApi(fetchMock, { 'GET /api/v1/missions': () => respond(200, { data: view }) })
    const store = useMissionsStore()

    await store.load()

    expect(store.goalMission?.id).toBe(42)
  })

  it('takes a mission, keeps the answer and tells what was given', async () => {
    const after = missionsView({ claimable: 0 })
    const calls = serveApi(fetchMock, {
      'POST /api/v1/missions/1/claim': () => respond(200, { data: after, repeated: false, claimed: { missions: [aMission({ id: 1, status: 'claimed' })], cash_cents: 600, xp: 4, car_xp: 0, crates: [], parts: [] } }),
    })
    const store = useMissionsStore()

    const claimed = await store.claim(1)

    expect(calls[0]).toMatchObject({ method: 'POST', url: '/api/v1/missions/1/claim' })
    expect(claimed?.cash_cents).toBe(600)
    expect(store.claimable).toBe(0)
    expect(store.lastClaim?.claimed?.xp).toBe(4)
  })

  it('takes nothing the second time, and says so', async () => {
    serveApi(fetchMock, { 'POST /api/v1/missions/1/claim': () => respond(200, { data: missionsView(), repeated: true, claimed: { missions: [], cash_cents: 600, xp: 4, car_xp: 0, crates: [], parts: [] } }) })
    const store = useMissionsStore()

    expect(await store.claim(1)).toBeNull()
  })

  it('takes the bonus of a set and returns the coins, none when it had been taken', async () => {
    let repeated = false
    serveApi(fetchMock, { 'POST /api/v1/missions/daily/bonus': () => respond(200, { data: missionsView(), repeated, bonus_coins: 2 }) })
    const store = useMissionsStore()

    expect(await store.claimBonus('daily')).toBe(2)

    repeated = true
    expect(await store.claimBonus('daily')).toBe(0)
  })

  it('takes every mission of a kind with one request', async () => {
    const calls = serveApi(fetchMock, { 'POST /api/v1/missions/claim': () => respond(200, { data: missionsView({ claimable: 0 }), repeated: false, claimed: { missions: [], cash_cents: 0, xp: 0, car_xp: 0, crates: [], parts: [] } }) })
    const store = useMissionsStore()

    await store.claimAll('daily')

    expect(calls[0]).toMatchObject({ method: 'POST', body: { kind: 'daily' } })
  })
})
