import { createI18n } from 'vue-i18n'
import vi from '@/locales/vi'
import en from '@/locales/en'

export const SUPPORTED_LOCALES = ['vi', 'en'] as const
export type Locale = (typeof SUPPORTED_LOCALES)[number]

const STORAGE_KEY = 'sitex.locale'

function initialLocale(): Locale {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved === 'vi' || saved === 'en') return saved
  } catch {
    /* storage unavailable */
  }
  return 'vi'
}

export const i18n = createI18n({
  legacy: false,
  locale: initialLocale(),
  fallbackLocale: 'vi',
  messages: { vi, en },
  missingWarn: false,
  fallbackWarn: false,
})

export function setLocale(locale: string): void {
  if (locale !== 'vi' && locale !== 'en') return
  i18n.global.locale.value = locale
  document.documentElement.lang = locale
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    /* ignore */
  }
}

export function currentLocale(): Locale {
  return i18n.global.locale.value as Locale
}
