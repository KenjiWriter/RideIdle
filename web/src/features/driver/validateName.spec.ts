import { describe, expect, it } from 'vitest'

import { NAME_MAX, NAME_MIN, validateName } from './validateName'

describe('validateName', () => {
  it.each(['Maciek_Turbo', 'abc', 'Łukasz', 'François', 'Müller_1', 'João-Pedro', 'a'.repeat(NAME_MAX), '  Turbo  '])(
    'accepts %s',
    (name) => {
      expect(validateName(name)).toBeNull()
    },
  )

  it.each(['', '   '])('asks for a name when it is empty (%j)', (name) => {
    expect(validateName(name)).toBe('required')
  })

  it('rejects names that are too short or too long', () => {
    expect(validateName('a'.repeat(NAME_MIN - 1))).toBe('length')
    expect(validateName('a'.repeat(NAME_MAX + 1))).toBe('length')
  })

  it.each(['ab cd', 'ab.cd', 'ab🚗cd', 'ab@cd', 'ab/cd'])('rejects the characters in %s', (name) => {
    expect(validateName(name)).toBe('characters')
  })

  it('counts characters, not bytes', () => {
    expect(validateName('Żółć')).toBeNull()
    expect(validateName('Ż'.repeat(NAME_MAX))).toBeNull()
  })
})
