/**
 * Hands a text to the browser to be saved as a file. The data of the driver is fetched with the device token, which
 * a plain link cannot carry, so it is made into a file here and offered from memory. Apart so tests can replace it.
 */
export function saveAsFile(filename: string, text: string, type = 'application/json'): void {
  const url = URL.createObjectURL(new Blob([text], { type }))
  const link = document.createElement('a')

  link.href = url
  link.download = filename
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()

  // The browser has the file by now; the address of it in memory is not needed any more.
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

/** The name of the file with the data of a driver: ride-idle-data-maciek_turbo-2026-10-23.json. */
export function dataFileName(driverName: string, day: Date): string {
  // The marks of a letter come off (ż becomes z), and the ł of Polish has none to take off.
  const safe = driverName
    .replace(/ł/g, 'l')
    .replace(/Ł/g, 'L')
    .normalize('NFKD')
    .replace(/[^\w-]+/g, '')
    .toLowerCase()
    .slice(0, 32)

  return `ride-idle-data-${safe === '' ? 'driver' : safe}-${day.toISOString().slice(0, 10)}.json`
}
