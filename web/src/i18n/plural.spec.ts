import { createI18n } from 'vue-i18n'
import { describe, expect, it } from 'vitest'

import { pluralRules } from './plural'

function counter(locale: string, message: string) {
  const i18n = createI18n({ legacy: false, locale, messages: { [locale]: { laps: message } }, pluralRules })

  return (n: number): string => i18n.global.t('laps', { n }, n)
}

describe('plural rules', () => {
  const polish = counter('pl', '{n} okrążenie | {n} okrążenia | {n} okrążeń')

  it.each([
    [1, '1 okrążenie'],
    [2, '2 okrążenia'],
    [4, '4 okrążenia'],
    [5, '5 okrążeń'],
    [0, '0 okrążeń'],
    [12, '12 okrążeń'],
    [14, '14 okrążeń'],
    [21, '21 okrążeń'],
    [22, '22 okrążenia'],
    [24, '24 okrążenia'],
    [25, '25 okrążeń'],
    [112, '112 okrążeń'],
    [312, '312 okrążeń'],
  ])('uses the Polish form for %i', (n, expected) => {
    expect(polish(n)).toBe(expected)
  })

  it('counts a Polish message with two forms like English', () => {
    const two = counter('pl', '{n} auto | {n} aut')

    expect([two(1), two(2), two(5)]).toEqual(['1 auto', '2 aut', '5 aut'])
  })

  it('calls zero singular in French', () => {
    const french = counter('fr', '{n} tour | {n} tours')

    expect([french(0), french(1), french(2)]).toEqual(['0 tour', '1 tour', '2 tours'])
  })

  it('keeps the English rule for the other languages', () => {
    const english = counter('en', '{n} lap | {n} laps')
    const german = counter('de', '{n} Runde | {n} Runden')

    expect([english(0), english(1), english(2)]).toEqual(['0 laps', '1 lap', '2 laps'])
    expect([german(1), german(3)]).toEqual(['1 Runde', '3 Runden'])
  })
})
