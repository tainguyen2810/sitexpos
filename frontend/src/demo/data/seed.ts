// Generates the linked demo dataset. Everything here is SAMPLE DATA:
// - customer names end with "(Demo)", domains use the reserved `.example` TLD,
// - keyword metrics are simulated (provider "demo"), citations point to `nguon-mau.example`,
// - images are SVG graphics drawn by the demo generator (CC0), AI images are labelled as such.
import { createRng, demoId, resetIds, type Rng } from './rng'
import type {
  ArticleStatus,
  DemoArticle,
  DemoCalendarItem,
  DemoCampaign,
  DemoClaim,
  DemoCluster,
  DemoCreditEntry,
  DemoDb,
  DemoFactCheck,
  DemoIdea,
  DemoInvoice,
  DemoKeyword,
  DemoMedia,
  DemoMember,
  DemoPlan,
  DemoPublishJob,
  DemoSeoAudit,
  DemoSeoIssue,
  DemoSite,
  DemoSubscription,
  DemoTransaction,
  DemoUsageCounter,
  DemoWorkspace,
  KeywordIntent,
  LimitKey,
  SiteStatus,
} from './types'

export const DEMO_DB_VERSION = 3
const SEED = 20261105
const DAY = 86_400_000

interface IndustryDef {
  name: string
  slug: string
  industry: string
  owner: string
  plan: string
  status: DemoWorkspace['status']
  sites: { name: string; host: string; status: SiteStatus }[]
  campaigns: { name: string; goal: string; audience: string }[]
  /** keyword building blocks: bases × modifiers */
  bases: string[]
  modifiers: string[]
  mediaTitles: string[]
  tone: string
}

const INDUSTRIES: IndustryDef[] = [
  {
    name: 'Nội Thất Mộc An (Demo)',
    slug: 'noi-that-moc-an',
    industry: 'Nội thất & trang trí nhà',
    owner: 'Trần Minh Khoa',
    plan: 'pro',
    status: 'active',
    sites: [
      { name: 'Mộc An – Cửa hàng', host: 'mocan-noithat.example', status: 'connected' },
      { name: 'Mộc An – Cẩm nang nhà đẹp', host: 'camnang.mocan-noithat.example', status: 'connected' },
      { name: 'Sofa Mộc An', host: 'sofa-mocan.example', status: 'degraded' },
    ],
    campaigns: [
      { name: 'Nội thất căn hộ nhỏ Q4', goal: 'Tăng traffic tự nhiên cho nhóm sản phẩm căn hộ nhỏ', audience: 'Gia đình trẻ ở chung cư' },
      { name: 'Mùa sofa Tết 2027', goal: 'Đón nhu cầu mua sofa trước Tết', audience: 'Người mua nhà mới' },
      { name: 'Cẩm nang bảo quản đồ gỗ', goal: 'Xây dựng độ tin cậy, giảm câu hỏi hậu mãi', audience: 'Khách hàng đã mua' },
    ],
    bases: ['sofa', 'bàn ăn', 'giường ngủ', 'tủ quần áo', 'kệ tivi', 'bàn làm việc', 'ghế thư giãn', 'tủ giày'],
    modifiers: ['gỗ sồi', 'cho căn hộ nhỏ', 'hiện đại', 'giá tốt', 'phong cách Bắc Âu', 'cách bảo quản', 'kích thước chuẩn'],
    mediaTitles: ['Phòng khách tối giản', 'Sofa góc chữ L', 'Bàn ăn gỗ sồi 6 ghế', 'Góc làm việc tại nhà', 'Phòng ngủ tông be', 'Kệ tivi treo tường', 'Tủ giày thông minh'],
    tone: 'Thân thiện, gần gũi, chuyên môn vừa phải',
  },
  {
    name: 'Lữ Hành Sen Việt (Demo)',
    slug: 'lu-hanh-sen-viet',
    industry: 'Du lịch & lữ hành',
    owner: 'Nguyễn Thu Hà',
    plan: 'agency',
    status: 'active',
    sites: [
      { name: 'Sen Việt Travel', host: 'senviet-travel.example', status: 'connected' },
      { name: 'Cẩm nang du lịch Sen Việt', host: 'camnang-senviet.example', status: 'connected' },
      { name: 'Sen Việt Tours Miền Tây', host: 'mientay.senviet-travel.example', status: 'disconnected' },
    ],
    campaigns: [
      { name: 'Du lịch Đà Lạt mùa hoa', goal: 'Bán tour Đà Lạt tháng 11–12', audience: 'Nhóm bạn trẻ, cặp đôi' },
      { name: 'Cẩm nang Phú Quốc 2027', goal: 'Phủ từ khóa du lịch Phú Quốc', audience: 'Gia đình có con nhỏ' },
    ],
    bases: ['du lịch Đà Lạt', 'tour Phú Quốc', 'homestay Đà Lạt', 'lịch trình Phú Quốc', 'vé cáp treo Hòn Thơm', 'ăn gì ở Đà Lạt', 'resort Phú Quốc'],
    modifiers: ['3 ngày 2 đêm', 'tự túc', 'cho gia đình', 'tháng 12', 'giá rẻ', 'kinh nghiệm', 'mùa hoa'],
    mediaTitles: ['Đồi chè buổi sáng', 'Bãi biển hoàng hôn', 'Chợ đêm phố núi', 'Cáp treo vượt biển', 'Homestay giữa rừng thông', 'Lịch trình 3 ngày (infographic)'],
    tone: 'Truyền cảm hứng, giàu hình ảnh',
  },
  {
    name: 'Nha Khoa An Tâm (Demo)',
    slug: 'nha-khoa-an-tam',
    industry: 'Y tế – nha khoa',
    owner: 'BS. Lê Quốc Bảo',
    plan: 'pro',
    status: 'active',
    sites: [
      { name: 'Nha Khoa An Tâm', host: 'nhakhoa-antam.example', status: 'connected' },
      { name: 'An Tâm Kids', host: 'kids.nhakhoa-antam.example', status: 'connected' },
    ],
    campaigns: [
      { name: 'Niềng răng cho người lớn', goal: 'Tăng lượt đặt lịch tư vấn niềng răng', audience: 'Người đi làm 22–35 tuổi' },
      { name: 'Chăm sóc răng trẻ em', goal: 'Giáo dục phụ huynh, tăng uy tín phòng khám', audience: 'Phụ huynh có con 3–12 tuổi' },
    ],
    bases: ['niềng răng', 'niềng răng trong suốt', 'tẩy trắng răng', 'sâu răng trẻ em', 'nhổ răng khôn', 'bọc răng sứ', 'chải răng đúng cách'],
    modifiers: ['bao lâu', 'có đau không', 'chi phí tham khảo', 'cho người lớn', 'lưu ý sau khi làm', 'ở trẻ em'],
    mediaTitles: ['Phòng khám sáng sủa', 'Bộ dụng cụ chải răng', 'Minh họa khay niềng', 'Bé đi khám răng', 'Quy trình tẩy trắng (infographic)'],
    tone: 'Chuyên nghiệp, thận trọng, dễ hiểu',
  },
  {
    name: 'Cà Phê Đất Đỏ (Demo)',
    slug: 'ca-phe-dat-do',
    industry: 'Thực phẩm & đồ uống',
    owner: 'Phạm Gia Huy',
    plan: 'starter',
    status: 'active',
    sites: [{ name: 'Cà Phê Đất Đỏ', host: 'caphedatdo.example', status: 'connected' }],
    campaigns: [{ name: 'Cà phê nguyên chất tại nhà', goal: 'Tăng đơn online hạt rang xay', audience: 'Dân văn phòng thích pha cà phê' }],
    bases: ['cà phê robusta', 'cà phê arabica', 'pha phin', 'cold brew', 'cà phê hạt rang'],
    modifiers: ['nguyên chất', 'cách pha', 'tại nhà', 'bảo quản', 'loại nào ngon'],
    mediaTitles: ['Ly phin buổi sáng', 'Hạt cà phê rang mộc', 'Cold brew trong chai thủy tinh'],
    tone: 'Mộc mạc, ấm áp',
  },
  {
    name: 'Học Viện Code Nhanh (Demo)',
    slug: 'hoc-vien-code-nhanh',
    industry: 'Giáo dục trực tuyến',
    owner: 'Đỗ Hoàng Nam',
    plan: 'starter',
    status: 'trialing',
    sites: [{ name: 'Code Nhanh Academy', host: 'codenhanh.example', status: 'pending_pairing' }],
    campaigns: [],
    bases: ['học lập trình'],
    modifiers: ['cho người mới'],
    mediaTitles: [],
    tone: 'Năng động, khích lệ',
  },
]

const KEYWORDS_PER_WS = [30, 30, 25, 15, 0]
const IDEAS_PER_WS = [50, 45, 35, 20, 0]
const ARTICLES_PER_WS = [18, 15, 11, 6, 0]
const MEDIA_PER_WS = [10, 9, 7, 4, 0]

const FIRST = ['Anh', 'Bảo', 'Châu', 'Dũng', 'Giang', 'Hải', 'Hương', 'Khánh', 'Linh', 'Mai', 'Ngọc', 'Phúc', 'Quân', 'Tâm', 'Thảo', 'Trang', 'Tú', 'Vy']
const LAST = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Võ', 'Đặng', 'Bùi', 'Đỗ', 'Huỳnh']
const MIDDLE = ['Văn', 'Thị', 'Minh', 'Ngọc', 'Gia', 'Quốc', 'Thanh', 'Hữu']

function ascii(s: string): string {
  return s
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd')
    .replace(/Đ/g, 'D')
}
export function slugify(s: string): string {
  return ascii(s)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

const IDEA_TEMPLATES: { t: (kw: string, n: number) => string; format: DemoIdea['format']; angle: string }[] = [
  { t: (k) => `Cách chọn ${k} phù hợp: hướng dẫn chi tiết 2027`, format: 'guide', angle: 'Hướng dẫn chọn mua' },
  { t: (k, n) => `${n} sai lầm thường gặp khi tìm hiểu ${k}`, format: 'listicle', angle: 'Tránh sai lầm' },
  { t: (k) => `So sánh các lựa chọn ${k}: nên chọn loại nào?`, format: 'comparison', angle: 'So sánh' },
  { t: (k) => `${k[0]!.toUpperCase()}${k.slice(1)} từ A đến Z cho người mới bắt đầu`, format: 'how_to', angle: 'Kiến thức nền tảng' },
  { t: (k) => `Hỏi đáp nhanh về ${k}: 12 câu hỏi thường gặp`, format: 'faq', angle: 'FAQ' },
  { t: (k) => `Kinh nghiệm thực tế về ${k} từ khách hàng của chúng tôi`, format: 'case_study', angle: 'Câu chuyện khách hàng' },
  { t: (k, n) => `${n} mẹo về ${k} ít người biết`, format: 'listicle', angle: 'Mẹo hay' },
  { t: (k) => `Checklist ${k}: những điều cần chuẩn bị`, format: 'guide', angle: 'Checklist' },
]

const SEO_ISSUE_TEMPLATES: Omit<DemoSeoIssue, 'id' | 'siteId' | 'pageUrl' | 'status'>[] = [
  { category: 'technical', severity: 'critical', title: 'Trang trả về lỗi 404', recommendation: 'Chuyển hướng 301 về trang liên quan hoặc khôi phục nội dung.' },
  { category: 'technical', severity: 'warning', title: 'Thiếu thẻ canonical', recommendation: 'Thêm thẻ canonical trỏ về URL chuẩn.' },
  { category: 'content', severity: 'warning', title: 'Meta description trùng lặp', recommendation: 'Viết meta description riêng cho từng trang (120–155 ký tự).' },
  { category: 'content', severity: 'notice', title: 'Tiêu đề dài hơn 60 ký tự', recommendation: 'Rút gọn tiêu đề, đưa từ khóa chính lên đầu.' },
  { category: 'content', severity: 'warning', title: 'Ảnh thiếu thuộc tính alt', recommendation: 'Bổ sung alt mô tả nội dung ảnh.' },
  { category: 'performance', severity: 'critical', title: 'LCP chậm hơn 4 giây (mô phỏng)', recommendation: 'Nén ảnh hero, bật cache và lazy-load ảnh bên dưới màn hình đầu.' },
  { category: 'performance', severity: 'warning', title: 'Ảnh chưa dùng định dạng WebP/AVIF', recommendation: 'Chuyển đổi ảnh sang WebP để giảm dung lượng.' },
  { category: 'links', severity: 'warning', title: 'Liên kết nội bộ bị hỏng', recommendation: 'Cập nhật hoặc gỡ liên kết trỏ tới trang không tồn tại.' },
  { category: 'links', severity: 'notice', title: 'Trang mồ côi (không có liên kết nội bộ trỏ tới)', recommendation: 'Thêm liên kết từ bài viết cùng chủ đề.' },
  { category: 'schema', severity: 'notice', title: 'Thiếu dữ liệu có cấu trúc Article', recommendation: 'Bật schema Article trong plugin SEO.' },
  { category: 'schema', severity: 'warning', title: 'Schema FAQ không hợp lệ', recommendation: 'Kiểm tra lại cấu trúc câu hỏi/trả lời.' },
  { category: 'technical', severity: 'notice', title: 'Sitemap chứa URL noindex', recommendation: 'Loại URL noindex khỏi sitemap.' },
]

export const PLANS: DemoPlan[] = [
  {
    code: 'starter',
    name: 'Starter',
    description: 'Cho cá nhân, 1 website',
    priceMonthMinor: 490_000,
    priceYearMinor: 4_900_000,
    currency: 'VND',
    isPublic: true,
    isActive: true,
    trialDays: 14,
    limits: { sites: 1, seats: 2, articles: 20, images_ai: 20, keyword_lookups: 200, research_runs: 10 },
    monthlyCredits: 200,
    highlight: false,
  },
  {
    code: 'pro',
    name: 'Pro',
    description: 'Cho doanh nghiệp nhỏ, nhiều website',
    priceMonthMinor: 1_490_000,
    priceYearMinor: 14_900_000,
    currency: 'VND',
    isPublic: true,
    isActive: true,
    trialDays: 14,
    limits: { sites: 5, seats: 5, articles: 80, images_ai: 100, keyword_lookups: 1000, research_runs: 50 },
    monthlyCredits: 1000,
    highlight: true,
  },
  {
    code: 'agency',
    name: 'Agency',
    description: 'Cho agency quản lý nhiều khách hàng',
    priceMonthMinor: 3_990_000,
    priceYearMinor: 39_900_000,
    currency: 'VND',
    isPublic: true,
    isActive: true,
    trialDays: 7,
    limits: { sites: 25, seats: 15, articles: 300, images_ai: 400, keyword_lookups: 5000, research_runs: 200 },
    monthlyCredits: 4000,
    highlight: false,
  },
  {
    code: 'enterprise',
    name: 'Enterprise',
    description: 'Gói tùy chỉnh theo hợp đồng',
    priceMonthMinor: 12_000_000,
    priceYearMinor: 120_000_000,
    currency: 'VND',
    isPublic: false,
    isActive: true,
    trialDays: 0,
    limits: { sites: -1, seats: -1, articles: 1500, images_ai: 2000, keyword_lookups: -1, research_runs: -1 },
    monthlyCredits: 20000,
    highlight: false,
  },
]

function iso(ms: number): string {
  return new Date(ms).toISOString()
}

function sentence(r: Rng, kw: string, industry: string): string {
  const parts = [
    `Khi tìm hiểu ${kw}, điều quan trọng nhất là xác định rõ nhu cầu thực tế trước khi so sánh các lựa chọn.`,
    `Trong lĩnh vực ${industry.toLowerCase()}, mỗi trường hợp có đặc điểm riêng nên cần được tư vấn cụ thể.`,
    `Bạn nên ghi lại các tiêu chí ưu tiên như ngân sách, thời gian và mức độ phù hợp để dễ ra quyết định.`,
    `Hãy tham khảo thông tin từ nguồn đáng tin cậy và kiểm tra lại số liệu trước khi áp dụng.`,
    `Đội ngũ của chúng tôi tổng hợp các câu hỏi khách hàng hay gặp nhất về ${kw} trong phần dưới đây.`,
    `Một kế hoạch rõ ràng giúp tiết kiệm chi phí và tránh những sai lầm không đáng có.`,
  ]
  return r.shuffle(parts).slice(0, 3).join(' ')
}

export function generateDemoDb(now = Date.now()): DemoDb {
  resetIds()
  const r = createRng(SEED)
  const ago = (days: number, jitterHours = 0) => iso(now - days * DAY - (jitterHours ? r.int(0, jitterHours) * 3_600_000 : 0))
  const ahead = (days: number, hour = 9) => {
    const d = new Date(now + days * DAY)
    d.setHours(hour, 0, 0, 0)
    return d.toISOString()
  }
  const personName = () => `${r.pick(LAST)} ${r.pick(MIDDLE)} ${r.pick(FIRST)}`

  const workspaces: DemoWorkspace[] = []
  const members: DemoMember[] = []
  const sites: DemoSite[] = []
  const campaigns: DemoCampaign[] = []
  const keywords: DemoKeyword[] = []
  const clusters: DemoCluster[] = []
  const ideas: DemoIdea[] = []
  const articles: DemoArticle[] = []
  const factChecks: DemoFactCheck[] = []
  const media: DemoMedia[] = []
  const seoAudits: DemoSeoAudit[] = []
  const publishJobs: DemoPublishJob[] = []
  const calendar: DemoCalendarItem[] = []
  const subscriptions: DemoSubscription[] = []
  const invoices: DemoInvoice[] = []
  const transactions: DemoTransaction[] = []
  const credits: DemoCreditEntry[] = []
  const usage: DemoUsageCounter[] = []

  let invoiceSeq = 1000

  INDUSTRIES.forEach((def, wi) => {
    const wsId = demoId('ws')
    const created = now - (420 - wi * 70) * DAY
    const plan = PLANS.find((p) => p.code === def.plan)!
    const emailDomain = `${def.slug}.demo.example`
    const ws: DemoWorkspace = {
      id: wsId,
      name: def.name,
      slug: def.slug,
      industry: def.industry,
      ownerName: def.owner,
      ownerEmail: `owner@${emailDomain}`,
      phone: `09${r.int(10000000, 99999999)}`,
      planCode: def.plan,
      status: def.status,
      timezone: 'Asia/Ho_Chi_Minh',
      locale: 'vi',
      createdAt: iso(created),
      credits: 0,
      isDemo: true,
      settings: {
        approvalMode: wi === 1 ? 'auto' : 'manual',
        defaultLanguage: 'vi',
        toneOfVoice: def.tone,
        bannedTerms: ['tốt nhất thế giới', 'cam kết 100%'],
        notifyEmail: true,
        notifyWeekly: wi !== 3,
        requireFactCheck: true,
        autoInternalLinks: wi !== 4,
      },
    }
    workspaces.push(ws)

    // Members
    const roles: DemoMember['role'][] = ['OWNER', 'ADMIN', 'EDITOR', 'EDITOR', 'VIEWER', 'EDITOR']
    const memberCount = [5, 6, 4, 2, 1][wi]!
    for (let i = 0; i < memberCount; i++) {
      const name = i === 0 ? def.owner : personName()
      members.push({
        id: demoId('mem'),
        workspaceId: wsId,
        name,
        email: i === 0 ? ws.ownerEmail : `${slugify(name).split('-').slice(-1)[0]}${i}@${emailDomain}`,
        role: roles[i]!,
        status: i === memberCount - 1 && i > 1 ? 'invited' : 'active',
        lastActiveAt: i === memberCount - 1 && i > 1 ? null : ago(r.int(0, 6), 20),
        joinedAt: iso(created + i * 9 * DAY),
      })
    }
    const authors = members.filter((m) => m.workspaceId === wsId && m.status === 'active').map((m) => m.name)

    // Sites
    const wsSites: DemoSite[] = def.sites.map((s, si) => {
      const connected = s.status !== 'pending_pairing'
      const health = Array.from({ length: 14 }, (_, d) => {
        const ok = s.status === 'connected' ? r.chance(0.97) : s.status === 'degraded' ? r.chance(0.7) : d > 4
        return {
          at: ago(13 - d),
          ok,
          latencyMs: ok ? r.int(180, s.status === 'degraded' ? 1900 : 650) : 0,
          error: ok ? undefined : r.pick(['timeout', 'signature_invalid', 'http_503']),
        }
      })
      if (s.status === 'disconnected') health.slice(-5).forEach((h) => Object.assign(h, { ok: false, latencyMs: 0, error: 'connection_refused' }))
      return {
        id: demoId('site'),
        workspaceId: wsId,
        name: s.name,
        url: `https://${s.host}`,
        status: s.status,
        language: 'vi',
        wpVersion: connected ? r.pick(['6.6.2', '6.7.1', '6.8.3', '6.8.3']) : '—',
        pluginVersion: connected ? (s.status === 'degraded' ? '0.9.2' : '1.0.0') : '—',
        phpVersion: connected ? r.pick(['8.1', '8.2', '8.3']) : '—',
        approvalMode: ws.settings.approvalMode,
        postsCount: connected ? r.int(40, 380) : 0,
        managedPosts: 0,
        categories: connected ? ['Tin tức', 'Cẩm nang', 'Khuyến mãi', 'Hỏi đáp'].slice(0, r.int(2, 4)) : [],
        lastSeenAt: s.status === 'connected' ? ago(0, 2) : s.status === 'degraded' ? ago(0, 9) : s.status === 'disconnected' ? ago(5) : null,
        connectedAt: connected ? iso(created + (si + 1) * 6 * DAY) : null,
        createdAt: iso(created + (si + 1) * 5 * DAY),
        latencyMs: health[health.length - 1]!.latencyMs,
        gscConnected: s.status === 'connected' && si < 2,
        health,
      }
    })
    sites.push(...wsSites)

    // Campaigns
    const wsCampaigns: DemoCampaign[] = def.campaigns.map((c, ci) => {
      const status: DemoCampaign['status'] = ci === 0 ? 'active' : ci === 1 ? (wi === 0 ? 'active' : 'paused') : wi === 0 ? 'completed' : 'draft'
      const start = status === 'completed' ? now - 120 * DAY : status === 'draft' ? now + 10 * DAY : now - r.int(20, 60) * DAY
      return {
        id: demoId('cmp'),
        workspaceId: wsId,
        siteId: wsSites[ci % wsSites.length]!.id,
        name: c.name,
        goal: c.goal,
        status,
        startDate: iso(start),
        endDate: iso(start + r.int(60, 120) * DAY),
        targetArticles: r.int(12, 30),
        postsPerWeek: r.int(2, 4),
        audience: c.audience,
        createdAt: iso(start - 7 * DAY),
      }
    })
    campaigns.push(...wsCampaigns)
    if (!wsCampaigns.length) {
      // brand-new trial customer: nothing else to generate except subscription/usage
    }

    // Keywords
    const kwCount = KEYWORDS_PER_WS[wi]!
    const combos = r.shuffle(def.bases.flatMap((b) => [b, ...def.modifiers.map((m) => `${b} ${m}`)]))
    const wsKeywords: DemoKeyword[] = []
    for (let k = 0; k < kwCount && k < combos.length; k++) {
      const camp = wsCampaigns[k % Math.max(1, wsCampaigns.length)]
      const hasMetrics = r.chance(0.82)
      const volume = r.weighted([[r.int(50, 400), 5], [r.int(400, 2500), 4], [r.int(2500, 18000), 1]] as const)
      wsKeywords.push({
        id: demoId('kw'),
        workspaceId: wsId,
        siteId: camp?.siteId ?? wsSites[0]!.id,
        campaignId: camp?.id ?? null,
        clusterId: null,
        keyword: combos[k]!,
        intent: r.chance(0.9) ? r.weighted<KeywordIntent>([['informational', 6], ['commercial', 3], ['transactional', 2], ['navigational', 1]]) : null,
        status: r.weighted([['accepted', 5], ['suggested', 3], ['used', 2], ['rejected', 1]] as const),
        origin: r.weighted([['ai_suggested', 5], ['serp', 2], ['gsc', 2], ['customer', 1]] as const),
        metrics: hasMetrics
          ? {
              volume,
              difficulty: r.int(8, 78),
              cpcMinor: r.int(2, 45) * 500,
              provider: 'demo',
              trend: Array.from({ length: 12 }, () => Math.round(volume * (0.6 + r.next() * 0.8))),
            }
          : null,
        position: r.chance(0.55) ? r.int(1, 60) : null,
        createdAt: ago(r.int(5, 90)),
      })
    }
    keywords.push(...wsKeywords)

    // Clusters: 1–2 per campaign, pillar = first keyword of the group
    wsCampaigns.forEach((camp, ci) => {
      const campKw = wsKeywords.filter((k) => k.campaignId === camp.id)
      const groups = campKw.length > 8 ? 2 : 1
      for (let g = 0; g < groups; g++) {
        const members = campKw.filter((_, i) => i % groups === g)
        if (!members.length) continue
        const pillar = members[0]!
        const cl: DemoCluster = {
          id: demoId('cl'),
          workspaceId: wsId,
          siteId: camp.siteId,
          campaignId: camp.id,
          name: `Chủ đề: ${pillar.keyword}`,
          pillarKeywordId: pillar.id,
          description: `Cụm bài viết xoay quanh "${pillar.keyword}" cho chiến dịch ${camp.name}.`,
          status: camp.status === 'completed' ? 'archived' : ci === 2 && g === 1 ? 'draft' : 'active',
          createdAt: camp.createdAt,
        }
        clusters.push(cl)
        members.forEach((m) => (m.clusterId = cl.id))
      }
    })

    // Ideas
    const ideaCount = IDEAS_PER_WS[wi]!
    const wsIdeas: DemoIdea[] = []
    for (let i = 0; i < ideaCount; i++) {
      const kw = wsKeywords[i % wsKeywords.length]!
      const tpl = IDEA_TEMPLATES[(i * 3 + wi) % IDEA_TEMPLATES.length]!
      wsIdeas.push({
        id: demoId('idea'),
        workspaceId: wsId,
        siteId: kw.siteId,
        campaignId: kw.campaignId,
        clusterId: kw.clusterId,
        keywordId: kw.id,
        title: tpl.t(kw.keyword, r.int(5, 12)),
        angle: tpl.angle,
        intent: kw.intent ?? 'informational',
        format: tpl.format,
        score: r.int(42, 96),
        status: r.weighted([['new', 5], ['shortlisted', 3], ['planned', 2], ['rejected', 1]] as const),
        source: r.chance(0.85) ? 'ai_simulated' : 'customer',
        createdAt: ago(r.int(1, 60), 23),
      })
    }
    ideas.push(...wsIdeas)

    // Media (needed before articles to set featured image)
    const wsMedia: DemoMedia[] = []
    for (let m = 0; m < MEDIA_PER_WS[wi]!; m++) {
      const title = def.mediaTitles[m % def.mediaTitles.length]!
      const isInfographic = title.includes('infographic')
      const kind: DemoMedia['kind'] = isInfographic ? 'infographic' : m % 3 === 2 ? 'ai_image' : m % 4 === 3 ? 'customer_upload' : 'stock_photo'
      const isAi = kind === 'ai_image' || kind === 'infographic'
      wsMedia.push({
        id: demoId('med'),
        workspaceId: wsId,
        title: m >= def.mediaTitles.length ? `${title} (biến thể ${m - def.mediaTitles.length + 2})` : title,
        kind,
        isAiGenerated: isAi,
        representsRealProduct: kind === 'customer_upload',
        width: 1600,
        height: kind === 'infographic' ? 2000 : 1000,
        bytes: r.int(90, 480) * 1024,
        altText: `${title} – minh họa cho ${def.industry.toLowerCase()}`,
        caption: isAi ? 'Ảnh minh họa tạo bằng AI (mô phỏng), không phải ảnh thật.' : '',
        sourceProvider: kind === 'stock_photo' ? 'Thư viện đồ họa SiteX Demo' : kind === 'customer_upload' ? 'Khách hàng tải lên (mô phỏng)' : 'AI image (mô phỏng, không gọi API thật)',
        sourceUrl: kind === 'stock_photo' ? `https://media.sitex-demo.example/library/${slugify(title)}` : null,
        license: kind === 'stock_photo' ? 'CC0 1.0 – đồ họa SVG do bộ sinh dữ liệu demo tạo' : kind === 'customer_upload' ? 'Thuộc sở hữu khách hàng (dữ liệu demo)' : 'Ảnh AI – chỉ dùng minh họa, không đại diện sản phẩm thật',
        attribution: kind === 'stock_photo' ? 'SiteX Demo Generator' : kind === 'customer_upload' ? def.name : 'Gắn nhãn: Ảnh do AI tạo',
        moderation: r.weighted([['approved', 8], ['pending', 2], ['rejected', 1]] as const),
        art: { hue: (wi * 67 + m * 29) % 360, motif: m % 5 },
        articleIds: [],
        createdAt: ago(r.int(2, 80)),
      })
    }
    media.push(...wsMedia)

    // Articles
    const statusPlan: ArticleStatus[] = [
      'published', 'published', 'published', 'published', 'scheduled', 'scheduled', 'awaiting_approval', 'ready_for_review',
      'drafting', 'verifying', 'needs_revision', 'approved', 'publish_failed', 'brief', 'published', 'publishing', 'drafting', 'published',
    ]
    const candidates = wsIdeas.filter((i) => i.status !== 'rejected')
    const artCount = ARTICLES_PER_WS[wi]!
    for (let a = 0; a < artCount; a++) {
      const idea = candidates[a]!
      idea.status = 'converted'
      const kw = wsKeywords.find((k) => k.id === idea.keywordId)
      if (kw && kw.status !== 'rejected') kw.status = 'used'
      const status = statusPlan[a % statusPlan.length]!
      const site = wsSites.find((s) => s.id === idea.siteId)!
      const createdAgo = r.int(3, 75)
      const sections = [
        { heading: `Tổng quan về ${kw?.keyword ?? 'chủ đề'}`, body: sentence(r, kw?.keyword ?? 'chủ đề', def.industry) },
        { heading: 'Những điều cần lưu ý', body: sentence(r, kw?.keyword ?? 'chủ đề', def.industry) },
        { heading: 'Hướng dẫn từng bước', body: sentence(r, kw?.keyword ?? 'chủ đề', def.industry) },
        { heading: 'Câu hỏi thường gặp', body: sentence(r, kw?.keyword ?? 'chủ đề', def.industry) },
      ]
      const versionsCount = status === 'brief' ? 0 : r.int(1, 4)
      const versions = Array.from({ length: versionsCount }, (_, v) => ({
        no: v + 1,
        authorType: (v === 0 || v % 2 === 0 ? 'ai' : 'user') as 'ai' | 'user',
        authorName: v === 0 || v % 2 === 0 ? 'AI Writer (mô phỏng)' : r.pick(authors),
        note: v === 0 ? 'Bản nháp đầu tiên' : v % 2 === 1 ? 'Biên tập viên chỉnh sửa giọng văn' : 'AI viết lại theo góp ý kiểm chứng',
        createdAt: iso(now - (createdAgo - v * 1.5) * DAY),
        wordCount: r.int(1200, 2400),
      }))
      const published = status === 'published'
      const scheduled = status === 'scheduled' || status === 'publishing'
      const verification: DemoArticle['verificationStatus'] = ['brief', 'drafting', 'verifying'].includes(status)
        ? 'pending'
        : status === 'needs_revision'
          ? 'failed'
          : a === 14
            ? 'overridden'
            : 'passed'
      const slug = slugify(idea.title).slice(0, 70)
      const featured = wsMedia.length && status !== 'brief' ? wsMedia[a % wsMedia.length]! : null
      if (featured && !featured.articleIds.includes(idea.id)) featured.articleIds.push('pending')
      const art: DemoArticle = {
        id: demoId('art'),
        workspaceId: wsId,
        siteId: site.id,
        campaignId: idea.campaignId,
        ideaId: idea.id,
        keywordId: idea.keywordId,
        clusterId: idea.clusterId,
        title: idea.title,
        slug,
        metaDescription: `${idea.title}. Bài viết mẫu (demo) do SiteX Post tạo để minh họa giao diện.`.slice(0, 155),
        status,
        verificationStatus: verification,
        seoScore: status === 'brief' ? 0 : r.int(55, 97),
        wordCount: versions[versions.length - 1]?.wordCount ?? 0,
        sections: status === 'brief' ? [] : sections,
        versions,
        featuredMediaId: featured?.id ?? null,
        authorName: r.pick(authors),
        scheduledAt: scheduled ? ahead(r.int(0, 21), r.pick([8, 10, 14, 20])) : null,
        publishedAt: published ? ago(r.int(1, Math.max(2, createdAgo - 2)), 10) : null,
        wpUrl: published ? `${site.url}/${slug}/` : null,
        createdAt: ago(createdAgo),
        updatedAt: ago(Math.max(0, createdAgo - r.int(1, 3))),
      }
      if (featured) featured.articleIds = featured.articleIds.filter((x) => x !== 'pending').concat(art.id)
      articles.push(art)
      if (published) site.managedPosts += 1
    }

    // SEO audits – one per paired site
    wsSites
      .filter((s) => s.status !== 'pending_pairing')
      .forEach((s) => {
        const issues: DemoSeoIssue[] = r
          .shuffle(SEO_ISSUE_TEMPLATES)
          .slice(0, r.int(5, 11))
          .map((tpl, ii) => ({
            ...tpl,
            id: demoId('iss'),
            siteId: s.id,
            pageUrl: `${s.url}/${['', 'gioi-thieu/', 'lien-he/', 'blog/', 'san-pham/', 'cam-nang/'][ii % 6]}`,
            status: r.weighted([['open', 6], ['fixed', 2], ['ignored', 1]] as const),
          }))
        const penalty = issues.filter((i) => i.status === 'open').reduce((sum, i) => sum + (i.severity === 'critical' ? 9 : i.severity === 'warning' ? 4 : 1), 0)
        seoAudits.push({
          id: demoId('aud'),
          workspaceId: wsId,
          siteId: s.id,
          score: Math.max(35, 100 - penalty),
          pagesCrawled: Math.min(500, s.postsCount + r.int(10, 40)),
          createdAt: ago(r.int(0, 9), 20),
          status: s.status === 'disconnected' ? 'failed' : 'completed',
          issues,
        })
      })

    // Subscription, invoices, transactions
    const months = Math.min(12, Math.floor((now - created) / (30 * DAY)))
    const trial = def.status === 'trialing'
    subscriptions.push({
      workspaceId: wsId,
      planCode: def.plan,
      interval: wi === 1 ? 'year' : 'month',
      status: trial ? 'trialing' : 'active',
      currentPeriodStart: ago(trial ? 3 : 12),
      currentPeriodEnd: iso(now + (trial ? 11 : 18) * DAY),
      cancelAtPeriodEnd: false,
      paymentMethod: trial ? null : { brand: r.pick(['VISA', 'Mastercard', 'JCB']), last4: String(r.int(1000, 9999)), exp: `0${r.int(1, 9)}/2${r.int(7, 9)}` },
    })
    if (!trial) {
      const count = wi === 1 ? 1 : Math.min(months, 8)
      for (let m = count - 1; m >= 0; m--) {
        const amount = wi === 1 ? plan.priceYearMinor : plan.priceMonthMinor
        const tax = Math.round(amount * 0.1)
        const issued = now - (m * 30 + 12) * DAY
        const failedOnce = wi === 3 && m === 2
        const inv: DemoInvoice = {
          id: demoId('inv'),
          workspaceId: wsId,
          number: `SXP-DEMO-${++invoiceSeq}`,
          status: 'paid',
          description: `Gói ${plan.name} – ${wi === 1 ? '12 tháng' : 'tháng'} (demo)`,
          subtotalMinor: amount,
          taxMinor: tax,
          totalMinor: amount + tax,
          gateway: 'demo_gateway',
          issuedAt: iso(issued),
          paidAt: iso(issued + (failedOnce ? 2 : 0) * DAY + 3_600_000),
        }
        invoices.push(inv)
        if (failedOnce) {
          transactions.push({
            id: demoId('txn'),
            workspaceId: wsId,
            invoiceId: inv.id,
            type: 'charge',
            amountMinor: inv.totalMinor,
            status: 'failed',
            gatewayRef: `demo_ch_${r.int(100000, 999999)}`,
            method: 'Thẻ (mô phỏng)',
            createdAt: iso(issued + 3_600_000),
            failureReason: 'Thẻ bị từ chối (mô phỏng: insufficient_funds)',
          })
        }
        transactions.push({
          id: demoId('txn'),
          workspaceId: wsId,
          invoiceId: inv.id,
          type: 'charge',
          amountMinor: inv.totalMinor,
          status: 'succeeded',
          gatewayRef: `demo_ch_${r.int(100000, 999999)}`,
          method: r.pick(['Thẻ (mô phỏng)', 'Chuyển khoản (mô phỏng)', 'Ví điện tử (mô phỏng)']),
          createdAt: inv.paidAt!,
          failureReason: null,
        })
      }
      if (wi === 0 || wi === 2) {
        const packAmount = wi === 0 ? 500_000 : 250_000
        const inv: DemoInvoice = {
          id: demoId('inv'),
          workspaceId: wsId,
          number: `SXP-DEMO-${++invoiceSeq}`,
          status: wi === 2 ? 'refunded' : 'paid',
          description: `Gói credit bổ sung ${wi === 0 ? 500 : 250} credit (demo)`,
          subtotalMinor: packAmount,
          taxMinor: Math.round(packAmount * 0.1),
          totalMinor: Math.round(packAmount * 1.1),
          gateway: 'demo_gateway',
          issuedAt: ago(20),
          paidAt: ago(20),
        }
        invoices.push(inv)
        transactions.push({
          id: demoId('txn'),
          workspaceId: wsId,
          invoiceId: inv.id,
          type: 'credit_purchase',
          amountMinor: inv.totalMinor,
          status: wi === 2 ? 'refunded' : 'succeeded',
          gatewayRef: `demo_ch_${r.int(100000, 999999)}`,
          method: 'Ví điện tử (mô phỏng)',
          createdAt: ago(20),
          failureReason: null,
        })
      }
    }

    // Credits ledger
    let balance = 0
    const pushCredit = (delta: number, reason: DemoCreditEntry['reason'], note: string, at: string) => {
      balance += delta
      credits.push({ id: demoId('cr'), workspaceId: wsId, delta, balanceAfter: balance, reason, note, createdAt: at })
    }
    pushCredit(plan.monthlyCredits, 'grant', `Credit hằng tháng gói ${plan.name}`, ago(trial ? 3 : 12))
    if (wi === 0) pushCredit(500, 'purchase', 'Mua gói 500 credit (thanh toán demo)', ago(20))
    const consumeCount = [14, 16, 10, 6, 1][wi]!
    for (let c = 0; c < consumeCount; c++) {
      const what = r.pick([
        ['Viết bài AI (mô phỏng)', 25],
        ['Kiểm chứng nội dung', 8],
        ['Tạo ảnh AI (mô phỏng)', 6],
        ['Tra cứu từ khóa', 2],
        ['Nghiên cứu chủ đề', 10],
      ] as const)
      pushCredit(-what[1] * r.int(1, 3), 'consume', what[0], ago(Math.max(0, 11 - c * 0.7)))
    }
    if (wi === 2) pushCredit(20, 'refund', 'Hoàn credit do tác vụ AI lỗi (mô phỏng)', ago(2))
    ws.credits = balance

    // Usage counters for current period
    const wsArticles = articles.filter((x) => x.workspaceId === wsId)
    const used: Record<LimitKey, number> = {
      sites: wsSites.length,
      seats: members.filter((m) => m.workspaceId === wsId).length,
      articles: Math.min(plan.limits.articles, wsArticles.length + r.int(0, 6)),
      images_ai: wsMedia.filter((x) => x.isAiGenerated).length + r.int(0, 8),
      keyword_lookups: Math.round(plan.limits.keyword_lookups * (wi === 3 ? 0.92 : r.next() * 0.6 + 0.1)),
      research_runs: r.int(1, Math.max(2, Math.round(plan.limits.research_runs * 0.5))),
    }
    ;(Object.keys(used) as LimitKey[]).forEach((metric) => usage.push({ workspaceId: wsId, metric, used: used[metric] }))
  })

  // Fact-check reports: 20 across articles that went through verification
  const verifiable = articles.filter((a) => !['brief', 'drafting'].includes(a.status))
  verifiable.slice(0, 20).forEach((a, i) => {
    const kwText = keywords.find((k) => k.id === a.keywordId)?.keyword ?? a.title
    const failed = a.verificationStatus === 'failed' || a.status === 'verifying' ? a.status === 'needs_revision' : false
    const claimTexts = [
      [`Quy trình ${kwText} thường kéo dài từ vài tuần đến vài tháng tùy trường hợp.`, 'medium'],
      [`Chi phí tham khảo cho ${kwText} dao động theo khu vực và nhà cung cấp.`, 'high'],
      [`Nên kiểm tra định kỳ 6 tháng một lần để đạt hiệu quả tốt nhất.`, 'medium'],
      [`Hơn 70% khách hàng hài lòng sau khi áp dụng (số liệu cần nguồn).`, 'high'],
      [`Chất liệu tự nhiên cần được bảo quản tránh ánh nắng trực tiếp.`, 'low'],
      [`Thông tin giá trên đây được cập nhật vào tháng hiện tại.`, 'high'],
    ] as const
    const n = 3 + (i % 4)
    const claims: DemoClaim[] = claimTexts.slice(0, n).map(([text, severity], ci) => {
      const status: DemoClaim['status'] =
        failed && ci === 3 ? 'unsupported' : failed && ci === 1 ? 'contradicted' : a.status === 'verifying' && ci === 2 ? 'needs_human' : ci === 3 && i % 3 === 0 ? 'needs_human' : 'supported'
      const src = (k: number) => ({
        sourceTitle: `Nguồn mẫu #${(i * 7 + ci * 3 + k) % 40 + 1}: Tài liệu tham khảo (demo)`,
        url: `https://nguon-mau.example/tai-lieu/${(i * 7 + ci * 3 + k) % 40 + 1}`,
        quote: status === 'supported' ? `Trích dẫn mẫu xác nhận nội dung: "${text.slice(0, 60)}…"` : 'Không tìm thấy đoạn trích tương ứng trong nguồn đã tải.',
        quoteFound: status === 'supported',
        stance: (status === 'contradicted' ? 'contradicts' : 'supports') as 'supports' | 'contradicts',
      })
      return {
        id: demoId('clm'),
        text,
        severity,
        status,
        evidence: status === 'unsupported' ? [] : [src(0), ...(ci % 2 === 0 ? [src(1)] : [])],
      }
    })
    const supported = claims.filter((c) => c.status === 'supported').length
    const result: DemoFactCheck['result'] = claims.some((c) => ['unsupported', 'contradicted'].includes(c.status) && c.severity === 'high') ? 'failed' : 'passed'
    factChecks.push({
      id: demoId('fc'),
      workspaceId: a.workspaceId,
      articleId: a.id,
      versionNo: Math.max(1, a.versions.length),
      result: a.verificationStatus === 'overridden' ? 'failed' : result,
      score: Math.round((supported / claims.length) * 100),
      claims,
      sourcesCount: 4 + (i % 5),
      overriddenBy: a.verificationStatus === 'overridden' ? 'Biên tập viên (demo)' : null,
      overrideReason: a.verificationStatus === 'overridden' ? 'Đã xác minh thủ công với tài liệu nội bộ của khách hàng.' : null,
      createdAt: a.updatedAt,
    })
    if (result === 'failed' && a.verificationStatus === 'passed') a.verificationStatus = 'failed'
    if (a.verificationStatus === 'failed' && !['needs_revision'].includes(a.status)) a.status = 'needs_revision'
  })

  // Publishing queue
  articles
    .filter((a) => ['scheduled', 'publishing', 'published', 'publish_failed'].includes(a.status))
    .forEach((a, i) => {
      const status: DemoPublishJob['status'] =
        a.status === 'published' ? 'succeeded' : a.status === 'publishing' ? 'running' : a.status === 'publish_failed' ? 'failed' : 'queued'
      publishJobs.push({
        id: demoId('pub'),
        workspaceId: a.workspaceId,
        siteId: a.siteId,
        articleId: a.id,
        action: i % 9 === 4 && status === 'succeeded' ? 'update' : 'create',
        wpStatus: status === 'queued' ? 'future' : 'publish',
        runAt: a.scheduledAt ?? a.publishedAt ?? a.updatedAt,
        status,
        attempts: status === 'failed' ? 3 : status === 'queued' ? 0 : 1,
        lastError: status === 'failed' ? 'Website không phản hồi (HTTP 503) sau 3 lần thử – mô phỏng' : null,
        wpPostId: status === 'succeeded' ? 1000 + i * 7 : null,
        createdAt: a.updatedAt,
      })
    })

  // Content calendar: articles + planned ideas
  articles.forEach((a) => {
    const date = a.publishedAt ?? a.scheduledAt
    calendar.push({
      id: demoId('cal'),
      workspaceId: a.workspaceId,
      siteId: a.siteId,
      campaignId: a.campaignId,
      ideaId: a.ideaId,
      articleId: a.id,
      title: a.title,
      plannedDate: date ?? ahead(r.int(1, 25)),
      status: a.status === 'published' ? 'done' : 'in_progress',
    })
  })
  ideas
    .filter((i) => i.status === 'planned')
    .forEach((i) => {
      calendar.push({
        id: demoId('cal'),
        workspaceId: i.workspaceId,
        siteId: i.siteId,
        campaignId: i.campaignId,
        ideaId: i.id,
        articleId: null,
        title: i.title,
        plannedDate: ahead(r.int(2, 45), r.pick([8, 9, 14])),
        status: 'planned',
      })
    })

  return {
    version: DEMO_DB_VERSION,
    generatedAt: iso(now),
    workspaces,
    members,
    sites,
    campaigns,
    keywords,
    clusters,
    ideas,
    articles,
    factChecks,
    media,
    seoAudits,
    publishJobs,
    calendar,
    plans: PLANS.map((p) => ({ ...p, limits: { ...p.limits } })),
    subscriptions,
    invoices,
    transactions,
    credits,
    usage,
    ...generatePlatformData(r, now, workspaces),
  }
}

function generatePlatformData(r: Rng, now: number, workspaces: DemoWorkspace[]): Pick<
  DemoDb,
  'aiProviders' | 'aiModels' | 'taskRoutes' | 'aiUsage' | 'queueJobs' | 'failedJobs' | 'auditLogs' | 'tickets' | 'systemSettings'
> {
  const ago = (days: number, jitterHours = 0) => iso(now - days * DAY - (jitterHours ? r.int(0, jitterHours) * 3_600_000 : 0))
  const aiProviders: DemoDb['aiProviders'] = [
    { code: 'openai', name: 'OpenAI', enabled: true, priority: 1, status: 'healthy', keyConfigured: true, latencyP50: 1450, errorRate: 0.6 },
    { code: 'anthropic', name: 'Anthropic', enabled: true, priority: 2, status: 'healthy', keyConfigured: true, latencyP50: 1720, errorRate: 0.4 },
    { code: 'gemini', name: 'Google Gemini', enabled: true, priority: 3, status: 'degraded', keyConfigured: true, latencyP50: 2380, errorRate: 3.1 },
  ]
  // Prices are SIMULATED placeholders for the demo — real prices come from provider pricing pages.
  const aiModels: DemoDb['aiModels'] = [
    { id: 'mdl_openai_a', providerCode: 'openai', name: 'gpt-5', capabilities: ['text', 'json'], inputPerMtokUsd: 1.25, outputPerMtokUsd: 10, imagePriceUsd: null, enabled: true },
    { id: 'mdl_openai_b', providerCode: 'openai', name: 'gpt-5-mini', capabilities: ['text', 'json'], inputPerMtokUsd: 0.25, outputPerMtokUsd: 2, imagePriceUsd: null, enabled: true },
    { id: 'mdl_openai_img', providerCode: 'openai', name: 'gpt-image-1', capabilities: ['image'], inputPerMtokUsd: 0, outputPerMtokUsd: 0, imagePriceUsd: 0.04, enabled: true },
    { id: 'mdl_anthropic_a', providerCode: 'anthropic', name: 'claude-sonnet-5-5', capabilities: ['text', 'json'], inputPerMtokUsd: 3, outputPerMtokUsd: 15, imagePriceUsd: null, enabled: true },
    { id: 'mdl_anthropic_b', providerCode: 'anthropic', name: 'claude-haiku-5-5', capabilities: ['text', 'json'], inputPerMtokUsd: 1, outputPerMtokUsd: 5, imagePriceUsd: null, enabled: true },
    { id: 'mdl_gemini_a', providerCode: 'gemini', name: 'gemini-2.5-pro', capabilities: ['text', 'json'], inputPerMtokUsd: 1.25, outputPerMtokUsd: 10, imagePriceUsd: null, enabled: true },
    { id: 'mdl_gemini_b', providerCode: 'gemini', name: 'gemini-2.5-flash', capabilities: ['text', 'json'], inputPerMtokUsd: 0.3, outputPerMtokUsd: 2.5, imagePriceUsd: null, enabled: false },
  ]
  const taskRoutes: DemoDb['taskRoutes'] = [
    { taskKey: 'research.summarize', label: 'Tóm tắt nghiên cứu', primaryModelId: 'mdl_anthropic_b', fallbackModelId: 'mdl_openai_b', maxOutputTokens: 2000, temperature: 0.2 },
    { taskKey: 'keywords.expand', label: 'Mở rộng từ khóa', primaryModelId: 'mdl_openai_b', fallbackModelId: 'mdl_gemini_a', maxOutputTokens: 1500, temperature: 0.4 },
    { taskKey: 'topics.discover', label: 'Khám phá chủ đề', primaryModelId: 'mdl_openai_a', fallbackModelId: 'mdl_anthropic_a', maxOutputTokens: 3000, temperature: 0.7 },
    { taskKey: 'content.draft', label: 'Viết bản nháp', primaryModelId: 'mdl_anthropic_a', fallbackModelId: 'mdl_openai_a', maxOutputTokens: 8000, temperature: 0.6 },
    { taskKey: 'verification.claims', label: 'Kiểm chứng nhận định', primaryModelId: 'mdl_anthropic_a', fallbackModelId: 'mdl_gemini_a', maxOutputTokens: 4000, temperature: 0 },
    { taskKey: 'seo.meta', label: 'Tối ưu meta SEO', primaryModelId: 'mdl_openai_b', fallbackModelId: null, maxOutputTokens: 600, temperature: 0.3 },
    { taskKey: 'media.image', label: 'Tạo ảnh minh họa', primaryModelId: 'mdl_openai_img', fallbackModelId: null, maxOutputTokens: 0, temperature: 0 },
  ]
  const aiUsage: DemoDb['aiUsage'] = []
  const weights = [1, 1.6, 0.8, 0.35, 0.05]
  for (let d = 29; d >= 0; d--) {
    const date = new Date(now - d * DAY).toISOString().slice(0, 10)
    workspaces.forEach((w, wi) => {
      aiProviders.forEach((p, pi) => {
        const base = weights[wi]! * [1, 0.7, 0.35][pi]! * (0.7 + r.next() * 0.6) * (d % 7 === 0 || d % 7 === 6 ? 0.6 : 1)
        const requests = Math.round(base * 40)
        if (!requests) return
        const inTok = requests * r.int(1800, 3500)
        const outTok = requests * r.int(700, 1800)
        const cost = Math.round((inTok / 1e6) * [1.25, 3, 1.25][pi]! * 1e6 + (outTok / 1e6) * [10, 15, 10][pi]! * 1e6)
        aiUsage.push({
          date,
          workspaceId: w.id,
          providerCode: p.code,
          taskKey: r.pick(taskRoutes).taskKey,
          requests,
          inputTokens: inTok,
          outputTokens: outTok,
          costMicroUsd: cost,
          errors: r.chance(pi === 2 ? 0.5 : 0.15) ? r.int(1, 3) : 0,
        })
      })
    })
  }

  const jobTypes: Record<DemoDb['queueJobs'][number]['queue'], string[]> = {
    default: ['SendWeeklyReport', 'SyncUsageCounters'],
    ai: ['DraftArticle', 'VerifyClaims', 'DiscoverTopics', 'ExpandKeywords'],
    media: ['GenerateImage', 'OptimizeImage'],
    publishing: ['PublishToWordPress', 'UpdateWordPressPost'],
    crawl: ['RunSeoAudit', 'FetchResearchSource', 'SiteHealthCheck'],
  }
  const queueJobs: DemoDb['queueJobs'] = Array.from({ length: 48 }, (_, i) => {
    const queue = r.weighted([['ai', 5], ['crawl', 3], ['media', 2], ['publishing', 2], ['default', 1]] as const)
    const status = r.weighted([['queued', 4], ['running', 2], ['succeeded', 5], ['failed', 1]] as const)
    return {
      id: demoId('job'),
      queue,
      type: r.pick(jobTypes[queue]),
      workspaceId: r.chance(0.9) ? r.pick(workspaces.slice(0, 4)).id : null,
      status,
      progress: status === 'succeeded' ? 100 : status === 'running' ? r.int(10, 90) : status === 'failed' ? r.int(10, 80) : 0,
      attempts: status === 'queued' ? 0 : r.int(1, 3),
      startedAt: status === 'queued' ? null : iso(now - r.int(1, 90) * 60_000),
      createdAt: iso(now - (i + 1) * r.int(1, 6) * 60_000),
    }
  })
  const exceptions = [
    ['SiteXPost\\Modules\\Publishing\\Jobs\\PublishToWordPress', 'publishing', 'RuntimeException: Website trả về HTTP 503 (mô phỏng)'],
    ['SiteXPost\\Modules\\AiGateway\\Jobs\\DraftArticle', 'ai', 'AiGateway\\SchemaValidationException: Kết quả không khớp JSON schema sau 3 lần thử (mô phỏng)'],
    ['SiteXPost\\Modules\\Media\\Jobs\\GenerateImage', 'media', 'AiGateway\\ProviderTimeout: Hết thời gian chờ 60s (mô phỏng)'],
    ['SiteXPost\\Modules\\Seo\\Jobs\\RunSeoAudit', 'crawl', 'SsrfViolation: Đích đến phân giải về IP nội bộ – đã chặn (mô phỏng)'],
    ['SiteXPost\\Modules\\Sites\\Jobs\\SiteHealthCheck', 'crawl', 'Sites\\SignatureInvalid: Chữ ký Ed25519 không hợp lệ (mô phỏng)'],
    ['SiteXPost\\Modules\\Verification\\Jobs\\VerifyClaims', 'ai', 'Verification\\SourceFetchFailed: Không tải được nguồn https://nguon-mau.example/... (mô phỏng)'],
  ] as const
  const failedJobs: DemoDb['failedJobs'] = Array.from({ length: 15 }, (_, i) => {
    const [cls, queue, exc] = exceptions[i % exceptions.length]!
    return {
      id: demoId('fj'),
      queue,
      jobClass: cls,
      workspaceId: r.pick(workspaces.slice(0, 4)).id,
      exception: exc,
      trace: `#0 /app/Modules/${cls.split('\\')[2]}/Jobs/Handler.php(${r.int(40, 180)}): handle()\n#1 /vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(${r.int(100, 140)})\n#2 {main}\n(Stack trace mô phỏng cho demo)`,
      failedAt: ago(r.int(0, 6), 23),
      attempts: 3,
    }
  })
  const actions = [
    ['user', 'auth.login', 'Phiên đăng nhập'],
    ['user', 'article.approved', 'Bài viết'],
    ['user', 'site.connected', 'Website'],
    ['user', 'member.invited', 'Thành viên'],
    ['user', 'campaign.created', 'Chiến dịch'],
    ['user', 'subscription.changed', 'Gói cước'],
    ['staff', 'platform.impersonation.started', 'Workspace'],
    ['staff', 'platform.workspace.suspended', 'Workspace'],
    ['staff', 'platform.plan.updated', 'Gói cước'],
    ['system', 'billing.invoice.paid', 'Hóa đơn'],
    ['system', 'ai.budget.threshold', 'Ngân sách AI'],
    ['user', 'factcheck.overridden', 'Báo cáo kiểm chứng'],
  ] as const
  const auditLogs: DemoDb['auditLogs'] = Array.from({ length: 120 }, (_, i) => {
    const [actorType, action, subject] = r.pick(actions)
    const w = actorType === 'system' && action.startsWith('ai') ? null : r.pick(workspaces)
    return {
      id: demoId('log'),
      workspaceId: w?.id ?? null,
      actorName: actorType === 'staff' ? r.pick(['Support Lan (demo)', 'Admin Quang (demo)']) : actorType === 'system' ? 'Hệ thống' : (w?.ownerName ?? 'Hệ thống'),
      actorType,
      action,
      subject,
      ip: actorType === 'system' ? '—' : `203.0.113.${r.int(2, 250)}`,
      createdAt: iso(now - i * r.int(20, 180) * 60_000),
    }
  })
  const tickets: DemoDb['tickets'] = [
    ['Không kết nối được website sau khi cài plugin', 'Kết nối WordPress', 'high', 'open'],
    ['Muốn đổi sang thanh toán theo năm', 'Thanh toán', 'normal', 'pending'],
    ['Bài viết bị đánh dấu "cần sửa" dù đã có nguồn', 'Kiểm chứng', 'normal', 'open'],
    ['Xin xuất hóa đơn VAT tháng trước', 'Thanh toán', 'low', 'resolved'],
    ['Lịch đăng bài bị lệch múi giờ', 'Xuất bản', 'high', 'open'],
    ['Thêm thành viên vượt giới hạn gói', 'Gói cước', 'normal', 'resolved'],
    ['Ảnh AI có thể dùng làm ảnh sản phẩm không?', 'Media', 'low', 'resolved'],
    ['Website báo lỗi chữ ký không hợp lệ', 'Kết nối WordPress', 'urgent', 'open'],
    ['Search Console không hiện dữ liệu', 'Analytics', 'normal', 'pending'],
    ['Yêu cầu hoàn credit cho tác vụ lỗi', 'Credit', 'normal', 'open'],
  ].map(([subject, category, priority, status], i) => {
    const w = workspaces[i % 4]!
    const created = now - (i * 1.3 + 0.2) * DAY
    return {
      id: demoId('tk'),
      workspaceId: w.id,
      subject: subject!,
      requester: w.ownerName,
      priority: priority as DemoDb['tickets'][number]['priority'],
      status: status as DemoDb['tickets'][number]['status'],
      category: category!,
      createdAt: iso(created),
      messages: [
        { from: 'customer' as const, author: w.ownerName, body: `Chào đội hỗ trợ, ${subject!.toLowerCase()}. Nhờ kiểm tra giúp (yêu cầu demo).`, at: iso(created) },
        ...(status !== 'open'
          ? [{ from: 'staff' as const, author: 'Support Lan (demo)', body: 'Chào anh/chị, bên em đã kiểm tra và cập nhật. Anh/chị thử lại giúp em nhé.', at: iso(created + 3_600_000 * 3) }]
          : []),
      ],
    }
  })
  return {
    aiProviders,
    aiModels,
    taskRoutes,
    aiUsage,
    queueJobs,
    failedJobs,
    auditLogs,
    tickets,
    systemSettings: {
      maintenanceMode: false,
      registrationOpen: true,
      defaultPlan: 'starter',
      trialDays: 14,
      globalAiBudgetUsd: 1500,
      alertThresholdPct: 80,
      actionOnExceed: 'pause_batch',
      maxConcurrentJobs: 24,
      supportEmail: 'support@sitex-demo.example',
      announcement: '',
      featureFlags: { gsc_integration: true, ai_images: true, auto_publish: false, internal_linking: true, refresh_suggestions: false },
    },
  }
}
