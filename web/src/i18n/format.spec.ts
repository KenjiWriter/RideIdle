import { describe, expect, it } from 'vitest'

import { formatDay, formatDuration, formatFixed, formatLapTime, formatMoney, formatNumber } from './format'
import { SUPPORTED_LOCALES } from './locales'

describe('formatMoney', () => {
  it('formats cents as whole dollars in English', () => {
    expect(formatMoney(123456, 'en')).toBe('$1,235')
  })

  it.each([...SUPPORTED_LOCALES])('keeps the amount readable in %s', (locale) => {
    expect(formatMoney(123456, locale).replace(/\D/g, '')).toBe('1235')
  })

  it('handles zero and rounds half up', () => {
    expect(formatMoney(0, 'en')).toBe('$0')
    expect(formatMoney(150, 'en')).toBe('$2')
  })

  it('shows the cents when asked, for the reward of a lap', () => {
    expect(formatMoney(1550, 'en', 2)).toBe('$15.50')
    expect(formatMoney(1442, 'en', 2)).toBe('$14.42')
    expect(formatMoney(0, 'en', 2)).toBe('$0.00')
    expect(formatMoney(1550, 'pl', 2).replace(/\s/g, ' ')).toBe('15,50 $')
  })
})

describe('formatDay', () => {
  it('writes a day the way the language does', () => {
    expect(formatDay('2026-10-23', 'en')).toBe('October 23, 2026')
    expect(formatDay('2026-10-23', 'pl')).toBe('23 października 2026')
    expect(formatDay('2026-10-23', 'de')).toBe('23. Oktober 2026')
    expect(formatDay('2026-10-23', 'fr')).toBe('23 octobre 2026')
    expect(formatDay('2026-10-23', 'pt-BR')).toBe('23 de outubro de 2026')
  })

  it('does not move the day with the time zone of the machine', () => {
    // The first and the last day of a year are where a shift of a few hours would show.
    expect(formatDay('2027-01-01', 'en')).toBe('January 1, 2027')
    expect(formatDay('2026-12-31', 'en')).toBe('December 31, 2026')
  })

  it('returns what is not a day as it is', () => {
    expect(formatDay('v3', 'en')).toBe('v3')
    expect(formatDay('', 'en')).toBe('')
  })
})

describe('formatNumber', () => {
  it('groups digits according to the language', () => {
    expect(formatNumber(1234567, 'en')).toBe('1,234,567')
    expect(formatNumber(1234.5, 'de', 1)).toBe('1.234,5')
  })
})

describe('formatFixed', () => {
  it('keeps the decimals that are zero, in the way of the language', () => {
    expect(formatFixed(2, 'en', 1)).toBe('2.0')
    expect(formatFixed(2, 'pl', 1)).toBe('2,0')
    expect(formatFixed(3.2, 'pt-BR', 1)).toBe('3,2')
  })
})

describe('formatLapTime', () => {
  it.each([
    [75_000, '1:15.0'],
    [67_015, '1:07.0'],
    [82_313, '1:22.3'],
    [59_950, '1:00.0'],
    [600_000, '10:00.0'],
    [0, '0:00.0'],
  ])('shows %i ms as %s', (ms, expected) => {
    expect(formatLapTime(ms)).toBe(expected)
  })

  it('never goes below zero', () => {
    expect(formatLapTime(-5)).toBe('0:00.0')
  })
})

describe('formatDuration', () => {
  it('shows minutes and seconds under an hour', () => {
    expect(formatDuration(750_000)).toBe('12 min 30 s')
  })

  it('shows hours and minutes from one hour', () => {
    expect(formatDuration(3_900_000)).toBe('1 h 05 min')
  })

  it('never goes below zero', () => {
    expect(formatDuration(-5)).toBe('0 min 00 s')
  })
})
