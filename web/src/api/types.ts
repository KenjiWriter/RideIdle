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

/** Why a run stopped: the tank is empty, the car broke down, it stands in the workshop while a job is done, it is on the road to another city, or it waits in the garage for the Start. */
export type StopReason = 'fuel_empty' | 'breakdown' | 'workshop' | 'travel' | 'parked'

/** One racer of the field of a run: a bot (with a name) or the driver (the screen writes the car). `lap_ms` is the lap in the dry. */
export interface FieldRow {
  name: string | null
  type: 'bot' | 'you'
  lap_ms: number
}

/**
 * The field the car races among: eight cars, the driver and seven bots, ranked by the lap in the dry (a tie goes to the bots). The position does not change with
 * the weather, and `reward_ppm` is what it multiplies the reward of a lap by (the reward in `lap.cash_cents` already has it in).
 */
export interface RunField {
  position: number
  size: number
  reward_ppm: number
  rows: FieldRow[]
}

/** A run: a car driving laps on a track (GET /api/v1/runs/current). Times are ms of the server clock, fuel is in ml. */
export interface Run {
  id: number
  car: { slug: string; name: string; image_url?: string | null }
  track: { slug: string; name: string; kind?: TrackKind }
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
  /** The field the car races among, null for a run that was frozen before there were bots. */
  field: RunField | null
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
  /** `service_due` says the car has driven as far as its service falls due (`mileage_km`); a `breakdown` may say which part it was (`part`, the name of the slot). */
  type: 'lap_batch' | 'breakdown' | 'fuel_empty' | 'service_due'
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
  /** The picture of the car, a path of the API, or null when the pack gives it none. */
  image_url?: string | null
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

/** How rare a part is: it multiplies what the part gives to a car. */
export type PartRarity = 'common' | 'uncommon' | 'rare' | 'epic' | 'legendary'

/** What the car must have for a part: an engine with at least `min_cylinders` cylinders, and, for an intercooler, a booster. */
export interface PartRequires {
  min_cylinders?: number
  booster?: true
}

/**
 * What a car lacks for a part, besides its level: the cylinders (`required` and the `current` number), a booster, or what the engine bay of the body says of an
 * engine: it does not take the `layout` (it takes the `allowed` ones), it holds no more than `max_displacement_l` litres, or the booster on the car needs
 * `required` cylinders and the engine has `current`.
 */
export type PartLock =
  | { reason: 'cylinders'; required: number; current: number }
  | { reason: 'booster' }
  | { reason: 'bay_layout'; layout: string; allowed: string[] }
  | { reason: 'bay_size'; displacement_l: number; max_displacement_l: number }
  | { reason: 'booster_cylinders'; required: number; current: number }

export interface PartInfo {
  slug: string
  name: string
  /** The picture of the model, a path of the API, or null when the pack gives it none. */
  image_url?: string | null
  /** Tires only: the kind (`street`, `offroad`, …), which a step of the tutorial names when it asks for tires of one kind. Null for any other part. */
  tire_kind?: string | null
  slot: string
  tier: number | null
  price_cents: number | null
  /** What the part gives, with its rarity applied. */
  stats: Record<string, number>
  /** What the car needs for the part: null when it needs nothing. */
  requires?: PartRequires | null
  /** An engine says what it is: its layout and its displacement in litres (its power and mass are in `stats`: `power_kw`, `engine_mass_kg`). Null for the other parts. */
  engine?: { layout: string; displacement_l: number } | null
  rarity: PartRarity
  /** 1 (common) to 5 (legendary): the number of the filled squares. */
  rarity_rank: number
  /** The upgrade of the part (+N): a level of 3% on top of the rarity, which stays with the part. */
  upgrade: number
  /** How far a part of this rarity can be upgraded at the most. */
  upgrade_max: number
  /** Tires only: how well they grip on each surface, 1 being the grip of the tires on dry asphalt. */
  surfaces?: Record<SurfaceKey, number> | null
  /** The affixes the part was drawn with when it got its rarity, in the order they were drawn: none for a common part and the parts of the shop. */
  affixes?: PartAffix[]
  /** What a legendary part has of its own, the same for all of its slot: null for any other part. */
  unique?: PartUnique | null
}

/** What an affix changes: a statistic of the part, how fast it wears, the fuel of the car, how likely it is to break down, or the grip of the tires on some surfaces. */
export type AffixEffect = 'stat' | 'wear' | 'fuel' | 'reliability' | 'surface'

/** One affix of a part: `name` is in the language of the driver, `percent` how much it gives (the share it adds to the statistic or takes off the wear, the fuel or the breakdowns). */
export interface PartAffix {
  key: string
  name: string
  effect: AffixEffect
  /** The statistic of a `stat` affix (grip, handling, braking, clearance or power). */
  stat: string | null
  /** The surfaces of a `surface` affix. */
  surfaces: SurfaceKey[] | null
  value_ppm: number
  percent: number
}

/** The effect of a legendary part: a special one (`special`, by how much in `percent`), or modifiers (`mods`, each with the name of what it changes when the rules have one). */
export interface PartUnique {
  name: string
  special: 'surface_loss' | 'mass_penalty' | 'braking_condition' | 'lag_cut' | null
  percent: number | null
  mods: { effect: AffixEffect; stat: string | null; name: string | null; percent: number }[] | null
}

/**
 * What merging this part would do (the inventory of the garage only): two parts of one slot, tier and rarity make one of the next rarity, or fail and are lost.
 * `chance_ppm` is the chance in ppm (1 000 000 is certain), of which `pity_ppm` is the protection from bad luck (3 points for every failure since the last success
 * at that slot and rarity); `with` are the ids of the other parts of the inventory it can be merged with.
 */
export interface MergeOffer {
  to: PartRarity
  chance_ppm: number
  pity_ppm: number
  cost_cents: number
  duration_ms: number
  scrap_cents: number
  with: number[]
}

/** What putting a worn part right costs and takes, and what stops it: the part is not worn (`fine`), it is worn out and has to be replaced (`worn_out`), or it has no price (`unavailable`). */
export interface RepairNext {
  cost_cents: number | null
  duration_ms: number | null
  locked: { reason: 'fine' | 'worn_out' | 'unavailable' } | null
}

/** What a job of putting a part on or taking it off costs and takes in this workshop (`cost_cents` is the fee of the vulcanizer for tires, where there is no tire machine). */
export interface SlotJobNext {
  cost_cents: number
  duration_ms: number
  /** What the car lacks for putting the part on (cylinders, a booster), or null; the job is refused then. */
  locked?: PartLock | null
}

/** A part of the driver, in the inventory or on the car. */
export interface OwnedPart extends PartInfo {
  id: number
  mounted: boolean
  car_id: number | null
  /** How much of its life the part has left, in percent: 100 is new, and the statistics above already count it (x 0.6 + 0.4 x condition). */
  condition_percent: number
  /** How much less the part gives for it than a new one, in percent (50% of life left is 20% less). */
  condition_loss_percent: number
  /** What putting it right would cost and take: the parts of the inventory of the garage have it (those on the car have it in `MountedPart`). */
  repair_next?: RepairNext
  /** What merging it would do: null when it cannot be merged now (it is legendary, or waits in a job). Only the parts of the inventory of the garage have it. */
  merge?: MergeOffer | null
  /** What putting it on would cost and take, with the taking off of the part that sits in its slot: the parts of the inventory of the garage have it. */
  mount_next?: SlotJobNext | null
}

/** A part for sale, with what the car of the driver says about it. */
export interface ShopOffer extends PartInfo {
  required_car_level: number
  /** False when the car cannot have it mounted: its level is too low, or it lacks what `blocked_by` says. */
  mountable: boolean
  /** What the car lacks for the part besides its level (cylinders, a booster), or null. */
  blocked_by?: PartLock | null
}

/** The kinds of crate: the driver chooses the slot (slot, premium), or it is drawn (mixed). */
export type CrateKind = 'slot' | 'mixed' | 'premium'

/** The groups of crates have their own odds and guarantees. */
export type CrateGroup = 'standard' | 'premium'

/** A slot a crate can give a part of, at the level of the driver: the tier, the models, and the price when the slot is chosen. */
export interface CrateSlot {
  slot: string
  tier: number
  /** Null for a mixed crate: it has one price, whatever slot it gives. */
  price_cents: number | null
  models: { slug: string; name: string }[]
}

export interface CrateOffer {
  key: CrateKind
  group: CrateGroup
  min_player_level: number
  /** The level of the driver opens it, and there is a slot that it can give. */
  open: boolean
  choose_slot: boolean
  /** The price of a mixed crate; the others have a price per slot. */
  price_cents: number | null
  slots: CrateSlot[]
}

/** The odds of a group in percent, and how far the driver is from its guarantees. */
export interface CrateGuarantee {
  odds: Record<PartRarity, number>
  every: number
  min_rarity: PartRarity
  /** How many crates it is to the guarantee, counting the next one (1 when the next one is guaranteed). */
  until: number
  legendary_every: number
  legendary_until: number
}

export interface ShopCrates {
  level: number
  groups: Record<CrateGroup, CrateGuarantee>
  kinds: CrateOffer[]
  /** The driver may open their first crate for nothing (the tutorial gives it): every price of a crate is 0 while it is so. */
  free_first: boolean
}

/** GET /api/v1/shop */
export interface ShopResponse {
  data: { parts: ShopOffer[]; car: { id: number; level: number } | null; crates: ShopCrates }
  wallet: Wallet
}

/** The part that came out of a crate. */
export interface OpenedCrate extends OwnedPart {
  cost_cents: number
  required_car_level: number
  /** The car of the driver has the level to have it mounted. */
  mountable: boolean
}

/** POST /api/v1/shop/crates/{kind}/open. `opened` is null when the same request was made before: the crate was opened. */
export interface CrateOpenResponse {
  data: ShopResponse['data']
  wallet: Wallet
  repeated: boolean
  opened: OpenedCrate | null
}

/** POST /api/v1/shop/purchases. `data` is null when the same request was made before: the part is in the inventory already. */
export interface PurchaseResponse {
  data: OwnedPart | null
  purchase: { cost_cents: number; repeated: boolean }
  wallet: Wallet
}

export type CarStatKey = 'accel' | 'top_speed' | 'grip' | 'handling' | 'braking' | 'clearance'

/** What stops the next step of an upgrade: the part is as far as its rarity goes, the car is not of the level the step needs, or the part has no price to upgrade by. */
export type UpgradeLock = { reason: 'max' | 'unavailable' } | { reason: 'car_level'; required: number; current: number }

/** The next step of the upgrade of a part that sits on the car: the level it takes it to, its price and its time, and what stops it (`to` and the rest are null when nothing can be done). */
export interface UpgradeNext {
  to: number | null
  cost_cents: number | null
  duration_ms: number | null
  locked: UpgradeLock | null
}

/** A part that sits on the car, which says what the next step of its upgrade is and what putting it right would take. */
export interface MountedPart extends OwnedPart {
  upgrade_next: UpgradeNext
  repair_next: RepairNext
  /** What taking it off would cost and take. */
  unmount_next?: SlotJobNext | null
}

/**
 * The service of the car: it is due every `every_km`, `since_km` is how far it has driven since the last one and `due_in_km` how far it is from the next (less than
 * nothing when it is overdue, by `overdue_km`, which takes `penalty_percent` off the performance). A service costs `cost_cents` and takes `duration_ms`.
 */
export interface ServiceState {
  every_km: number
  since_km: number
  due_in_km: number
  overdue_km: number
  penalty_percent: number
  cost_cents: number
  duration_ms: number
}

export interface GarageSlot {
  slot: string
  /** What the slot is to the formulas of the game (`tires`, `engine`, ...), or null for a slot that has no role; the garage of an older server does not say. */
  role?: string | null
  part: MountedPart | null
  /** The slot holds a part of the body when it is empty (the factory engine): with an engine part on it, that part is in `part`. */
  built_in: boolean
}

export interface GarageCar {
  id: number
  level: number
  /** The experience of the car, and where its level begins and ends; `next_level_at` is null at the highest level. */
  xp: { total: number; level_start: number; next_level_at: number | null }
  /** The next tier of parts the car cannot have mounted yet, and the level that opens it, or null when no level does. */
  tier_unlock: { tier: number; level: number } | null
  vehicle: { slug: string; name: string; image_url?: string | null; engine: string | null; power_kw: number | null; drivetrain: 'fwd' | 'rwd' | 'awd' | null }
  /** With the parts that are on the car, and without them. */
  stats: Record<CarStatKey, number>
  base_stats: Record<CarStatKey, number>
  /** The value of a statistic at which its bar is full: what the best car of the whole game reaches. */
  ceilings: Record<CarStatKey, number>
  tank_ml: number
  /** What the odometer says. */
  mileage_km: number
  /** How good the car is as a whole, in percent: the mean of the condition of the parts that are on it (100 with none). */
  parts_condition_percent: number
  service: ServiceState
  /** How often it breaks down: the chance after a lap, in ppm (1 000 000 is certain), with its condition, the wear of its parts and its service counted. */
  breakdown: { chance_ppm: number }
  slots: GarageSlot[]
  lap_ms: Record<string, number> | null
  run: { id: number; status: RunStatus; stop_reason: StopReason | null } | null
}

export type JobKind = 'mount' | 'unmount' | 'upgrade' | 'merge' | 'repair' | 'service'

/** The items of equipment of a workshop; each has levels that are bought one after the other (level 0 is the start state). */
export type EquipmentItemKey = 'tire_machine' | 'lift' | 'hoist' | 'toolkit'

/** What can be bought for a workshop: an item of equipment, or one more bay or mechanic. */
export type EquipmentKey = EquipmentItemKey | 'bays' | 'mechanics'

/** An item of equipment of the workshop of a city, at the level it is. */
export interface EquipmentItem {
  key: EquipmentItemKey
  /** The slots it works on (tires, suspension, ...); null for the toolkit, which works on every job. */
  slots: string[] | null
  level: number
  max_level: number
  /** The name of the level it is at (`none`, `manual`, ...): the key of its text. */
  name: string
  /** What the time of a job on its slots is multiplied by at this level, in ppm (1 000 000 is the usual time). */
  time_ppm: number
  /** What every job on its slots costs on top while the workshop is at this level (the vulcanizer outside the workshop). */
  fee_cents: number
  /** The next level and what it costs, or null at the highest. */
  next: { name: string; time_ppm: number; price_cents: number } | null
}

/** The bays or the mechanics of a workshop: how many there are, how many there can be, and what one more costs (null at the most). */
export interface EquipmentCount {
  count: number
  max: number
  next_price_cents: number | null
}

/** What a workshop of a city has bought: the equipment, the bays and the mechanics. As many mechanics work at once as there are bays for them (`workers`). */
export interface WorkshopGear {
  workers: number
  queue_max: number
  items: EquipmentItem[]
  bays: EquipmentCount
  mechanics: EquipmentCount
}

/** A car of the account in the summary of a break: what it earned in it, and whether it waits for the driver (no fuel, broken down). */
export interface AwayCar {
  id: number | null
  name: string
  /** The car the driver plays: the card of the garage tells of it. */
  played: boolean
  earned_cents: number
  laps: number
  waiting: 'fuel_empty' | 'breakdown' | null
}

/** A job of the workshop that was done in the break. */
export interface AwayJob {
  kind: JobKind
  part: string | null
  finished_ms: number
}

/** A journey that ended in the break, with what the police made of a boosted one. */
export interface AwayJourney {
  to: string
  arrived_ms: number
  boosted: boolean
  police: { outcome: string; paid_cents: number } | null
}

/** What happened to the whole account while the driver was away (`pending: false` when there is no break to tell of). */
export interface AwayAccount {
  pending: boolean
  from_ms?: number
  to_ms?: number
  away_minutes?: number
  earned_cents?: number
  cars?: AwayCar[]
  jobs?: AwayJob[]
  journeys?: AwayJourney[]
}

/** GET /api/v1/away and POST /api/v1/away/seen. */
export interface AwayResponse {
  data: AwayAccount
  now_ms: number
}

/** What finishing a timer at once costs in premium coins, and what was left of it (ms) when the server answered. */
export interface Speedup {
  coins: number
  remaining_ms: number
}

/** A job of the workshop. The times are ms of the clock of the server. */
export interface WorkshopJob {
  id: number
  kind: JobKind
  /** What an upgrade, a merge, a repair or a service cost, paid when it was queued; the fee of the vulcanizer for a job on tires where the workshop has no tire machine (0 for the other jobs). */
  cost_cents: number
  /** The level an upgrade takes the part to (null for the other jobs). */
  upgrade_to: number | null
  /** A merge: the part that is used up with `part` (which is the one that stays), and the rarity the attempt aims at (null for the other jobs). */
  merge_with: OwnedPart | null
  merge_to: PartRarity | null
  /** Waiting for the mechanic, or being done (the car stands in the workshop, except for a merge and the repair of a part of the inventory, which are not done on the car). */
  status: 'queued' | 'working'
  /** The part the job is about: none for a service, which is a job on the car. */
  part: OwnedPart | null
  start_ms: number
  finish_ms: number
  /** What finishing the job at once costs: only for the job the mechanic is at, and null when the game sells nothing (absent in older answers). */
  speedup?: Speedup | null
}

/** What became of an attempt of merging, as the garage tells the last five. */
export interface MergeResult {
  id: number
  success: boolean
  slot: string
  tier: number
  /** The rarity the attempt aimed at. */
  to: PartRarity
  /** The model of the part, in the language of the driver. */
  name: string
  chance_ppm: number
  /** What a failure gave back (0 for a success). */
  scrap_cents: number
  /** The part that was made: null for a failure, and for a success whose part is gone. */
  part: OwnedPart | null
  finished_ms: number
}

/** GET /api/v1/garage, and the answer of POST /api/v1/garage/jobs. `now_ms` is the clock of the server. */
export interface GarageResponse {
  data: {
    car: GarageCar | null
    inventory: OwnedPart[]
    /** The last results of merging, the newest first. */
    merges: MergeResult[]
    /** The workshop of the city the car stands in: its queue, its mechanics (several work at once when it has bays for them) and what it has bought. */
    workshop: { city: { slug: string; name: string }; queue_max: number; mechanic: { level: number; time_cut_ppm: number }; gear: WorkshopGear; jobs: WorkshopJob[] }
  }
  /** What the driver has to spend as of this answer: what a job or a purchase has just cost is already taken off. */
  wallet: Wallet
  /** The premium coins of the driver (absent in older answers). */
  premium_coins?: number
  now_ms: number
}

/** POST /api/v1/garage/jobs/{job}/finish: the garage as it is now, the id of the job that was finished and the coins it cost; `repeated` says it was done before (nothing was charged). */
export interface FinishJobResponse extends GarageResponse {
  repeated: boolean
  finished: number | null
  spent_coins: number
}

/** POST /api/v1/garage/equipment: the garage as it is now; `repeated` says the purchase was made before (nothing was charged this time). */
export interface BuyEquipmentResponse extends GarageResponse {
  repeated: boolean
}

/** DELETE /api/v1/garage/jobs/{job}: the garage as it is now, and the ids of the jobs that were cancelled, the asked one first. */
export interface CancelJobResponse extends GarageResponse {
  cancelled: number[]
}

export type TutorialStatus = 'in_progress' | 'finished' | 'skipped'

/** The thing on a screen of the game that a step of the tutorial points at; a step that has no screen of its own (the locked parts) has none. */
export type TutorialTarget =
  | 'start'
  | 'laps'
  | 'cash'
  | 'refuel'
  | 'repair'
  | 'mount'
  | 'crate'
  | 'travel'
  | 'merge'
  | 'exchange'
  | 'service'
  | 'away'
  | 'upgrade'
  | 'equipment'
  | 'profile'
  | 'garages'

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
  /** The kind of tires the step waits to see on the car (`offroad`), or null when it names none. */
  tire_kind?: string | null
  /** The driver answered the messages: the phone is put away, but the step may still wait for them to do the thing. */
  acknowledged: boolean
  /** The driver may leave the step for later: the phone has a second button, with these words. */
  optional: boolean
  decline: string | null
}

/**
 * The goal in front of the driver at every screen of the game: the first step that is not passed, started or not, with what it will pay. `waiting` says what a
 * step that has not started waits for (rain on the forecast, the cash for a purchase), and is null for a step that has.
 */
export interface TutorialGoal {
  key: string
  /** The place of the step in the tutorial, from 1. */
  number: number
  /** 1 the first five minutes, 2 the first hour, 3 the first day, 4 the first week. */
  phase: number
  title: string
  waiting: string | null
  target: TutorialTarget | null
  /** The kind of tires the step waits to see on the car (`offroad`), or null when it names none. */
  tire_kind?: string | null
  optional: boolean
  /** What it pays: the names of the part and the garage are in the language of the driver. */
  reward: { cash_cents: number; car_xp: number; xp: number; part: string | null; garage: string | null }
}

/** GET /api/v1/tutorial, and the answer of POST /api/v1/tutorial/reply and /skip. */
export interface TutorialResponse {
  data: {
    status: TutorialStatus
    total: number
    done: number
    current: TutorialStep | null
    /** What to do next, started or not; null when the tutorial is over or was skipped. */
    goal: TutorialGoal | null
    /**
     * What the steps completed by this very call paid: cash, experience of the car and of the driver, a part that was put in the inventory (its slug), and
     * the city whose garage was given (its slug).
     */
    rewarded: { key: string; cash_cents: number; car_xp: number; xp: number; part: string | null; garage: string | null }[]
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
  track: { slug: string; name: string; kind: TrackKind; surface: 'asphalt' | 'gravel'; lit: boolean; base_lap_ms: number; demand: Record<string, number> }
  /** Every track of the city, in the order of the rules: the first is the one a car arrives on. */
  tracks: MapTrack[]
  /** How well the car, with its parts, runs the track in the dry: 100 is the lap of a car that matches what the track asks. Null without a car. */
  fit_percent: number | null
  /** The way from the city the car is in: null for that city, without a car and while the car is on the road. */
  /** What the way costs, and what a boosted journey would take and burn, and the risk of it (absent in older answers, null when the game has no boost). */
  route: { km: number; ms: number; fuel_ml: number; boost?: { ms: number; fuel_ml: number; police_chance_ppm: number } | null } | null
}

/** What a track asks of the driver: a lap of seconds that burns little, the usual one, or a lap of minutes that burns a tank in about twenty. */
export type TrackKind = 'sprint' | 'standard' | 'endurance'

/** A track of a city, and what a lap of it is for the car that is played (null without a car). */
export interface MapTrack {
  slug: string
  name: string
  kind: TrackKind
  surface: 'asphalt' | 'gravel'
  lit: boolean
  level_required: number
  /** The level of the driver opens it. */
  open: boolean
  /** The car drives it now. */
  current: boolean
  base_lap_ms: number
  demand: Record<string, number>
  car: MapTrackCar | null
}

/** The dry lap of the car on a track, and what it pays, burns and earns an hour after the fuel. Times in ms, fuel in ml, money in cents. */
export interface MapTrackCar {
  lap_ms: number
  cash_cents: number
  xp: number
  fuel_ml: number
  tank_ml: number
  /** False when the tank cannot hold the fuel of one lap: the car cannot drive the track. */
  fits_tank: boolean
  laps_per_tank: number
  /** How long a full tank lasts. */
  tank_ms: number
  gross_cents_per_hour: number
  fuel_cents_per_hour: number
  net_cents_per_hour: number
  xp_per_hour: number
  fit_percent: number
  position: number | null
  field_size: number | null
}

/** GET /api/v1/map, and the answer of POST /api/v1/travel. `now_ms` is the clock of the server. */
export interface MapResponse {
  data: {
    here: string
    level: number
    cities: MapCity[]
    roads: { from: string; to: string; km: number }[]
    travel: { from: string; to: string; distance_km: number; start_ms: number; arrive_ms: number; speedup?: Speedup | null; boosted?: boolean; police?: PoliceStop | null } | null
  }
  now_ms: number
  /** The premium coins of the driver (absent in older answers). */
  premium_coins?: number
}

/** What the police ask of a driver whose car they stopped on a boosted journey, and what came of it once the driver chose. */
export interface PoliceStop {
  at_ms: number
  decide_by_ms: number
  fine_cents: number
  status: 'pending' | 'resolved'
  choice: string | null
  outcome: 'paid' | 'bribed' | 'bribe_failed' | 'lawyered' | 'defaulted' | 'waived' | null
  paid_cents: number | null
  /** What each choice costs while the driver has not chosen (null after). */
  options: {
    pay: { cents: number; stop_ms: number }
    bribe: { cents: number; risk_ppm: number; fail_cents: number; fail_stop_ms: number }
    lawyer: { coins: number } | null
  } | null
}

export type PoliceChoice = 'pay' | 'bribe' | 'lawyer'

/** POST /api/v1/travel/police: the map as it is now, and what came of the choice; `repeated` says the same choice was made before. */
export interface PoliceChoiceResponse extends MapResponse {
  repeated: boolean
  police: { choice: string | null; outcome: PoliceStop['outcome']; paid_cents: number | null }
}

/** POST /api/v1/travel/finish: the map as it is now, and the coins it cost; `repeated` says it was done before (nothing was charged). */
export interface FinishTravelResponse extends MapResponse {
  repeated: boolean
  spent_coins: number
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
  /** What a sale of the car pays now, in cents. */
  sale_cents: number
  /** Why the car cannot be sold now: it is the last one the driver has, or it is on the road or has work in the workshop. */
  sell_blocked: 'last_car' | 'busy' | null
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
  /** The car that was sold, and what the driver was paid for it. */
  sold?: { car: number; cents: number }
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

/** What can be in a storage unit, from the worst to the best. */
export type StorageClass = 'scrap' | 'junk' | 'good' | 'gem'

/** The kinds of storage unit, from the cheapest. */
export type StorageKind = 'small' | 'medium' | 'large'

/** A kind of storage unit: what it costs, the chance of each thing in it (in percent), and how far the driver is from the guarantee of a good car. */
export interface StorageUnit {
  key: StorageKind
  unlock_level: number
  /** The level of the driver opens this kind of unit. */
  open: boolean
  price_cents: number
  odds: Record<StorageClass, number>
  /** How many units of this kind it takes at most to get a good car or better. */
  until_guarantee: number
}

/** What was in the unit that was opened: the car, how it looks, and what a sale of it pays. */
export interface StorageFind {
  class: StorageClass
  vehicle: MarketVehicle
  age_years: number
  mileage_km: number
  condition: { percent: number; defects: number }
  value_cents: number
  sale_cents: number
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
    storage: { pity_every: number; units: StorageUnit[] }
  }
  wallet: Wallet
  now_ms: number
  /** A purchase that came a second time bought nothing. */
  repeated?: boolean
  bought?: number | null
  /** The car found in a storage unit that was just opened, null when the request came a second time. */
  opened?: StorageFind | null
}
