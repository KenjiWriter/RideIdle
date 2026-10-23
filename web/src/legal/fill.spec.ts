import { describe, expect, it } from 'vitest'

import { fillLegal, placeholdersOf } from './fill'

const numbers = { minAge: 16, guestDays: 90 }

describe('fillLegal', () => {
  it('puts the numbers in', () => {
    expect(fillLegal('You must be at least {minAge} years old.', numbers)).toBe('You must be at least 16 years old.')
    expect(fillLegal('Deleted after {guestDays} days.', numbers)).toBe('Deleted after 90 days.')
  })

  it('fills a number every time it appears', () => {
    expect(fillLegal('{minAge} and again {minAge}', numbers)).toBe('16 and again 16')
  })

  it('leaves a name it does not know as it is, so that a typo shows', () => {
    expect(fillLegal('after {guestDay} days', numbers)).toBe('after {guestDay} days')
  })

  it('does not treat the characters of a message compiler as syntax', () => {
    expect(fillLegal('write to a@b.example | or | c@d.example {minAge}', numbers)).toBe('write to a@b.example | or | c@d.example 16')
  })

  it('leaves a text with nothing to fill alone', () => {
    expect(fillLegal('Nothing here.', numbers)).toBe('Nothing here.')
  })

  it('does not take a name that is only on the prototype of the numbers', () => {
    expect(fillLegal('{toString}', numbers)).toBe('{toString}')
  })
})

describe('placeholdersOf', () => {
  it('lists each name once, in the order it appears', () => {
    expect(placeholdersOf('{guestDays}, {minAge}, {guestDays}')).toEqual(['guestDays', 'minAge'])
    expect(placeholdersOf('none')).toEqual([])
  })
})
