// Vietnamese labels + badge tones for demo enums.
export type Tone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger'
type LabelMap = Record<string, [string, Tone]>

export const LABELS = {
  site: {
    pending_pairing: ['Chờ ghép nối', 'warning'],
    connected: ['Đã kết nối', 'success'],
    degraded: ['Không ổn định', 'warning'],
    disconnected: ['Mất kết nối', 'danger'],
    revoked: ['Đã thu hồi', 'neutral'],
  },
  campaign: { draft: ['Nháp', 'neutral'], active: ['Đang chạy', 'success'], paused: ['Tạm dừng', 'warning'], completed: ['Hoàn thành', 'brand'] },
  keywordStatus: { suggested: ['Đề xuất', 'brand'], accepted: ['Đã chọn', 'success'], rejected: ['Loại bỏ', 'neutral'], used: ['Đã dùng', 'neutral'] },
  intent: {
    informational: ['Thông tin', 'brand'],
    commercial: ['Thương mại', 'warning'],
    transactional: ['Giao dịch', 'success'],
    navigational: ['Điều hướng', 'neutral'],
  },
  origin: { ai_suggested: ['AI đề xuất (mô phỏng)', 'brand'], serp: ['SERP', 'neutral'], gsc: ['Search Console', 'neutral'], customer: ['Khách hàng nhập', 'neutral'] },
  idea: { new: ['Mới', 'brand'], shortlisted: ['Đã chọn lọc', 'warning'], planned: ['Đã lên lịch', 'success'], rejected: ['Loại bỏ', 'neutral'], converted: ['Đã thành bài', 'success'] },
  format: { guide: ['Hướng dẫn', 'neutral'], listicle: ['Danh sách', 'neutral'], comparison: ['So sánh', 'neutral'], how_to: ['How-to', 'neutral'], case_study: ['Case study', 'neutral'], faq: ['FAQ', 'neutral'] },
  article: {
    brief: ['Dàn ý', 'neutral'],
    drafting: ['Đang viết', 'brand'],
    verifying: ['Đang kiểm chứng', 'brand'],
    needs_revision: ['Cần sửa', 'danger'],
    ready_for_review: ['Chờ biên tập', 'warning'],
    awaiting_approval: ['Chờ duyệt', 'warning'],
    approved: ['Đã duyệt', 'success'],
    scheduled: ['Đã lên lịch', 'brand'],
    publishing: ['Đang đăng', 'brand'],
    published: ['Đã đăng', 'success'],
    publish_failed: ['Đăng lỗi', 'danger'],
  },
  verification: { pending: ['Chưa kiểm chứng', 'neutral'], passed: ['Đạt', 'success'], failed: ['Không đạt', 'danger'], overridden: ['Duyệt thủ công', 'warning'] },
  claim: { supported: ['Có nguồn xác nhận', 'success'], unsupported: ['Không có nguồn', 'danger'], contradicted: ['Mâu thuẫn nguồn', 'danger'], needs_human: ['Cần người kiểm tra', 'warning'] },
  severity: { high: ['Cao', 'danger'], medium: ['Trung bình', 'warning'], low: ['Thấp', 'neutral'], critical: ['Nghiêm trọng', 'danger'], warning: ['Cảnh báo', 'warning'], notice: ['Lưu ý', 'neutral'] },
  issueStatus: { open: ['Chưa xử lý', 'warning'], ignored: ['Bỏ qua', 'neutral'], fixed: ['Đã sửa', 'success'] },
  mediaKind: { stock_photo: ['Thư viện', 'neutral'], customer_upload: ['Tải lên', 'brand'], ai_image: ['Ảnh AI', 'warning'], infographic: ['Infographic AI', 'warning'] },
  moderation: { pending: ['Chờ duyệt', 'warning'], approved: ['Đã duyệt', 'success'], rejected: ['Từ chối', 'danger'] },
  job: { queued: ['Đang chờ', 'neutral'], running: ['Đang chạy', 'brand'], succeeded: ['Thành công', 'success'], failed: ['Thất bại', 'danger'], canceled: ['Đã hủy', 'neutral'] },
  calendar: { planned: ['Kế hoạch', 'neutral'], in_progress: ['Đang thực hiện', 'brand'], done: ['Đã đăng', 'success'], skipped: ['Bỏ qua', 'neutral'] },
  subscription: { trialing: ['Dùng thử', 'brand'], active: ['Đang hoạt động', 'success'], past_due: ['Quá hạn', 'danger'], canceled: ['Đã hủy', 'neutral'] },
  invoice: { open: ['Chờ thanh toán', 'warning'], paid: ['Đã thanh toán', 'success'], void: ['Đã hủy', 'neutral'], refunded: ['Đã hoàn tiền', 'neutral'] },
  txn: { succeeded: ['Thành công', 'success'], failed: ['Thất bại', 'danger'], pending: ['Đang xử lý', 'warning'], refunded: ['Đã hoàn', 'neutral'] },
  txnType: { charge: ['Thanh toán gói', 'neutral'], refund: ['Hoàn tiền', 'neutral'], credit_purchase: ['Mua credit', 'neutral'] },
  workspace: { active: ['Hoạt động', 'success'], suspended: ['Tạm khóa', 'danger'], trialing: ['Dùng thử', 'brand'] },
  member: { active: ['Hoạt động', 'success'], invited: ['Đã mời', 'warning'] },
  role: { OWNER: ['Chủ sở hữu', 'brand'], ADMIN: ['Quản trị viên', 'brand'], EDITOR: ['Biên tập viên', 'neutral'], VIEWER: ['Người xem', 'neutral'] },
  provider: { healthy: ['Ổn định', 'success'], degraded: ['Chậm/lỗi', 'warning'], down: ['Ngừng', 'danger'] },
  ticket: { open: ['Mở', 'warning'], pending: ['Chờ khách', 'brand'], resolved: ['Đã xử lý', 'success'] },
  priority: { low: ['Thấp', 'neutral'], normal: ['Bình thường', 'neutral'], high: ['Cao', 'warning'], urgent: ['Khẩn cấp', 'danger'] },
  audit: { completed: ['Hoàn tất', 'success'], running: ['Đang quét', 'brand'], failed: ['Lỗi', 'danger'] },
  cluster: { draft: ['Nháp', 'neutral'], active: ['Đang dùng', 'success'], archived: ['Lưu trữ', 'neutral'] },
} satisfies Record<string, LabelMap>

export type LabelGroup = keyof typeof LABELS

export function label(group: LabelGroup, value: string | null | undefined): string {
  if (!value) return '—'
  return (LABELS[group] as LabelMap)[value]?.[0] ?? value
}
export function tone(group: LabelGroup, value: string | null | undefined): Tone {
  if (!value) return 'neutral'
  return (LABELS[group] as LabelMap)[value]?.[1] ?? 'neutral'
}
export function options(group: LabelGroup, allLabel = 'Tất cả'): { value: string; label: string }[] {
  return [{ value: '', label: allLabel }, ...Object.entries(LABELS[group] as LabelMap).map(([value, [l]]) => ({ value, label: l }))]
}

export const LIMIT_LABELS: Record<string, string> = {
  sites: 'Website',
  seats: 'Thành viên',
  articles: 'Bài viết / tháng',
  images_ai: 'Ảnh AI / tháng',
  keyword_lookups: 'Tra cứu từ khóa / tháng',
  research_runs: 'Lượt nghiên cứu / tháng',
}
