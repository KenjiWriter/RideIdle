/** Sends the whole page to another address, such as the sign-in page of a provider. Apart so tests can replace it. */
export function goTo(url: string): void {
  window.location.assign(url)
}
