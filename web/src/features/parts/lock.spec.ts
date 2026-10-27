import { beforeEach, describe, expect, it } from 'vitest'

import type { PartLock } from '@/api/types'
import { i18n, setLocale } from '@/i18n'
import { formatFixed } from '@/i18n/format'

import { lockText } from './lock'

const t = (key: string, named?: Record<string, unknown>): string => i18n.global.t(key, named ?? {}) as string

const bayLayout: PartLock = { reason: 'bay_layout', layout: 'V8', allowed: ['R4', 'V6'] }
const baySize: PartLock = { reason: 'bay_size', displacement_l: 3.2, max_displacement_l: 3 }
const boosterCylinders: PartLock = { reason: 'booster_cylinders', required: 6, current: 4 }

describe('lockText', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it('says what a car lacks for a part: the cylinders, a booster', () => {
    expect(lockText(t, { reason: 'cylinders', required: 6, current: 4 })).toBe('This car has 4 cylinders, the part needs 6')
    expect(lockText(t, { reason: 'booster' })).toBe('Put a booster on the car first')
  })

  it('says what the engine bay of the body says of an engine: the layout it does not take, the litres it does not hold, the cylinders the booster asks for', () => {
    expect(lockText(t, bayLayout)).toBe('The engine bay of this car does not take a V8 engine (it takes R4, V6)')
    expect(lockText(t, baySize)).toBe('Engine too big: 3.2 l, the bay holds up to 3 l')
    expect(lockText(t, boosterCylinders)).toBe('The booster on the car needs an engine with at least 6 cylinders (this one has 4)')
  })

  it('writes the litres the way the language does when it is given the way', async () => {
    await setLocale('pl')

    expect(lockText(t, baySize, (value, digits) => formatFixed(value, 'pl', digits))).toBe('Silnik za duży: 3,2 l, zatoka mieści do 3 l')
    expect(lockText(t, bayLayout)).toBe('Zatoka tego auta nie przyjmie silnika V8 (przyjmuje: R4, V6)')
    expect(lockText(t, boosterCylinders)).toBe('Doładowanie na aucie wymaga silnika z co najmniej 6 cylindrami (ten ma 4)')
  })

  it('keeps a no-break space before the colon and the unit in French', async () => {
    await setLocale('fr')

    expect(lockText(t, baySize, (value, digits) => formatFixed(value, 'fr', digits))).toBe('Moteur trop grand : 3,2 l, le compartiment en accepte au plus 3 l')
  })
})
