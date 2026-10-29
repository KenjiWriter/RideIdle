import { describe, expect, it } from 'vitest'

import { carCutout, carPicture, stageArt } from './art'

describe('the pictures of the cars that the app brings', () => {
  it('has each of the cars of the canvas twice: with its dark ground and cut out of it', () => {
    for (const slug of ['rdzak-gt-4', 'bizon-m-2', 'kuna-4x4']) {
      expect(carPicture(slug)).toBeTruthy()
      expect(carCutout(slug)).toBeTruthy()
      expect(carCutout(slug)).not.toBe(carPicture(slug))
    }
  })

  it('has nothing for a car it does not know', () => {
    expect(carPicture('somebody-elses-car')).toBeNull()
    expect(carCutout('somebody-elses-car')).toBeNull()
    expect(carCutout(null)).toBeNull()
    expect(carCutout(undefined)).toBeNull()
  })
})

describe('what a stage shows', () => {
  it('is the car cut out of its ground, on the light stage, when the pack gives no picture', () => {
    expect(stageArt(null, 'bizon-m-2')).toEqual({ picture: carCutout('bizon-m-2'), cutout: true })
    expect(stageArt(undefined, 'bizon-m-2')).toEqual({ picture: carCutout('bizon-m-2'), cutout: true })
  })

  it('is the dark stage and the picture with its ground when the pack gives a picture, which brings a ground of its own', () => {
    expect(stageArt('/api/v1/media/aaaaaaaa.png', 'bizon-m-2')).toEqual({ picture: carPicture('bizon-m-2'), cutout: false })
  })

  it('is nothing for a car without a picture, which stands on the dark stage as the outline of a body', () => {
    expect(stageArt(null, 'somebody-elses-car')).toEqual({ picture: null, cutout: false })
    expect(stageArt(null, null)).toEqual({ picture: null, cutout: false })
  })
})
