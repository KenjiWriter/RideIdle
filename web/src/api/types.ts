/** Profile of the signed-in driver (GET /api/v1/me). */
export interface Me {
  id: number
  name: string
  locale: string | null
  is_guest: boolean
  has_recovery_code: boolean
}

export interface MeResponse {
  data: Me
}

/** Response of creating or restoring a driver: the profile and a token for this device. */
export interface SessionResponse extends MeResponse {
  token: string
}

/** Response of issuing a recovery code. The code is shown only once. */
export interface RecoveryCodeResponse extends MeResponse {
  code: string
}
