import { useI18n } from 'vue-i18n'

/** The kind of body of a car in the language of the driver, or as the server calls it when there is no word for it. */
export function useCarClass() {
  const { t, te } = useI18n()

  const className = (slug: string | null | undefined): string | null => (slug ? (te(`fleet.class.${slug}`) ? t(`fleet.class.${slug}`) : slug) : null)

  return { className }
}
