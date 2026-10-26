import { describe, expect, it } from 'vitest'

import { levelOf, xpToReach } from './level'

describe('the level of a driver', () => {
  it('follows the table of the first levels: 60 XP for level 2, 800 for level 5, 12 800 for level 10', () => {
    expect(xpToReach(1)).toBe(0)
    expect(xpToReach(2)).toBe(60)
    expect(xpToReach(3)).toBe(180)
    expect(xpToReach(5)).toBe(800)
    expect(xpToReach(10)).toBe(12_800)
  })

  it('goes on a straight line from level 10 to the curve of the design at level 20, which the server draws too', () => {
    // The numbers of the rules of the game (Rules::playerXpToReach): a test of the server keeps them together.
    expect(xpToReach(15)).toBe(227_481)
    expect(xpToReach(20)).toBe(442_162)
    expect(xpToReach(20)).toBe(Math.round(450 * 20 ** 2.3))
    expect(xpToReach(30)).toBe(Math.round(450 * 30 ** 2.3))
  })

  it('is level 1 with nothing, and level 2 from 60 XP', () => {
    expect(levelOf(0)).toEqual({ level: 1, into: 0, needed: 60 })
    expect(levelOf(59)).toEqual({ level: 1, into: 59, needed: 60 })
  })

  it('counts the XP in the level and what the level takes, for the bar', () => {
    expect(levelOf(60)).toEqual({ level: 2, into: 0, needed: 120 })
    expect(levelOf(100)).toEqual({ level: 2, into: 40, needed: 120 })
    expect(levelOf(180).level).toBe(3)
    expect(levelOf(12_800).level).toBe(10)
  })

  it('does not go under zero, and rounds down what is not whole', () => {
    expect(levelOf(-5)).toEqual({ level: 1, into: 0, needed: 60 })
    expect(levelOf(60.9).level).toBe(2)
  })

  it('stops at the highest level, with a bar that is not empty', () => {
    const top = levelOf(Number.MAX_SAFE_INTEGER)

    expect(top.level).toBe(30)
    expect(top.needed).toBeGreaterThan(0)
  })
})
