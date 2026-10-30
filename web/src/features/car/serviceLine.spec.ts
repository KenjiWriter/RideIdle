import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'

import { setGame } from '@/game'
import { i18n, setLocale, setVocabulary } from '@/i18n'
import en from '@/i18n/locales/en.json'
import { aService } from '@/test/garageFixtures'
import { aShipsGame } from '@/test/unitsFixtures'

import { useServiceLine } from './serviceLine'

/** The lines of a screen that is set up. */
function inScreen() {
  let lines!: ReturnType<typeof useServiceLine>
  const Probe = defineComponent({
    setup() {
      lines = useServiceLine()

      return () => h('div')
    },
  })

  mount(Probe, { global: { plugins: [i18n] } })

  return lines
}

const LATE = aService({ since_km: 5_800, due_in_km: -800, overdue_km: 800, penalty_percent: 4 })
const SOFT = aService({ since_km: 5_300, due_in_km: -300, overdue_km: 300, penalty_percent: 0 })

describe('the service line', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  afterEach(async () => {
    setGame()
    // The texts of the app, as they were: a vocabulary is merged into the messages that the module keeps.
    setVocabulary({})
    i18n.global.setLocaleMessage('en', en)
    await setLocale('en')
  })

  it('says how far the service is, or how late it is and what the lateness costs, in kilometres', () => {
    const { serviceLine } = inScreen()

    expect(serviceLine(aService())).toBe('Service in 3,420 km')
    expect(serviceLine(LATE)).toBe('Service overdue by 800 km: −4% performance')
    expect(serviceLine(SOFT)).toBe('Service overdue by 300 km')
  })

  it('says it in the unit of travel of the game that is in force', () => {
    const { serviceLine } = inScreen()

    setGame(aShipsGame())

    expect(serviceLine(aService())).toBe('Service in 1,846.7 nm')
    expect(serviceLine(LATE)).toBe('Service overdue by 432.0 nm: −4% performance')
    expect(serviceLine(SOFT)).toBe('Service overdue by 162.0 nm')
  })

  it('writes the number the way the language does, with a no-break space before the unit in French', async () => {
    const { serviceLine } = inScreen()

    await setLocale('fr')

    expect(serviceLine(aService())).toBe('Entretien dans 3 420 km')

    setGame(aShipsGame())

    expect(serviceLine(aService())).toBe('Entretien dans 1 846,7 nm')
    expect(serviceLine(LATE)).toBe('Entretien en retard de 432,0 nm : −4 % de performances')
  })

  it('still fills in {km}, the number as the server has it, for a game whose vocabulary wrote its own message with it', () => {
    const { serviceLine } = inScreen()

    setVocabulary({ en: { 'workshop.serviceIn': 'Overhaul in {km} kilometres', 'workshop.serviceLate': 'Overhaul late by {km} kilometres' } })

    expect(serviceLine(aService())).toBe('Overhaul in 3,420 kilometres')
    expect(serviceLine(SOFT)).toBe('Overhaul late by 300 kilometres')
  })
})
