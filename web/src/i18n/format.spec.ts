import { describe, expect, it } from 'vitest'

import { formatDuration, formatLapTime, formatMoney, formatNumber } from './format'
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

describe('formatNumber', () => {
  it('groups digits according to the language', () => {
    expect(formatNumber(1234567, 'en')).toBe('1,234,567')
    expect(formatNumber(1234.5, 'de', 1)).toBe('1.234,5')
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
