/** Same rules as the API (CreateGuestRequest), so mistakes are caught before a request is sent. */
export const NAME_MIN = 3
export const NAME_MAX = 16

export type NameError = 'required' | 'length' | 'characters'

/** Letters of any alphabet, digits, underscore and dash. */
const ALLOWED = /^[\p{L}\p{N}_-]+$/u

/**
 * Checks a driver name. Returns the key of the problem (used for the message
 * `create.errors.<key>`) or null when the name is fine.
 */
export function validateName(raw: string): NameError | null {
  const name = raw.trim()

  if (name === '') {
    return 'required'
  }

  if (!ALLOWED.test(name)) {
    return 'characters'
  }

  const length = [...name].length

  return length < NAME_MIN || length > NAME_MAX ? 'length' : null
}
