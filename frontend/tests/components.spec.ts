import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { i18n } from '@/shared/i18n'
import { ApiError } from '@/shared/api/client'
import SafeHtml from '@/shared/ui/SafeHtml.vue'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorState from '@/shared/ui/ErrorState.vue'

const global = { plugins: [i18n] }

describe('SafeHtml', () => {
  it('strips scripts and event handlers', () => {
    const w = mount(SafeHtml, { props: { html: '<p onclick="alert(1)">ok</p><script>alert(2)</script><img src=x onerror=alert(3)>' } })
    const html = w.html()
    expect(html).toContain('<p>ok</p>')
    expect(html).not.toContain('script')
    expect(html).not.toContain('onclick')
    expect(html).not.toContain('onerror')
  })
})

describe('EmptyState', () => {
  it('renders title and description', () => {
    const w = mount(EmptyState, { props: { title: 'Trống', description: 'Chưa có dữ liệu' }, global })
    expect(w.text()).toContain('Trống')
    expect(w.text()).toContain('Chưa có dữ liệu')
  })
})

describe('ErrorState', () => {
  it('shows the API message and request id, and emits retry', async () => {
    const w = mount(ErrorState, { props: { error: new ApiError(500, 'server_error', 'Lỗi máy chủ', {}, 'req-123') }, global })
    expect(w.text()).toContain('Lỗi máy chủ')
    expect(w.text()).toContain('req-123')
    await w.get('button').trigger('click')
    expect(w.emitted('retry')).toHaveLength(1)
  })

  it('shows a network message for network errors', () => {
    const w = mount(ErrorState, { props: { error: new ApiError(0, 'network_error', '') }, global })
    expect(w.text()).toContain('Không kết nối được máy chủ')
  })
})
