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

/** Why a run stopped: the tank is empty, the car broke down, it stands in the workshop while a job is done, or it is on the road to another city. */
export type StopReason = 'fuel_empty' | 'breakdown' | 'workshop' | 'travel'

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
    /** What a lap gives the car, in any weather: the car levels on its laps. */
    car_xp: number
    /** How much of its time a lap takes when it starts at night, in ppm: 1 000 000 on a track that is lit, 1 030 000 on one that is not. */
    night_ppm: number
  }
  /** The journey the car is on, while it stands on the road between two cities. */
  travel: RunTravel | null
  /** The clock of the game: a day is `day_ms` of the clock of the server, and each phase of it begins at an hour of the game. */
  clock: { day_ms: number; phases: { key: string; from_hour: number }[] }
  /** The weather of the region now, when this segment of 22.5 minutes ends, what comes next, and a forecast of this segment and the four after it. */
  weather: { now: string; ends_ms: number; next: string; forecast: ForecastSegment[] }
  /** The lap in progress, in ms of the clock of the server; null for a car that stands or waits for a tow. */
  current_lap: { start_ms: number; finish_ms: number; ms: number; weather: string } | null
  server_time_ms: number
}

/** The journey of a car between two cities, with the names in the language of the driver. */
export interface RunTravel {
  from: string
  from_name: string
  to: string
  to_name: string
  start_ms: number
  arrive_ms: number
}

/** One stretch of 22.5 minutes of the weather, with the surface it makes of the track (a column of the tire matrix). */
export interface ForecastSegment {
  weather: string
  starts_ms: number
  ends_ms: number
  surface: SurfaceKey
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
/** What a car drives on: the dry track, and what the weather makes of it. The columns of the tire matrix. */
export type SurfaceKey = 'asphalt_dry' | 'asphalt_wet' | 'gravel_dry' | 'mud' | 'snow'

export interface PartInfo {
  slug: string
  name: string
  slot: string
  tier: number | null
  price_cents: number | null
  stats: Record<string, number>
  /** Tires only: how well they grip on each surface, 1 being the grip of the tires on dry asphalt. */
  surfaces?: Record<SurfaceKey, number> | null
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

export type CarStatKey = 'accel' | 'top_speed' | 'grip' | 'handling' | 'braking' | 'clearance'

export interface GarageSlot {
  slot: string
  part: OwnedPart | null
  /** The slot holds a part of the body, which cannot be taken off (the engine for now). */
  built_in: boolean
}

export interface GarageCar {
  id: number
  level: number
  /** The experience of the car, and where its level begins and ends; `next_level_at` is null at the highest level. */
  xp: { total: number; level_start: number; next_level_at: number | null }
  /** The next tier of parts the car cannot have mounted yet, and the level that opens it, or null when no level does. */
  tier_unlock: { tier: number; level: number } | null
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
    /** The workshop of the city the car stands in: its queue, and the mechanic who works the cars of the driver there one after another. */
    workshop: { city: { slug: string; name: string }; queue_max: number; mechanic: { level: number; time_cut_ppm: number }; jobs: WorkshopJob[] }
  }
  now_ms: number
}

/** DELETE /api/v1/garage/jobs/{job}: the garage as it is now, and the ids of the jobs that were cancelled, the asked one first. */
export interface CancelJobResponse extends GarageResponse {
  cancelled: number[]
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
    rewarded: { key: string; cash_cents: number; car_xp: number }[]
  }
}

/** What a city holds for the driver: whether they have a garage there, how full it is, and what one costs. */
export interface CityGarageState {
  owned: boolean
  capacity: number
  /** The cars that stand there, and the ones on their way: a car on the road keeps its place at the destination. */
  used: number
  free: number
  /** What a garage costs, null when the driver has it. */
  price_cents: number | null
}

/** A city of the map: where it is, what its track asks of a car, and what the way there costs from the city the car is in. */
export interface MapCity {
  slug: string
  name: string
  /** Where it is on the board of the map, 390 by 716 units. */
  x: number
  y: number
  home: boolean
  here: boolean
  level_required: number
  open: boolean
  garage: CityGarageState
  track: { slug: string; name: string; surface: 'asphalt' | 'gravel'; lit: boolean; base_lap_ms: number; demand: Record<string, number> }
  /** How well the car, with its parts, runs the track in the dry: 100 is the lap of a car that matches what the track asks. Null without a car. */
  fit_percent: number | null
  /** The way from the city the car is in: null for that city, without a car and while the car is on the road. */
  route: { km: number; ms: number; fuel_ml: number } | null
}

/** GET /api/v1/map, and the answer of POST /api/v1/travel. `now_ms` is the clock of the server. */
export interface MapResponse {
  data: {
    here: string
    level: number
    cities: MapCity[]
    roads: { from: string; to: string; km: number }[]
    travel: { from: string; to: string; distance_km: number; start_ms: number; arrive_ms: number } | null
  }
  now_ms: number
}

/** A car of the driver as the list of the fleet has it: where it stands, and what its run is doing. */
export interface FleetCar {
  id: number
  level: number
  /** The car the driver plays. */
  selected: boolean
  /** How worn the car is: 100 is perfect, and a worn car breaks down more. */
  condition_percent: number
  vehicle: { slug: string; name: string }
  city: { slug: string; name: string }
  run: { id: number; status: RunStatus; stop_reason: StopReason | null; laps_done: number; fuel_ml: number; tank_ml: number; track: string } | null
  /** The journey the car is on, if it is. */
  travel: { to: string; to_name: string; arrive_ms: number } | null
}

/** A garage the driver can have in a city, with how full it is. */
export interface FleetGarage extends CityGarageState {
  city: string
  name: string
  level_required: number
  /** The level of the driver opens the city, so a garage can be bought there. */
  open: boolean
}

/** A wreck the yard sells. */
export interface YardCar {
  slug: string
  name: string
  class: string
  price_cents: number
}

/** GET /api/v1/cars, and the answer of the calls that change the fleet. `repeated` says that a purchase came a second time and bought nothing. */
export interface FleetResponse {
  data: { selected: number | null; cars: FleetCar[]; garages: FleetGarage[]; yard: YardCar[] }
  wallet: Wallet
  now_ms: number
  repeated?: boolean
  bought?: number | null
}

/** A car of the market as the screens show it: the body, and what it is made of. */
export interface MarketVehicle {
  slug: string
  name: string
  class: string
  drivetrain: 'fwd' | 'rwd' | 'awd'
  engine: string
  power_kw: number
  top_speed_kmh: number
  mass_kg: number
}

/** What a listing says of the condition of a car, which is only roughly the truth: an inspection tells it. */
export type ListingWord = 'good' | 'fair' | 'poor'

/** A car on the used-car exchange. The condition is there once the driver has paid for the inspection. */
export interface ExchangeListing {
  key: string
  vehicle: MarketVehicle
  age_years: number
  mileage_km: number
  word: ListingWord
  price_cents: number
  inspected: boolean
  /** What the inspection costs, null once it is done. */
  inspection_cost_cents: number | null
  condition: { percent: number; defects: number } | null
}

/** A new car of the dealer, and whether the level of the driver opens it. */
export interface DealerOffer {
  vehicle: MarketVehicle
  price_cents: number
  level_required: number
  open: boolean
}

/** GET /api/v1/market, and the answer of the calls that buy or inspect a car. The market is the one of the city where the car the driver plays stands. */
export interface MarketResponse {
  data: {
    city: { slug: string; name: string }
    level: number
    garage: { owned: boolean; capacity: number; used: number; free: number }
    exchange: { refresh_ms: number; next_refresh_ms: number; listings: ExchangeListing[] }
    dealer: DealerOffer[]
    wrecks: YardCar[]
  }
  wallet: Wallet
  now_ms: number
  /** A purchase that came a second time bought nothing. */
  repeated?: boolean
  bought?: number | null
}
