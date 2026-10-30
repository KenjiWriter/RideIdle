import { DEFAULT_GAME, type GameInfo } from '@/game'
import { parseUnits } from '@/game/units'

/** The units of a game of ships, as the server sends them: cables for a lap, nautical miles for the roads, knots for the speed, and fuel per nautical mile. */
export const NAUTICAL_UNITS = parseUnits({
  lap: { name: { en: 'cable', pl: 'kabel' }, short: { en: 'cb' }, metres: 185.2, decimals: 1 },
  travel: { name: { en: 'nautical mile', pl: 'mila morska' }, short: { en: 'nm' }, metres: 1852, decimals: 1 },
  speed: { name: { en: 'knot', pl: 'wezel' }, short: { en: 'kn' }, kmh: 1.852, decimals: 0 },
  fuel_economy_per: 1,
})

/** A game of ships that counts in them: put it in force with `setGame`, and take it out again with `setGame()`. */
export function aShipsGame(): GameInfo {
  return { ...DEFAULT_GAME, slug: 'ships', name: 'Star Ships', is_default: false, units: NAUTICAL_UNITS }
}
