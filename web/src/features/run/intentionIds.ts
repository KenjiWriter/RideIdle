import { newRequestId } from '@/features/run/requestId'

/**
 * The request ids of purchases that must not be made twice. The same intention (the same key) keeps the same id until it is answered, so a
 * request whose answer was lost can be sent again and charges once; another intention, or the same one after `lifetimeMs`, gets a new id,
 * because then the driver means a new purchase.
 */
export function intentionIds(lifetimeMs: number) {
  let pending: { key: string; id: string; at: number } | null = null

  return {
    /** The id of the intention with this key. */
    forKey(key: string): string {
      if (pending === null || pending.key !== key || Date.now() - pending.at > lifetimeMs) {
        pending = { key, id: newRequestId(), at: Date.now() }
      }

      return pending.id
    },
    /** The intention was answered: the next one is a new one. */
    answered(): void {
      pending = null
    },
  }
}
