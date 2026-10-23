/** The ways to sign in to an account. */
export type AccountProvider = 'email' | 'google' | 'facebook' | 'apple'

/** The providers of another service. The server offers the ones it has keys for. */
export type SocialProvider = Exclude<AccountProvider, 'email'>

/** A way the account can be signed in to. The e-mail address is masked (t***@example.com): enough to tell which one it is. */
export interface AccountIdentity {
  provider: AccountProvider
  email: string | null
}

/** Profile of the signed-in driver (GET /api/v1/me). */
export interface Me {
  id: number
  name: string
  locale: string | null
  is_guest: boolean
  has_recovery_code: boolean
  identities: AccountIdentity[]
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

/** Why a run stopped: the tank is empty, the car broke down, or it stands in the workshop while a job is done. */
export type StopReason = 'fuel_empty' | 'breakdown' | 'workshop'

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

/**
 * Whether the driver may ask for a tow now: the car stands and they cannot pay to get it going. The tow is free,
 * but the car waits `delay_ms` before it drives again.
 */
export interface TowState {
  available: boolean
  delay_ms: number
}

/** Answer of every call that touches the run. Which of the extra parts are there depends on the call. */
export interface RunResponse {
  data: Run
  wallet: Wallet
  settlements?: Settlement[]
  summary?: RunSummary
  refuel?: RefuelResult
  repair?: RepairResult
  tow?: TowState
  /** In the answer of the tow itself: false when nothing was done, because the car was driving or the driver could pay. */
  towed?: boolean
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

/** What the terms and the privacy policy need from the server (GET /api/v1/meta/legal). */
export interface LegalInfo {
  /** The age a driver confirms to have reached. */
  min_age: number
  /** The day the texts were last changed. It is saved with the acceptance. */
  version: string
  /** How many days a guest account is kept without activity. */
  guest_expiry_days: number
  /** Who runs the game. A detail that was not set is null. */
  operator: { name: string | null; address: string | null; email: string | null }
}

export interface LegalResponse {
  data: LegalInfo
}

/** The sign-ins with another service that the server has set up (GET /api/v1/auth/providers). */
export interface ProvidersResponse {
  data: SocialProvider[]
}

/** The answer to asking for a link by e-mail: what to tell the driver, and how long the link works. */
export interface EmailLinkResponse {
  message: string
  expires_in: number
}

/**
 * Starting a sign-in with a provider: where to send the browser, and the verifier it must keep until it comes back.
 * Only the browser that holds the verifier can finish the sign-in.
 */
export interface ProviderStart {
  url: string
  verifier: string
}

/** Finishing a sign-in with a provider. A `login` carries a token for this device, a `link` does not. */
export interface ProviderFinish extends MeResponse {
  purpose: 'link' | 'login'
  token?: string
}

/** Using the link of an e-mail. A `claim` secures the account the link was asked for, a `login` signs in. */
export interface EmailConfirmation extends MeResponse {
  purpose: 'claim' | 'login'
  token?: string
}

/** What a definition of the pack says about a part. The name is in the language of the driver. */
export interface PartInfo {
  slug: string
  name: string
  slot: string
  tier: number | null
  price_cents: number | null
  stats: Record<string, number>
}

/** A part of the driver, in the inventory or on the car. */
export interface OwnedPart extends PartInfo {
  id: number
  mounted: boolean
  car_id: number | null
}

/** A part for sale, with what the car of the driver says about it. */
export interface ShopOffer extends PartInfo {
  required_car_level: number
  mountable: boolean
}

/** GET /api/v1/shop */
export interface ShopResponse {
  data: { parts: ShopOffer[]; car: { id: number; level: number } | null }
  wallet: Wallet
}

/** POST /api/v1/shop/purchases. `data` is null when the same request was made before: the part is in the inventory already. */
export interface PurchaseResponse {
  data: OwnedPart | null
  purchase: { cost_cents: number; repeated: boolean }
  wallet: Wallet
}

export type CarStatKey = 'accel' | 'top_speed' | 'grip' | 'handling' | 'braking'

export interface GarageSlot {
  slot: string
  part: OwnedPart | null
  /** The slot holds a part of the body, which cannot be taken off (the engine for now). */
  built_in: boolean
}

export interface GarageCar {
  id: number
  level: number
  vehicle: { slug: string; name: string; engine: string | null; power_kw: number | null; drivetrain: 'fwd' | 'rwd' | 'awd' | null }
  /** With the parts that are on the car, and without them. */
  stats: Record<CarStatKey, number>
  base_stats: Record<CarStatKey, number>
  /** The value of a statistic at which its bar is full: what the best car of the whole game reaches. */
  ceilings: Record<CarStatKey, number>
  tank_ml: number
  slots: GarageSlot[]
  lap_ms: Record<string, number> | null
  run: { id: number; status: RunStatus; stop_reason: StopReason | null } | null
}

export type JobKind = 'mount' | 'unmount'

/** A job of the workshop. The times are ms of the clock of the server. */
export interface WorkshopJob {
  id: number
  kind: JobKind
  /** Waiting for the mechanic, or being done (the car stands in the workshop). */
  status: 'queued' | 'working'
  part: OwnedPart
  start_ms: number
  finish_ms: number
}

/** GET /api/v1/garage, and the answer of POST /api/v1/garage/jobs. `now_ms` is the clock of the server. */
export interface GarageResponse {
  data: {
    car: GarageCar | null
    inventory: OwnedPart[]
    workshop: { queue_max: number; mechanic: { level: number; time_cut_ppm: number }; jobs: WorkshopJob[] }
  }
  now_ms: number
}

export type TutorialStatus = 'in_progress' | 'finished' | 'skipped'

/** The thing on the garage screen that a step of the tutorial points at. */
export type TutorialTarget = 'laps' | 'cash' | 'refuel' | 'repair'

/** The step that waits: the messages of the mentor, in the language of the driver, and what to highlight. */
export interface TutorialStep {
  key: string
  /** The place of the step in the tutorial, from 1. */
  number: number
  sender: string
  sms: string[]
  /** The words of the button that answers the messages. */
  reply: string
  target: TutorialTarget | null
  /** The driver answered the messages: the phone is put away, but the step may still wait for them to do the thing. */
  acknowledged: boolean
}

/** GET /api/v1/tutorial, and the answer of POST /api/v1/tutorial/reply and /skip. */
export interface TutorialResponse {
  data: {
    status: TutorialStatus
    total: number
    done: number
    current: TutorialStep | null
    /** What the steps completed by this very call paid. */
    rewarded: { key: string; cash_cents: number }[]
  }
}
