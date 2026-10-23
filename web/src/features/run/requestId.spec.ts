import { afterEach, describe, expect, it, vi } from 'vitest'

import { newRequestId } from './requestId'

describe('newRequestId', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('makes a different id every time, short enough for the server (64 characters)', () => {
    const ids = new Set(Array.from({ length: 50 }, () => newRequestId()))

    expect(ids.size).toBe(50)
    expect([...ids].every((id) => id.length > 0 && id.length <= 64)).toBe(true)
  })

  it('still works where randomUUID does not exist, as on plain http', () => {
    vi.stubGlobal('crypto', { getRandomValues: crypto.getRandomValues.bind(crypto) })

    const ids = new Set(Array.from({ length: 50 }, () => newRequestId()))

    expect(ids.size).toBe(50)
    expect([...ids].every((id) => /^[0-9a-f]{32}$/.test(id))).toBe(true)
  })
})
