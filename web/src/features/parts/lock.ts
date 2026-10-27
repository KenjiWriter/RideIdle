import type { PartLock } from '@/api/types'

/** What a car lacks for a part, in words: "This car has 4 cylinders, the part needs 6", or that a booster has to be put on first. */
export function lockText(t: (key: string, named?: Record<string, unknown>) => string, lock: PartLock): string {
  return lock.reason === 'cylinders' ? t('part.lock.cylinders', { current: lock.current, required: lock.required }) : t('part.lock.booster')
}
