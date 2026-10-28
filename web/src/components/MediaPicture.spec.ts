import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import { mediaUrl } from '@/api/client'

import MediaPicture from './MediaPicture.vue'

const packPicture = '/api/v1/media/aaaaaaaa.png'

function picture(props: { src: string | null | undefined; fallback?: string | null }, slots: Record<string, string> = {}) {
  return mount(MediaPicture, { props: { alt: 'Rdzak GT-4', ...props }, attrs: { class: 'pic', 'data-test': 'picture' }, slots })
}

describe('MediaPicture', () => {
  it('shows the picture that the pack gives, with the attributes of the place it is put in', () => {
    const wrapper = picture({ src: packPicture, fallback: '/bundled.jpg' })

    const img = wrapper.get('img')
    expect(img.attributes('src')).toBe(packPicture)
    expect(img.attributes('alt')).toBe('Rdzak GT-4')
    expect(img.attributes('data-test')).toBe('picture')
    expect(img.classes()).toContain('pic')
  })

  it('shows the picture of the app when the pack gives none', () => {
    expect(picture({ src: null, fallback: '/bundled.jpg' }).get('img').attributes('src')).toBe('/bundled.jpg')
    expect(picture({ src: undefined, fallback: '/bundled.jpg' }).get('img').attributes('src')).toBe('/bundled.jpg')
  })

  it('goes back to the picture of the app when the file of the pack is not there', async () => {
    const wrapper = picture({ src: packPicture, fallback: '/bundled.jpg' })

    await wrapper.get('img').trigger('error')

    expect(wrapper.get('img').attributes('src')).toBe('/bundled.jpg')
  })

  it('shows what the place keeps for it when there is no picture at all, and nothing when it keeps nothing', async () => {
    const withPlaceholder = picture({ src: null }, { placeholder: '<div data-test="placeholder" />' })
    expect(withPlaceholder.find('img').exists()).toBe(false)
    expect(withPlaceholder.find('[data-test="placeholder"]').exists()).toBe(true)

    const bare = picture({ src: packPicture })
    await bare.get('img').trigger('error')
    expect(bare.find('img').exists()).toBe(false)
  })

  it('tries a new picture when it is given another', async () => {
    const wrapper = picture({ src: packPicture })
    await wrapper.get('img').trigger('error')
    expect(wrapper.find('img').exists()).toBe(false)

    await wrapper.setProps({ src: '/api/v1/media/bbbbbbbb.webp' })

    expect(wrapper.get('img').attributes('src')).toBe('/api/v1/media/bbbbbbbb.webp')
  })
})

describe('mediaUrl', () => {
  it('is the path of the API on this host, and nothing without a path', () => {
    expect(mediaUrl(packPicture)).toBe(packPicture)
    expect(mediaUrl(null)).toBeNull()
    expect(mediaUrl(undefined)).toBeNull()
    expect(mediaUrl('')).toBeNull()
  })
})
