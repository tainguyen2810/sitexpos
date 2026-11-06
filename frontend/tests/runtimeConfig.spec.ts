import { afterEach, describe, expect, it } from 'vitest'
import { apiBaseUrl, customerAppUrl } from '@/shared/config/runtime'

describe('runtime config (Docker /config.js)', () => {
  afterEach(() => {
    delete window.__SITEX_CONFIG__
  })

  it('uses window.__SITEX_CONFIG__ and strips trailing slashes', () => {
    window.__SITEX_CONFIG__ = { apiBaseUrl: 'https://api.example.test/', customerAppUrl: 'https://app.example.test//' }
    expect(apiBaseUrl()).toBe('https://api.example.test')
    expect(customerAppUrl()).toBe('https://app.example.test')
  })

  it('falls back to build-time values when /config.js sets nothing', () => {
    window.__SITEX_CONFIG__ = {}
    expect(apiBaseUrl()).toBe((import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/+$/, ''))
    expect(customerAppUrl()).toBe((import.meta.env.VITE_CUSTOMER_APP_URL ?? '').replace(/\/+$/, ''))
  })
})
