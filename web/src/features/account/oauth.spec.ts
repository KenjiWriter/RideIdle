import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { clearPending, readPending, storePending } from './oauth'

describe('the sign-in with a provider that this browser started', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('is remembered with its verifier', () => {
    storePending('google', 'verifier-123')

    expect(readPending()).toMatchObject({ provider: 'google', verifier: 'verifier-123' })
  })

  it('is not there when nothing was started', () => {
    expect(readPending()).toBeNull()
  })

  it('is forgotten on request', () => {
    storePending('apple', 'verifier-123')

    clearPending()

    expect(readPending()).toBeNull()
  })

  it('is replaced by the one started later', () => {
    storePending('google', 'first')
    storePending('facebook', 'second')

    expect(readPending()).toMatchObject({ provider: 'facebook', verifier: 'second' })
  })

  it('is forgotten after a quarter of an hour, because the server forgot it long before', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T12:00:00Z'))
    storePending('google', 'verifier-123')

    vi.setSystemTime(new Date('2026-10-23T12:15:00Z'))
    expect(readPending()).not.toBeNull()

    vi.setSystemTime(new Date('2026-10-23T12:15:01Z'))
    expect(readPending()).toBeNull()
  })

  it('does not trust what is not a sign-in', () => {
    localStorage.setItem('ride.oauth', 'not json')
    expect(readPending()).toBeNull()

    localStorage.setItem('ride.oauth', JSON.stringify({ provider: 'google' }))
    expect(readPending()).toBeNull()
  })

  it('works without storage, only it cannot be remembered', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })

    expect(() => storePending('google', 'verifier-123')).not.toThrow()
    expect(readPending()).toBeNull()
  })
})
