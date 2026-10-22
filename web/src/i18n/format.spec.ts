import { describe, expect, it } from 'vitest'

import { formatDuration, formatMoney, formatNumber } from './format'
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
})

describe('formatNumber', () => {
  it('groups digits according to the language', () => {
    expect(formatNumber(1234567, 'en')).toBe('1,234,567')
    expect(formatNumber(1234.5, 'de', 1)).toBe('1.234,5')
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
