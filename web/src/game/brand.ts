/** The name of a game as the mark of the header draws it: the first part in white, the second, when there is one, in amber. */
export interface BrandParts {
  first: string
  second: string | null
  /** The two parts were two words, so the mark has a space between them (the parts of RideIdle touch). */
  spaced: boolean
}

/**
 * Splits the name of a game into the two parts of its mark: at the first space (`Star Ships` is Star and Ships), or where a small letter is followed by a capital
 * (`RideIdle` is Ride and Idle). A name of one word, which has neither, is all of it the first part.
 */
export function brandParts(name: string): BrandParts {
  const trimmed = name.trim()
  const space = trimmed.search(/\s/)

  if (space > 0) {
    const second = trimmed.slice(space).trim()

    return { first: trimmed.slice(0, space), second: second === '' ? null : second, spaced: second !== '' }
  }

  const camel = /^(.*?[\p{Ll}\p{N}])(\p{Lu}.*)$/u.exec(trimmed)

  return camel ? { first: camel[1] ?? trimmed, second: camel[2] ?? null, spaced: false } : { first: trimmed, second: null, spaced: false }
}
