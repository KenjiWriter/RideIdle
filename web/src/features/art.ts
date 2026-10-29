import bizon from '@/assets/art/bizon-m-2.jpg'
import bizonCutout from '@/assets/art/bizon-m-2-cutout.webp'
import kuna from '@/assets/art/kuna-4x4.jpg'
import kunaCutout from '@/assets/art/kuna-4x4-cutout.webp'
import rdzak from '@/assets/art/rdzak-gt-4.jpg'
import rdzakCutout from '@/assets/art/rdzak-gt-4-cutout.webp'

/** The pictures of the cars, from the design canvas, with the dark ground they were drawn on. A car that has none yet is shown without one. */
const pictures: Record<string, string> = {
  'rdzak-gt-4': rdzak,
  'bizon-m-2': bizon,
  'kuna-4x4': kuna,
}

/** The same cars cut out of their ground (transparent), so that they stand on the light stage with a shadow of the stage's own. */
const cutouts: Record<string, string> = {
  'rdzak-gt-4': rdzakCutout,
  'bizon-m-2': bizonCutout,
  'kuna-4x4': kunaCutout,
}

export function carPicture(slug: string): string | null {
  return pictures[slug] ?? null
}

/** The picture of a car without its ground, or null when the app has none: the stage uses it in place of the picture with the ground. */
export function carCutout(slug: string | null | undefined): string | null {
  return slug ? (cutouts[slug] ?? null) : null
}

/**
 * What a stage shows when the pack gives no picture for the car: the car cut out of its ground when the app has it (`cutout`, and the stage is light for it), else the
 * picture with the dark ground it was drawn on, else nothing. A picture of a pack has a ground of its own, so it keeps the dark stage and gets nothing from here.
 */
export function stageArt(packPicture: string | null | undefined, slug: string | null | undefined): { picture: string | null; cutout: boolean } {
  const cut = packPicture ? null : carCutout(slug)

  return { picture: cut ?? (slug ? carPicture(slug) : null), cutout: !!cut }
}
