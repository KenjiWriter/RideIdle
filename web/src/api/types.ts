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

export type RunStatus = 'running' | 'stopped'

/** Why a run stopped: the tank is empty or the car broke down. */
export type StopReason = 'fuel_empty' | 'breakdown'

/** A run: a car driving laps on a track (GET /api/v1/runs/current). Times are ms of the server clock, fuel is in ml. */
export interface Run {
  id: number
  car: { slug: string; name: string }
  track: { slug: string; name: string }
  status: RunStatus
  stop_reason: StopReason | null
  laps_done: number
  fuel_ml: number
  tank_ml: number | null
  cursor_ms: number
  /** Grows with every change of the run. The client sends the one it saw last as since_version. */
  state_version: number
  /** What the actions of the run cost, in cents. */
  prices: { fuel_cents_per_liter: number; repair_cents: number }
  /** Per weather: lap time in ms, reward in cents, XP; and the fuel use per lap in ml. */
  lap: {
    ms: Record<string, number>
    cash_cents: Record<string, number>
    xp: Record<string, number>
    fuel_ml: number
  }
  server_time_ms: number
}

export interface Wallet {
  cash_cents: number
  xp: number
}

export interface SettlementEvent {
  type: 'lap_batch' | 'breakdown' | 'fuel_empty'
  at_ms: number
  payload: Record<string, number | string>
}

/** What the car did between two moments, and the log of it. */
export interface Settlement {
  version: number
  from_ms: number
  to_ms: number
  at_ms: number
  laps: number
  cash_cents: number
  xp: number
  stop_reason: StopReason | null
  events: SettlementEvent[]
}

/** The settlements of an answer added up. */
export interface RunSummary {
  laps: number
  cash_cents: number
  xp: number
  from_ms: number | null
  to_ms: number | null
  stop_reason: StopReason | null
}

/** What a refuel did. `repeated` means the same request had already been done, so nothing was added or charged now. */
export interface RefuelResult {
  added_ml: number
  cost_cents: number
  repeated: boolean
}

/** What a repair did. `repaired` is false when the car had not broken down. */
export interface RepairResult {
  repaired: boolean
  cost_cents: number
  repeated: boolean
}

/** Answer of every call that touches the run. Which of the extra parts are there depends on the call. */
export interface RunResponse {
  data: Run
  wallet: Wallet
  settlements?: Settlement[]
  summary?: RunSummary
  refuel?: RefuelResult
  repair?: RepairResult
}

/** A wreck a new driver can start with (GET /api/v1/starter-cars), and what it does on the starting track. */
export interface StarterCar {
  slug: string
  name: string
  description: string | null
  class: string
  drivetrain: 'fwd' | 'rwd' | 'awd'
  engine: { layout: string; displacement_l: number }
  power_kw: number
  mass_kg: number
  top_speed_kmh: number
  cargo_kg: number
  tank_l: number
  lap: { weather: string; ms: number; cash_cents: number; xp: number; fuel_ml: number }
}

export interface StarterCarsResponse {
  data: StarterCar[]
  track: { slug: string; name: string }
}
