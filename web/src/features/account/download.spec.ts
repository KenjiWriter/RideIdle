import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { dataFileName, saveAsFile } from './download'

describe('saveAsFile', () => {
  const createObjectURL = vi.fn((_blob: Blob) => 'blob:ride-idle-test')
  const revokeObjectURL = vi.fn()
  let clicked: HTMLAnchorElement[]

  beforeEach(() => {
    vi.useFakeTimers()
    createObjectURL.mockClear()
    revokeObjectURL.mockClear()
    // jsdom has no object URLs, and a click on a link would try to navigate.
    Object.assign(URL, { createObjectURL, revokeObjectURL })
    clicked = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      clicked.push(this)
    })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('offers the text to the browser as a file with the name given', () => {
    saveAsFile('data.json', '{"a":1}')

    expect(clicked).toHaveLength(1)
    expect(clicked[0]?.download).toBe('data.json')
    expect(clicked[0]?.href).toBe('blob:ride-idle-test')
  })

  it('makes the file out of the text, as JSON unless it is told otherwise', async () => {
    saveAsFile('data.json', '{"a":1}')

    const blob = createObjectURL.mock.calls[0]?.[0] as unknown as Blob
    expect(blob.type).toBe('application/json')
    expect(await blob.text()).toBe('{"a":1}')
  })

  it('leaves no link behind in the page', () => {
    saveAsFile('data.json', '{}')

    expect(document.querySelector('a[download]')).toBeNull()
  })

  it('lets go of the address in memory once the browser has the file', () => {
    saveAsFile('data.json', '{}')
    expect(revokeObjectURL).not.toHaveBeenCalled()

    vi.runAllTimers()

    expect(revokeObjectURL).toHaveBeenCalledWith('blob:ride-idle-test')
  })
})

describe('dataFileName', () => {
  const day = new Date('2026-10-23T23:30:00Z')

  it('names the file after the driver and the day', () => {
    expect(dataFileName('Maciek_Turbo', day)).toBe('ride-idle-data-maciek_turbo-2026-10-23.json')
  })

  it('keeps only what a file name can hold, and the letters of a name without their marks', () => {
    expect(dataFileName('Zażółć Gęślą/..\\x', day)).toBe('ride-idle-data-zazolcgeslax-2026-10-23.json')
  })

  it('does not let a name make a path of the file name', () => {
    const name = dataFileName('../../etc/passwd', day)

    expect(name).not.toContain('/')
    expect(name).not.toContain('..')
    expect(name).toMatch(/^ride-idle-data-[a-z0-9_-]+-2026-10-23\.json$/)
  })

  it('is short whatever the name is, and has a word of its own when nothing is left of it', () => {
    expect(dataFileName('a'.repeat(100), day).length).toBeLessThan(70)
    expect(dataFileName('???', day)).toBe('ride-idle-data-driver-2026-10-23.json')
    expect(dataFileName('', day)).toBe('ride-idle-data-driver-2026-10-23.json')
  })

  it('takes the day in UTC, so the file name does not depend on the time zone of the machine', () => {
    expect(dataFileName('Turbo', new Date('2026-12-31T23:59:59Z'))).toBe('ride-idle-data-turbo-2026-12-31.json')
  })
})
