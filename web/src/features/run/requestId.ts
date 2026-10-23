/**
 * A new identifier for one intention to refuel or repair. The server charges once for a request id however many
 * times it arrives, so the client may safely repeat a request whose answer was lost.
 *
 * randomUUID exists only in secure contexts (https and localhost), and a game opened over plain http on a
 * local network must work too, so the fallback builds the id from random bytes, which every browser has.
 */
export function newRequestId(): string {
  if (typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  return Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('')
}
