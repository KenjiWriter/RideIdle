import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { DEFAULT_MODEL, parseModel, roleOf, setModel, slotKeys, slotOf, useModel, visibleSlotKeys, type GameModel } from './index'
import { loadModel } from './load'

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

/** A game about ships: the tires are the hull, there is no booster, and the garage hides the rudder. */
const ships: GameModel = {
  slots: [
    { key: 'hull', role: 'tires', hidden: false },
    { key: 'reactor', role: 'engine', hidden: false },
    { key: 'sails', role: null, hidden: false },
    { key: 'rudder', role: null, hidden: true },
  ],
  stats: ['top_speed', 'grip'],
}

afterEach(() => setModel())

describe('the model of the car game', () => {
  it('is what the app has until the server says another: the slots in the order of the garage, and the six statistics', () => {
    expect(slotKeys(DEFAULT_MODEL)).toEqual(['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank', 'ecu'])
    expect(visibleSlotKeys(DEFAULT_MODEL)).toEqual(['tires', 'suspension', 'brakes', 'engine', 'boost', 'gearbox', 'exhaust', 'cooling', 'tank'])
    expect(DEFAULT_MODEL.stats).toEqual(['accel', 'top_speed', 'grip', 'handling', 'braking', 'clearance'])
    expect(useModel().model.value).toBe(DEFAULT_MODEL)
  })

  it('has a role for every slot but the exhaust and the hidden one', () => {
    expect(slotOf(DEFAULT_MODEL, 'tires')).toBe('tires')
    expect(slotOf(DEFAULT_MODEL, 'gearbox')).toBe('gearbox')
    expect(roleOf(DEFAULT_MODEL, 'exhaust')).toBeNull()
    expect(roleOf(DEFAULT_MODEL, 'ecu')).toBeNull()
    expect(roleOf(DEFAULT_MODEL, 'not-a-slot')).toBeNull()
  })
})

describe('a model of another game', () => {
  it('finds a slot by its role, and the role of a slot, whatever the slot is called', () => {
    expect(slotOf(ships, 'tires')).toBe('hull')
    expect(slotOf(ships, 'engine')).toBe('reactor')
    expect(slotOf(ships, 'boost')).toBeNull()
    expect(roleOf(ships, 'hull')).toBe('tires')
    expect(roleOf(ships, 'sails')).toBeNull()
    expect(roleOf(ships, 'tires')).toBeNull()
  })

  it('lists every slot, and the ones the garage shows without the hidden ones', () => {
    expect(slotKeys(ships)).toEqual(['hull', 'reactor', 'sails', 'rudder'])
    expect(visibleSlotKeys(ships)).toEqual(['hull', 'reactor', 'sails'])
  })

  it('is what the screens read once it is in force, and the model of the car game again when it is taken out', () => {
    const model = useModel()

    setModel(ships)

    expect(model.slotKeys.value).toEqual(['hull', 'reactor', 'sails', 'rudder'])
    expect(model.visibleSlotKeys.value).toEqual(['hull', 'reactor', 'sails'])
    expect(model.stats.value).toEqual(['top_speed', 'grip'])
    expect(model.slotOf('tires')).toBe('hull')
    expect(model.roleOf('hull')).toBe('tires')

    setModel()

    expect(model.slotKeys.value).toContain('tires')
    expect(model.slotOf('tires')).toBe('tires')
  })
})

describe('parseModel', () => {
  it('takes what the server sends: the slots with their roles, the statistics that the formulas make', () => {
    const model = parseModel({
      slots: [
        { key: 'hull', role: 'tires', hidden: false },
        { key: 'sails', role: null },
        { key: 'rudder', role: 'a role of a newer server', hidden: true },
      ],
      stats: ['grip', 'a statistic of a newer server', 'accel'],
    })

    expect(model).toEqual({
      slots: [
        { key: 'hull', role: 'tires', hidden: false },
        { key: 'sails', role: null, hidden: false },
        { key: 'rudder', role: null, hidden: true },
      ],
      stats: ['grip', 'accel'],
    })
  })

  it.each([
    ['nothing', null],
    ['a list', []],
    ['no slots', { slots: [], stats: ['grip'] }],
    ['a slot without a key', { slots: [{ role: 'tires' }], stats: ['grip'] }],
    ['no statistics that are known', { slots: [{ key: 'hull' }], stats: ['sails'] }],
    ['statistics that are not a list', { slots: [{ key: 'hull' }], stats: 'grip' }],
  ])('is not a model: %s', (_what, answer) => {
    expect(parseModel(answer)).toBeNull()
  })
})

describe('loadModel', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('puts the model of the game in force', async () => {
    fetchMock.mockResolvedValue(respond(200, ships))

    await loadModel()
    await flushPromises()

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/meta/model')
    expect(useModel().slotOf('tires')).toBe('hull')
    expect(useModel().stats.value).toEqual(['top_speed', 'grip'])
  })

  it.each([
    ['the server fails', () => fetchMock.mockResolvedValue(respond(500, { message: 'down' }))],
    ['the network fails', () => fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))],
    ['the answer is not a model', () => fetchMock.mockResolvedValue(respond(200, { slots: 'many' }))],
  ])('leaves the model of the car game when %s', async (_what, arrange) => {
    arrange()

    await loadModel()

    expect(useModel().model.value).toBe(DEFAULT_MODEL)
  })

  it('does not wait for a server that does not answer', async () => {
    fetchMock.mockImplementation((_url: string, init: RequestInit) => new Promise((_resolve, reject) => init.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')))))

    await loadModel(20)

    expect(useModel().model.value).toBe(DEFAULT_MODEL)
  })
})
