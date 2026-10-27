import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { respond, serveApi } from '@/test/fetchServer'
import { aStep, aTutorial } from '@/test/tutorialFixtures'

import { useRunStore } from './run'
import { useTutorialStore } from './tutorial'

const fetchMock = vi.fn()

describe('the tutorial store', () => {
  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    setActivePinia(createPinia())
  })

  it('shows the phone while the messages are not answered, and the ring alone after', async () => {
    const calls = serveApi(fetchMock, {
      'GET /api/v1/tutorial': () => respond(200, aTutorial()),
      'POST /api/v1/tutorial/reply': () => respond(200, aTutorial(aStep({ acknowledged: true }))),
    })
    const store = useTutorialStore()

    await store.load()
    expect(store.current?.key).toBe('refuel')
    expect(store.showing).toBe(true)
    expect(store.waiting).toBe(false)

    await store.reply()
    expect(store.showing).toBe(false)
    expect(store.waiting).toBe(true)
    expect(calls.map((call) => `${call.method} ${call.url}`)).toEqual(['GET /api/v1/tutorial', 'POST /api/v1/tutorial/reply'])
  })

  it('shows nothing when no step waits, and nothing once the tutorial is over', async () => {
    serveApi(fetchMock, { 'GET /api/v1/tutorial': () => respond(200, aTutorial(null)) })
    const store = useTutorialStore()

    await store.load()
    expect(store.current).toBeNull()
    expect(store.showing).toBe(false)

    serveApi(fetchMock, { 'GET /api/v1/tutorial': () => respond(200, aTutorial(aStep(), { status: 'skipped' })) })
    await store.load()
    expect(store.current).toBeNull()
  })

  it('leaves the tutorial when the driver skips', async () => {
    serveApi(fetchMock, { 'POST /api/v1/tutorial/skip': () => respond(200, aTutorial(null, { status: 'skipped', done: 1 })) })
    const store = useTutorialStore()

    await store.skip()

    expect(store.tutorial?.status).toBe('skipped')
    expect(store.showing).toBe(false)
  })

  it('keeps what the steps paid until it is said, and asks the run for the cash that arrived', async () => {
    serveApi(fetchMock, {
      'GET /api/v1/tutorial': () => respond(200, aTutorial(aStep(), { rewarded: [{ key: 'first-lap', cash_cents: 20_000, car_xp: 0, xp: 0, part: null, garage: null }, { key: 'refuel', cash_cents: 9_000, car_xp: 0, xp: 0, part: null, garage: null }] })),
    })
    const store = useTutorialStore()
    const refresh = vi.spyOn(useRunStore(), 'refresh').mockResolvedValue()

    await store.load()

    expect(store.reward).toEqual({ cashCents: 29_000, carXp: 0, xp: 0, parts: [], garages: [] })
    expect(refresh).toHaveBeenCalledTimes(1)

    store.acknowledgeReward()
    expect(store.reward).toBeNull()
  })

  it('keeps the experience the steps gave the car as well as the cash', async () => {
    serveApi(fetchMock, { 'POST /api/v1/tutorial/reply': () => respond(200, aTutorial(null, { rewarded: [{ key: 'rewards', cash_cents: 0, car_xp: 100, xp: 0, part: null, garage: null }] })) })
    vi.spyOn(useRunStore(), 'refresh').mockResolvedValue()
    const store = useTutorialStore()

    await store.reply()

    expect(store.reward).toEqual({ cashCents: 0, carXp: 100, xp: 0, parts: [], garages: [] })
  })

  it('keeps the experience of the driver, the parts and the garages that the steps gave, and asks the run for the experience', async () => {
    serveApi(fetchMock, {
      'POST /api/v1/tutorial/reply': () =>
        respond(
          200,
          aTutorial(null, {
            rewarded: [
              { key: 'repair', cash_cents: 3_000, car_xp: 0, xp: 0, part: 'street-tires-t2', garage: null },
              { key: 'locked', cash_cents: 0, car_xp: 0, xp: 1_160, part: null, garage: 'kruszyn' },
            ],
          }),
        ),
    })
    const refresh = vi.spyOn(useRunStore(), 'refresh').mockResolvedValue()
    const store = useTutorialStore()

    await store.reply()

    expect(store.reward).toEqual({ cashCents: 3_000, carXp: 0, xp: 1_160, parts: ['street-tires-t2'], garages: ['kruszyn'] })
    expect(refresh).toHaveBeenCalledTimes(1)
  })

  it('does not ask the run for anything when nothing was paid', async () => {
    serveApi(fetchMock, { 'GET /api/v1/tutorial': () => respond(200, aTutorial()) })
    const refresh = vi.spyOn(useRunStore(), 'refresh').mockResolvedValue()

    await useTutorialStore().load()

    expect(refresh).not.toHaveBeenCalled()
  })

  it('has been asked even when the server did not answer, so that a screen waiting for it does not wait for ever', async () => {
    serveApi(fetchMock, { 'GET /api/v1/tutorial': () => respond(500, { message: 'Server error' }) })
    const store = useTutorialStore()
    expect(store.asked).toBe(false)

    await expect(store.load()).rejects.toThrow()

    expect(store.asked).toBe(true)
    expect(store.tutorial).toBeNull()
  })

  it('forgets everything on reset', async () => {
    serveApi(fetchMock, { 'GET /api/v1/tutorial': () => respond(200, aTutorial()) })
    const store = useTutorialStore()
    await store.load()

    store.reset()

    expect(store.tutorial).toBeNull()
    expect(store.current).toBeNull()
    expect(store.asked).toBe(false)
  })
})
