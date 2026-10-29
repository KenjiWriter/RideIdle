import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '@/api/client'

/** GET /api/v1/premium: only the balance is read here, the packs and the purchases are for the screen that sells them. */
interface PremiumResponse {
  data: { balance: number }
}

/** The premium coins of the driver, asked for when no answer of another screen has said how many there are. */
export const usePremiumStore = defineStore('premium', () => {
  const balance = ref(0)
  /** When the balance was read (the time of this device), 0 until it was. */
  const seenAt = ref(0)

  async function load(): Promise<void> {
    const response = await api<PremiumResponse>('/v1/premium')

    balance.value = response.data.balance
    seenAt.value = Date.now()
  }

  return { balance, seenAt, load }
})
