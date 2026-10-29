import { api } from '@/api/client'

import { DEFAULT_GAME, applyGame, parseGame, setGame, type GameInfo } from './index'

/**
 * Asks which game the host of the page is and puts it in force (and on the page). It waits a moment at most, and when it cannot be had (the network, the server, an
 * answer that is not a game) nothing changes: the app is the car game, which is also what a server of one game says. Gives the game that is in force.
 */
export async function loadGame(timeoutMs = 1500): Promise<GameInfo> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)

  try {
    const game = parseGame(await api<unknown>('/v1/meta/game', { signal: controller.signal }))

    if (game !== null) {
      setGame(game)
      applyGame(game)

      return game
    }
  } catch {
    // Without the game the app is the car game.
  } finally {
    clearTimeout(timer)
  }

  return DEFAULT_GAME
}
