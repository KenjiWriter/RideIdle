import bizon from '@/assets/art/bizon-m-2.jpg'
import kuna from '@/assets/art/kuna-4x4.jpg'
import rdzak from '@/assets/art/rdzak-gt-4.jpg'

/** The pictures of the cars, from the design canvas. A car that has none yet is shown without one. */
const pictures: Record<string, string> = {
  'rdzak-gt-4': rdzak,
  'bizon-m-2': bizon,
  'kuna-4x4': kuna,
}

export function carPicture(slug: string): string | null {
  return pictures[slug] ?? null
}
