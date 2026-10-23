import { describe, expect, it } from 'vitest'

import { levelOf, xpToReach } from './level'

describe('level of a driver', () => {
  it('follows the curve of the design: 45 * n ^ 2.3 XP in all to reach level n', () => {
    expect(xpToReach(2)).toBe(222)
    expect(xpToReach(3)).toBe(563)
    expect(xpToReach(10)).toBe(Math.round(45 * 10 ** 2.3))
  })

  it('starts a new driver at level 1 with the whole first level ahead', () => {
    expect(levelOf(0)).toEqual({ level: 1, into: 0, needed: 222 })
    expect(levelOf(221)).toEqual({ level: 1, into: 221, needed: 222 })
  })

  it('moves to the next level at the XP of the curve and counts from there', () => {
    expect(levelOf(222)).toEqual({ level: 2, into: 0, needed: 563 - 222 })
    expect(levelOf(300)).toEqual({ level: 2, into: 78, needed: 341 })
    expect(levelOf(563).level).toBe(3)
  })

  it('never goes backwards or fractional for odd input', () => {
    expect(levelOf(-5)).toEqual({ level: 1, into: 0, needed: 222 })
    expect(levelOf(222.9).level).toBe(2)
  })
})
