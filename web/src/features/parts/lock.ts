import type { PartLock } from '@/api/types'

/** The litres of an engine as the interface writes numbers: "3.2" or "3,2", and a whole number with no decimal. */
type FixedFormat = (value: number, fractionDigits: number) => string

function litres(value: number, fixed?: FixedFormat): string {
  const shown = Number.isInteger(value) ? 0 : 1

  return fixed ? fixed(value, shown) : value.toFixed(shown)
}

/**
 * What a car lacks for a part, in words: "This car has 4 cylinders, the part needs 6", that a booster has to be put on first, or what the engine bay of the body
 * says (the layout it does not take, the litres it does not hold, the cylinders the booster on the car asks for). `fixed` writes the litres in the language of the
 * interface; without it they are written with a dot.
 */
export function lockText(t: (key: string, named?: Record<string, unknown>) => string, lock: PartLock, fixed?: FixedFormat): string {
  switch (lock.reason) {
    case 'cylinders':
      return t('part.lock.cylinders', { current: lock.current, required: lock.required })
    case 'bay_layout':
      return t('part.lock.bayLayout', { layout: lock.layout, allowed: lock.allowed.join(', ') })
    case 'bay_size':
      return t('part.lock.baySize', { displacement: litres(lock.displacement_l, fixed), max: litres(lock.max_displacement_l, fixed) })
    case 'booster_cylinders':
      return t('part.lock.boosterCylinders', { current: lock.current, required: lock.required })
    default:
      return t('part.lock.booster')
  }
}
