// Cross-module demo actions (keep the links between ideas, articles, calendar and keywords consistent).
import { db, newId } from '../store'
import { slugify } from '../data/seed'
import type { DemoArticle, DemoIdea, DemoKeyword } from '../data/types'

export function createArticleFromIdea(idea: DemoIdea, authorName: string): DemoArticle {
  const now = new Date().toISOString()
  const art: DemoArticle = {
    id: newId('art'),
    workspaceId: idea.workspaceId,
    siteId: idea.siteId,
    campaignId: idea.campaignId,
    ideaId: idea.id,
    keywordId: idea.keywordId,
    clusterId: idea.clusterId,
    title: idea.title,
    slug: slugify(idea.title).slice(0, 70),
    metaDescription: '',
    status: 'brief',
    verificationStatus: 'pending',
    seoScore: 0,
    wordCount: 0,
    sections: [],
    versions: [],
    featuredMediaId: null,
    authorName,
    scheduledAt: null,
    publishedAt: null,
    wpUrl: null,
    createdAt: now,
    updatedAt: now,
  }
  db.articles.unshift(art)
  idea.status = 'converted'
  const kw = db.keywords.find((k) => k.id === idea.keywordId)
  if (kw && kw.status !== 'rejected') kw.status = 'used'
  const cal = db.calendar.find((c) => c.ideaId === idea.id)
  if (cal) {
    cal.articleId = art.id
    cal.status = 'in_progress'
  }
  return db.articles[0]!
}

export function planIdea(idea: DemoIdea, dateIso: string) {
  idea.status = 'planned'
  const existing = db.calendar.find((c) => c.ideaId === idea.id)
  if (existing) {
    existing.plannedDate = dateIso
    return existing
  }
  db.calendar.push({
    id: newId('cal'),
    workspaceId: idea.workspaceId,
    siteId: idea.siteId,
    campaignId: idea.campaignId,
    ideaId: idea.id,
    articleId: null,
    title: idea.title,
    plannedDate: dateIso,
    status: 'planned',
  })
}

/** Simple deterministic "AI" draft used by the demo writer — no real model is called. */
export function demoDraftSections(title: string, kw: DemoKeyword | undefined, tone: string) {
  const k = kw?.keyword ?? title.toLowerCase()
  return [
    { heading: `Tổng quan: ${k}`, body: `Bài viết này giúp bạn hiểu rõ ${k} trước khi đưa ra quyết định. Nội dung được tạo bởi AI Writer ở chế độ demo (giọng văn: ${tone.toLowerCase()}) và cần biên tập viên kiểm tra.` },
    { heading: 'Các yếu tố cần cân nhắc', body: `Ngân sách, nhu cầu sử dụng và thời gian là ba yếu tố quan trọng nhất khi tìm hiểu ${k}. Mọi số liệu trong bài phải có nguồn được kiểm chứng.` },
    { heading: 'Hướng dẫn từng bước', body: `Bước 1: xác định mục tiêu. Bước 2: so sánh các lựa chọn. Bước 3: tham khảo ý kiến chuyên gia. Bước 4: theo dõi và đánh giá kết quả.` },
    { heading: 'Câu hỏi thường gặp', body: `Chúng tôi tổng hợp các câu hỏi phổ biến về ${k} từ khách hàng. Nếu bạn cần tư vấn cụ thể, hãy liên hệ đội ngũ hỗ trợ.` },
  ]
}

export const IDEA_PATTERNS: { t: (k: string) => string; format: DemoIdea['format']; angle: string }[] = [
  { t: (k) => `Hướng dẫn chọn ${k} cho người mới (cập nhật)`, format: 'guide', angle: 'Hướng dẫn' },
  { t: (k) => `7 lưu ý quan trọng khi tìm hiểu ${k}`, format: 'listicle', angle: 'Lưu ý' },
  { t: (k) => `${k}: nên hay không? Phân tích ưu và nhược điểm`, format: 'comparison', angle: 'Ưu nhược điểm' },
  { t: (k) => `Cách tự kiểm tra ${k} tại nhà trong 10 phút`, format: 'how_to', angle: 'Thực hành' },
  { t: (k) => `${k} – 10 câu hỏi khách hàng hay hỏi nhất`, format: 'faq', angle: 'FAQ' },
  { t: (k) => `Câu chuyện thực tế: khách hàng trải nghiệm ${k}`, format: 'case_study', angle: 'Câu chuyện' },
]
