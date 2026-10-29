import { api } from '@/api/client'

import { parseModel, setModel } from './index'

/**
 * Asks for the model of the game that is live and puts it in force. It waits a moment at most, and when it cannot be had (the network, the server, an answer
 * that is not a model) nothing changes: the app has the model of the car game, which is also what a server without a model says.
 */
export async function loadModel(timeoutMs = 1500): Promise<void> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)

  try {
    const model = parseModel(await api<unknown>('/v1/meta/model', { signal: controller.signal }))

    if (model !== null) {
      setModel(model)
    }
  } catch {
    // Without the model the app works as the car game does.
  } finally {
    clearTimeout(timer)
  }
}
