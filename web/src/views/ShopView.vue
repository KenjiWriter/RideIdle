<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { ApiError } from '@/api/client'
import type { CrateGroup, CrateOffer, CrateSlot, OpenedCrate, ShopOffer } from '@/api/types'
import CarHotspots from '@/components/CarHotspots.vue'
import CarStage from '@/components/CarStage.vue'
import CityTabs from '@/components/CityTabs.vue'
import GameShell from '@/components/GameShell.vue'
import PartAffixes from '@/components/PartAffixes.vue'
import MediaPicture from '@/components/MediaPicture.vue'
import PartCompare from '@/components/PartCompare.vue'
import PartStats from '@/components/PartStats.vue'
import RarityMark from '@/components/RarityMark.vue'
import SlotDetail from '@/components/SlotDetail.vue'
import { carPicture } from '@/features/art'
import { useCarSpots } from '@/features/car/useCarSpots'
import { lockText } from '@/features/parts/lock'
import { RARITIES, rankOf } from '@/features/parts/rarity'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { useFormat } from '@/i18n/useFormat'
import { useModel } from '@/model'
import { useGarageStore } from '@/stores/garage'
import { useTutorialStore } from '@/stores/tutorial'

const { t } = useI18n()
const { money, percent, fixed } = useFormat()
const store = useGarageStore()
const tutorial = useTutorialStore()

useLeaveOnSignOut()

const loadError = ref(false)
/** What is being bought: a part, or a crate. One purchase at a time. */
const buying = ref<string | null>(null)
const { message: done, show: showDone, clear: clearDone } = useFlash()
const error = ref<string | null>(null)

/** The slot the driver chose for each kind of crate that has a choice; the first one until they choose. */
const chosen = ref<Record<string, string>>({})
/** What the last crate held, until another is opened. */
const found = ref<OpenedCrate | null>(null)
const foundCard = ref<HTMLElement | null>(null)

const kinds = computed(() => store.crates?.kinds ?? [])
/** The crate that the tutorial points at when it asks for one to be opened: the first the driver can open. */
const crateTarget = computed(() => kinds.value.find((kind) => kind.open)?.key ?? null)
/** The groups of crates the driver can open, with their odds and the count to the guarantee. */
const groups = computed(() => (Object.keys(store.crates?.groups ?? {}) as CrateGroup[]).filter((group) => kinds.value.some((kind) => kind.group === group && kind.open)))

async function open(): Promise<void> {
  loadError.value = false

  try {
    await store.loadShop()
  } catch (failure) {
    if (!endSessionIfUnauthorized(failure)) {
      loadError.value = true
    }
  }
}

onMounted(() => {
  void open()
  // The car on the stage is the one of the garage: the shop knows only its level. A garage that cannot be read is a shop without a stage, not a shop that does not open.
  void store.loadGarage().catch(() => undefined)
})

/** The car of the garage, for the stage, the spots and the parts that are fitted (to set the parts for sale against). */
const stageCar = computed(() => store.garage?.car ?? null)
const hasPicture = computed(() => !!stageCar.value && !!(stageCar.value.vehicle.image_url || carPicture(stageCar.value.vehicle.slug)))
const {
  pickedSlot,
  picked,
  mounted: mountedCount,
  parts,
  pick,
  clear: clearPick,
} = useCarSpots(() => (stageCar.value ? { slots: stageCar.value.slots, slug: stageCar.value.vehicle.slug, hasPicture: hasPicture.value } : null))

/** The slots in the order of the model of the game (the garage shows them in it): the parts of the shop are listed under their slot. */
const { slotKeys } = useModel()

/** The slot the driver narrows the list of parts to (the one tapped on the car, or chosen from the row), or all of them. */
const slotFilter = computed(() => pickedSlot.value ?? 'all')
const partsAnchor = ref<HTMLElement | null>(null)

/** A tap on a spot of the car narrows the list to that slot and brings the list into view; a tap on the same spot shows all the parts again. */
async function pickSpot(slot: string): Promise<void> {
  pick(slot)

  if (pickedSlot.value !== null) {
    await nextTick()
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false

    partsAnchor.value?.scrollIntoView?.({ block: 'start', behavior: reduced ? 'auto' : 'smooth' })
  }
}

/** A chip of the row sets the slot: a chip that is on stays on when it is tapped again, and the chip of all the parts is what turns the filter off. */
function chooseSlot(slot: string): void {
  if (pickedSlot.value !== slot) {
    pick(slot)
  }
}

/** The row of chips has the slots that have no place on the picture (all of them with no stage), and the slot that is chosen: the rest is on the car. */
const placedSlots = computed(() => new Set(parts.value.placed.map((entry) => entry.slot.slot)))
const chipSlots = computed(() => partSlots.value.filter((slot) => !placedSlots.value.has(slot) || slot === slotFilter.value))
const showFilter = computed(() => partSlots.value.length > 1 && (chipSlots.value.length > 0 || slotFilter.value !== 'all'))
const forSlotCount = computed(() => store.offers.filter((offer) => offer.slot === pickedSlot.value).length)

/** The part that is fitted on the slot of an offer, to set the offer against; none when the slot holds the factory part or the car is not known. */
function fittedOf(offer: ShopOffer): { name: string; stats: Record<string, number> } | null {
  const part = stageCar.value?.slots.find((slot) => slot.slot === offer.slot)?.part

  return part ? { name: part.name, stats: part.stats } : null
}

/** The slots that have a part for sale, in order. */
const partSlots = computed(() => slotKeys.value.filter((slot) => store.offers.some((offer) => offer.slot === slot)))

/** The parts for sale by slot: the shop grows as the car rises, so the list is told apart by what the parts are. */
const offerGroups = computed(() =>
  partSlots.value
    .filter((slot) => slotFilter.value === 'all' || slotFilter.value === slot)
    .map((slot) => ({ slot, offers: store.offers.filter((offer) => offer.slot === slot) })),
)

/** Whether the level of the car is what stands between it and the part (the cylinders and the booster are told apart). */
function levelLocked(offer: ShopOffer): boolean {
  return store.shopCar !== null && store.shopCar.level < offer.required_car_level
}

function affordable(offer: ShopOffer): boolean {
  return (store.cash?.cash_cents ?? 0) >= (offer.price_cents ?? 0)
}

/** The slot of a crate that the driver has chosen, or the first it can give. */
function slotOf(kind: CrateOffer): CrateSlot | null {
  return kind.slots.find((slot) => slot.slot === chosen.value[kind.key]) ?? kind.slots[0] ?? null
}

/** What a crate costs: one price for a mixed crate, and one per slot for the others. */
function priceOf(kind: CrateOffer): number {
  return (kind.choose_slot ? slotOf(kind)?.price_cents : kind.price_cents) ?? 0
}

function crateAffordable(kind: CrateOffer): boolean {
  return (store.cash?.cash_cents ?? 0) >= priceOf(kind)
}

/** The odds of a kind in the order of the rarities, with their share of the bar. */
function oddsOf(kind: CrateOffer): { rarity: (typeof RARITIES)[number]; percent: number }[] {
  const odds = store.crates?.groups[kind.group].odds

  return odds ? RARITIES.map((rarity) => ({ rarity, percent: odds[rarity] })) : []
}

/** How the answer of the server to a failed purchase is told: in its own words when it refuses, and in ours when it is the network. */
function failWith(failure: unknown): void {
  if (endSessionIfUnauthorized(failure)) {
    return
  }

  // The server says what is wrong in the language of the driver: not enough cash, a part that is not for sale, a level that is too low.
  error.value =
    failure instanceof ApiError && failure.status === 429
      ? t('common.tooManyRequests')
      : failure instanceof ApiError && failure.status === 422
        ? failure.message
        : t('common.error')
}

async function buy(offer: ShopOffer): Promise<void> {
  if (buying.value !== null) {
    return
  }

  buying.value = offer.slug
  clearDone()
  error.value = null

  try {
    const result = await store.buy(offer.slug)

    showDone(result.purchase.repeated ? t('shop.repeated') : t('shop.bought', { name: offer.name }))
  } catch (failure) {
    failWith(failure)
  } finally {
    buying.value = null
  }
}

async function openCrate(kind: CrateOffer): Promise<void> {
  if (buying.value !== null) {
    return
  }

  buying.value = `crate-${kind.key}`
  clearDone()
  error.value = null

  try {
    const opened = await store.openCrate(kind.key, kind.choose_slot ? (slotOf(kind)?.slot ?? null) : null)
    // The server moves the tutorial when it is asked, and a crate that was opened is what a step waits for.
    void tutorial.load().catch(() => undefined)

    if (opened) {
      found.value = opened
      // The crate that was opened may be far down the page, and what it held is on top.
      await nextTick()
      foundCard.value?.scrollIntoView?.({ block: 'center', behavior: 'smooth' })
    }
  } catch (failure) {
    failWith(failure)
  } finally {
    buying.value = null
  }
}
</script>

<template>
  <GameShell>
    <header class="flex flex-col gap-1">
      <h1 class="ttl">{{ t('shop.title') }}</h1>
      <p class="sub">{{ t('shop.intro') }}</p>
      <p v-if="store.cash" class="sr-only" data-test="cash">{{ t('shop.cash', { money: money(store.cash.cash_cents, 2) }) }}</p>
    </header>

    <CityTabs />

    <div v-if="loadError" class="flex flex-col items-start gap-3" data-test="load-error">
      <p class="text-danger" role="alert">{{ t('shop.loadError') }}</p>
      <button type="button" class="btn btn-secondary" data-test="retry" @click="open">{{ t('common.retry') }}</button>
    </div>

    <p v-else-if="!store.shopLoaded" class="text-muted" data-test="loading">{{ t('common.loading') }}</p>

    <template v-else>
      <div v-if="done" class="card card-cy flex flex-col gap-2 !p-3" role="status" data-test="done">
        <p class="kick !mb-0">{{ t('shop.last') }}</p>
        <p class="font-semibold">{{ done }}</p>
        <RouterLink :to="{ name: 'workshop' }" class="btn btn-primary btn-sm" data-test="to-workshop">{{ t('shop.toWorkshop') }}</RouterLink>
      </div>
      <p v-if="error" class="text-danger" role="alert" data-test="error">{{ error }}</p>

      <!-- The car of the driver on its stage: a tap on a part of it shows what is for sale for that part, and what is on the car now. -->
      <CarStage v-if="stageCar" :name="stageCar.vehicle.name" :src="stageCar.vehicle.image_url" :fallback="carPicture(stageCar.vehicle.slug)">
        <template #spots>
          <CarHotspots v-if="parts.placed.length > 0" :placed="parts.placed" :picked="pickedSlot" :engine="stageCar.vehicle.engine" @pick="pickSpot" />
        </template>
        <span class="chip chip-am !h-6 !text-[11px]" data-test="stage-level">{{ t('garage.carLevel', { level: stageCar.level }) }}</span>
      </CarStage>

      <SlotDetail v-if="stageCar && picked" :slot="picked" :engine="stageCar.vehicle.engine" :mounted="mountedCount" :total="stageCar.slots.length" :link="false" @close="clearPick">
        <p class="sub" data-test="shop-slot-note">{{ forSlotCount > 0 ? t('shop.forSlot', { count: forSlotCount }) : t('shop.noneForSlot') }}</p>
      </SlotDetail>
      <p v-else-if="stageCar && parts.placed.length > 0" class="sub" data-test="shop-hint">{{ t('shop.spotHint') }}</p>

      <p v-if="!store.shopCar" class="text-muted" data-test="no-car">{{ t('shop.noCar') }}</p>

      <section v-if="kinds.length > 0" class="flex flex-col gap-2.5" :aria-label="t('shop.crates.title')" data-test="crates">
        <h2 class="kick !mb-0">{{ t('shop.crates.title') }}</h2>

        <section
          v-if="found"
          ref="foundCard"
          class="card flex flex-col gap-2 !p-3"
          :style="{ borderColor: `var(--color-r${rankOf(found.rarity)})` }"
          role="status"
          :data-rarity="found.rarity"
          data-test="found"
        >
          <p class="kick !mb-0">{{ t('shop.crates.last') }}</p>
          <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            <b class="text-base text-ink" data-test="found-name">{{ found.name }}</b>
            <RarityMark :rarity="found.rarity" />
            <span class="tag">{{ t('part.tier', { tier: found.tier ?? 0 }) }}</span>
          </div>
          <p class="sub">{{ t(`slot.${found.slot}`) }}</p>
          <PartStats :part="found" />
          <PartAffixes :part="found" />
          <p v-if="!found.mountable && store.shopCar" class="text-sm text-danger" data-test="found-needs-level">
            {{ t('shop.needsLevel', { level: found.required_car_level }) }} {{ t('shop.yourLevel', { level: store.shopCar.level }) }}
          </p>
          <RouterLink :to="{ name: 'workshop' }" class="btn btn-primary btn-sm" data-test="found-to-workshop">{{ t('shop.toWorkshop') }}</RouterLink>
        </section>

        <!-- The three kinds of crate in a strip that is swiped sideways, a card each; the next one peeks out from the edge. -->
        <div class="rail crates-rail" role="group" :aria-label="t('shop.crates.title')" data-test="crate-rail">
          <article v-for="kind in kinds" :key="kind.key" class="card crate !p-3" :class="{ 'opacity-90': !kind.open }" :data-test="`crate-${kind.key}`">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <h3 class="text-[15px] font-bold text-ink" data-test="crate-name">{{ t(`shop.crates.kind.${kind.key}`) }}</h3>
                <span v-if="kind.choose_slot && slotOf(kind)" class="tag" data-test="crate-tier">{{ t('part.tier', { tier: slotOf(kind)?.tier ?? 0 }) }}</span>
              </div>
              <p class="sub">{{ t(`shop.crates.note.${kind.key}`) }}</p>
            </div>

            <div v-if="kind.open && kind.choose_slot" class="flex flex-wrap gap-1.5" role="radiogroup" :aria-label="t('shop.crates.slot')" data-test="crate-slots">
              <button
                v-for="slot in kind.slots"
                :key="slot.slot"
                type="button"
                role="radio"
                class="chip cursor-pointer"
                :class="{ 'chip-am': slotOf(kind)?.slot === slot.slot }"
                :aria-checked="slotOf(kind)?.slot === slot.slot"
                :data-test="`crate-slot-${slot.slot}`"
                @click="chosen[kind.key] = slot.slot"
              >
                {{ t(`slot.${slot.slot}`) }}
              </button>
            </div>

            <template v-if="kind.open">
              <div class="flex h-2 gap-0.5" aria-hidden="true" data-test="crate-bar">
                <i v-for="odd in oddsOf(kind)" :key="odd.rarity" class="rounded-[2px] bg-current" :class="`r${rankOf(odd.rarity)}`" :style="{ flexGrow: odd.percent, flexBasis: 0 }" />
              </div>
              <p class="flex flex-wrap gap-x-2.5 gap-y-0.5 font-mono text-[10.5px] text-faint" data-test="crate-odds">
                <span v-for="odd in oddsOf(kind)" :key="odd.rarity" :data-test="`odds-${odd.rarity}`">{{ t('shop.crates.odds', { name: t(`rarity.${odd.rarity}`), percent: percent(odd.percent) }) }}</span>
              </p>
            </template>

            <button
              v-if="kind.open"
              type="button"
              class="btn btn-primary btn-sm mt-auto"
              :disabled="buying !== null || !crateAffordable(kind)"
              :data-tutorial="kind.key === crateTarget ? 'crate' : undefined"
              data-test="crate-open"
              @click="openCrate(kind)"
            >
              {{ buying === `crate-${kind.key}` ? t('shop.buying') : store.crates?.free_first ? t('shop.crates.free') : t('shop.crates.buy', { price: money(priceOf(kind), 2) }) }}
            </button>
            <button v-else type="button" class="btn btn-secondary btn-sm mt-auto" disabled data-test="crate-locked">{{ t('shop.crates.locked', { level: kind.min_player_level }) }}</button>
          </article>
        </div>

        <!-- What is left to the guarantees, as slim bars under the strip. -->
        <article v-for="group in groups" :key="group" class="card flex flex-col gap-1 !p-2.5" :data-test="`guarantee-${group}`">
          <div class="flex justify-between gap-2 text-xs text-muted">
            <span data-test="guarantee-text">{{ t('shop.crates.guarantee', { rarity: t(`rarity.${store.crates!.groups[group].min_rarity}`), count: store.crates!.groups[group].until }) }}</span>
            <span class="font-mono text-ink" data-test="guarantee-count">{{ store.crates!.groups[group].every - store.crates!.groups[group].until }} / {{ store.crates!.groups[group].every }}</span>
          </div>
          <div class="bar !h-1.5" aria-hidden="true">
            <i :style="{ width: `${(100 * (store.crates!.groups[group].every - store.crates!.groups[group].until)) / store.crates!.groups[group].every}%` }" />
          </div>
          <p class="sub !text-xs" data-test="legendary-text">{{ t('shop.crates.legendary', { count: store.crates!.groups[group].legendary_until }) }}</p>
        </article>
      </section>

      <h2 ref="partsAnchor" class="kick !mb-0 scroll-mt-32">{{ t('shop.partsTitle') }}</h2>

      <!-- With the engine group the list is long: the parts are told apart by slot, and one slot can be looked at alone. -->
      <div v-if="showFilter" class="flex flex-wrap gap-1.5" role="radiogroup" :aria-label="t('shop.filter')" data-test="part-filter">
        <button
          type="button"
          role="radio"
          class="chip cursor-pointer"
          :class="{ 'chip-am': slotFilter === 'all' }"
          :aria-checked="slotFilter === 'all'"
          data-test="filter-all"
          @click="clearPick"
        >
          {{ t('shop.allSlots') }}
        </button>
        <button
          v-for="slot in chipSlots"
          :key="slot"
          type="button"
          role="radio"
          class="chip cursor-pointer"
          :class="{ 'chip-am': slotFilter === slot }"
          :aria-checked="slotFilter === slot"
          :data-test="`filter-${slot}`"
          @click="chooseSlot(slot)"
        >
          {{ t(`slot.${slot}`) }}
        </button>
      </div>

      <section v-for="group in offerGroups" :key="group.slot" class="flex flex-col gap-2.5" :aria-label="t(`slot.${group.slot}`)" :data-test="`group-${group.slot}`">
        <h3 v-if="partSlots.length > 1" class="kick !mb-0 mt-1" data-test="group-title">{{ t(`slot.${group.slot}`) }}</h3>

        <ul class="flex flex-col gap-2.5">
          <li v-for="offer in group.offers" :key="offer.slug" class="card flex flex-col gap-1.5 !p-3" :data-test="`offer-${offer.slug}`">
            <div class="flex items-center justify-between gap-2.5">
              <div class="flex min-w-0 items-center gap-2.5">
                <MediaPicture :src="offer.image_url" :alt="offer.name" class="h-12 w-12 flex-none rounded-md border border-line-2 object-cover" data-test="part-picture" />
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <h2 class="text-[15px] font-bold text-ink">{{ offer.name }}</h2>
                    <span class="tag">{{ t('part.tier', { tier: offer.tier ?? 0 }) }}</span>
                  </div>
                  <p class="sub">{{ t(`slot.${offer.slot}`) }}</p>
                </div>
              </div>
              <button
                type="button"
                class="btn btn-primary btn-sm shrink-0"
                :disabled="buying !== null || !affordable(offer)"
                :data-test="`buy-${offer.slug}`"
                @click="buy(offer)"
              >
                {{ buying === offer.slug ? t('shop.buying') : t('shop.buy', { price: money(offer.price_cents ?? 0, 2) }) }}
              </button>
            </div>

            <PartStats :part="offer" />
            <!-- Against the part on the same slot of the car, like for like. -->
            <PartCompare :offer="offer" :fitted="fittedOf(offer)" />

            <p v-if="!affordable(offer)" class="sub" data-test="no-cash">{{ t('shop.noCash') }}</p>
            <p v-if="store.shopCar && levelLocked(offer)" class="text-sm text-danger" data-test="needs-level">
              {{ t('shop.needsLevel', { level: offer.required_car_level }) }} {{ t('shop.yourLevel', { level: store.shopCar.level }) }} {{ t('shop.getXp') }}
            </p>
            <!-- What the car lacks besides its level: the cylinders of its engine, or a booster. It can be bought, but it cannot be put on yet. -->
            <p v-if="store.shopCar && offer.blocked_by" class="text-sm text-danger" data-test="blocked">{{ lockText(t, offer.blocked_by, fixed) }}</p>
          </li>
        </ul>
      </section>
    </template>
  </GameShell>
</template>
