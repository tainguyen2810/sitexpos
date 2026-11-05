// Demo entity shapes. Field names and status values follow docs/DATABASE.md so the
// prototype previews the planned schema. Every record is sample data (isDemo: true).

export type WorkspaceRole = 'OWNER' | 'ADMIN' | 'EDITOR' | 'VIEWER'

export interface DemoWorkspace {
  id: string
  name: string
  slug: string
  industry: string
  ownerName: string
  ownerEmail: string
  phone: string
  planCode: string
  status: 'active' | 'suspended' | 'trialing'
  timezone: string
  locale: string
  createdAt: string
  credits: number
  isDemo: true
  settings: {
    approvalMode: 'manual' | 'auto'
    defaultLanguage: string
    toneOfVoice: string
    bannedTerms: string[]
    notifyEmail: boolean
    notifyWeekly: boolean
    requireFactCheck: boolean
    autoInternalLinks: boolean
  }
}

export interface DemoMember {
  id: string
  workspaceId: string
  name: string
  email: string
  role: WorkspaceRole
  status: 'active' | 'invited'
  lastActiveAt: string | null
  joinedAt: string
}

export type SiteStatus = 'pending_pairing' | 'connected' | 'degraded' | 'disconnected' | 'revoked'

export interface DemoSite {
  id: string
  workspaceId: string
  name: string
  url: string
  status: SiteStatus
  language: string
  wpVersion: string
  pluginVersion: string
  phpVersion: string
  approvalMode: 'manual' | 'auto'
  postsCount: number
  managedPosts: number
  categories: string[]
  lastSeenAt: string | null
  connectedAt: string | null
  createdAt: string
  latencyMs: number
  gscConnected: boolean
  health: { at: string; ok: boolean; latencyMs: number; error?: string }[]
}

export type CampaignStatus = 'draft' | 'active' | 'paused' | 'completed'

export interface DemoCampaign {
  id: string
  workspaceId: string
  siteId: string
  name: string
  goal: string
  status: CampaignStatus
  startDate: string
  endDate: string
  targetArticles: number
  postsPerWeek: number
  audience: string
  createdAt: string
}

export type KeywordIntent = 'informational' | 'commercial' | 'transactional' | 'navigational'
export type KeywordStatus = 'suggested' | 'accepted' | 'rejected' | 'used'

export interface DemoKeyword {
  id: string
  workspaceId: string
  siteId: string
  campaignId: string | null
  clusterId: string | null
  keyword: string
  intent: KeywordIntent | null
  status: KeywordStatus
  origin: 'ai_suggested' | 'serp' | 'gsc' | 'customer'
  /** Simulated metrics (provider 'demo'); null = "Chưa có dữ liệu". */
  metrics: { volume: number; difficulty: number; cpcMinor: number; provider: 'demo'; trend: number[] } | null
  position: number | null
  createdAt: string
}

export interface DemoCluster {
  id: string
  workspaceId: string
  siteId: string
  campaignId: string | null
  name: string
  pillarKeywordId: string
  description: string
  status: 'draft' | 'active' | 'archived'
  createdAt: string
}

export type IdeaStatus = 'new' | 'shortlisted' | 'planned' | 'rejected' | 'converted'

export interface DemoIdea {
  id: string
  workspaceId: string
  siteId: string
  campaignId: string | null
  clusterId: string | null
  keywordId: string | null
  title: string
  angle: string
  intent: KeywordIntent
  format: 'guide' | 'listicle' | 'comparison' | 'how_to' | 'case_study' | 'faq'
  score: number
  status: IdeaStatus
  source: 'ai_simulated' | 'customer'
  createdAt: string
}

export type ArticleStatus =
  | 'brief'
  | 'drafting'
  | 'verifying'
  | 'needs_revision'
  | 'ready_for_review'
  | 'awaiting_approval'
  | 'approved'
  | 'scheduled'
  | 'publishing'
  | 'published'
  | 'publish_failed'

export interface DemoArticleVersion {
  no: number
  authorType: 'ai' | 'user'
  authorName: string
  note: string
  createdAt: string
  wordCount: number
  /** Content snapshot (versions created in the demo session); seeded versions share the current text. */
  snapshot?: { title: string; metaDescription: string; sections: DemoArticleSection[] }
}

export interface DemoArticleSection {
  heading: string
  body: string
}

export interface DemoArticle {
  id: string
  workspaceId: string
  siteId: string
  campaignId: string | null
  ideaId: string | null
  keywordId: string | null
  clusterId: string | null
  title: string
  slug: string
  metaDescription: string
  status: ArticleStatus
  verificationStatus: 'pending' | 'passed' | 'failed' | 'overridden'
  seoScore: number
  wordCount: number
  sections: DemoArticleSection[]
  versions: DemoArticleVersion[]
  featuredMediaId: string | null
  authorName: string
  scheduledAt: string | null
  publishedAt: string | null
  wpUrl: string | null
  createdAt: string
  updatedAt: string
}

export type ClaimStatus = 'supported' | 'unsupported' | 'contradicted' | 'needs_human'

export interface DemoClaim {
  id: string
  text: string
  severity: 'high' | 'medium' | 'low'
  status: ClaimStatus
  evidence: { sourceTitle: string; url: string; quote: string; quoteFound: boolean; stance: 'supports' | 'contradicts' }[]
  resolution?: string
}

export interface DemoFactCheck {
  id: string
  workspaceId: string
  articleId: string
  versionNo: number
  result: 'passed' | 'failed'
  score: number
  claims: DemoClaim[]
  sourcesCount: number
  overriddenBy: string | null
  overrideReason: string | null
  createdAt: string
}

export interface DemoMedia {
  id: string
  workspaceId: string
  title: string
  kind: 'stock_photo' | 'customer_upload' | 'ai_image' | 'infographic'
  isAiGenerated: boolean
  representsRealProduct: boolean
  width: number
  height: number
  bytes: number
  altText: string
  caption: string
  sourceProvider: string
  sourceUrl: string | null
  license: string
  attribution: string
  moderation: 'pending' | 'approved' | 'rejected'
  art: { hue: number; motif: number }
  articleIds: string[]
  createdAt: string
}

export interface DemoSeoIssue {
  id: string
  siteId: string
  category: 'technical' | 'content' | 'performance' | 'links' | 'schema'
  severity: 'critical' | 'warning' | 'notice'
  title: string
  pageUrl: string
  recommendation: string
  status: 'open' | 'ignored' | 'fixed'
}

export interface DemoSeoAudit {
  id: string
  workspaceId: string
  siteId: string
  score: number
  pagesCrawled: number
  createdAt: string
  status: 'completed' | 'running' | 'failed'
  issues: DemoSeoIssue[]
}

export interface DemoPublishJob {
  id: string
  workspaceId: string
  siteId: string
  articleId: string
  action: 'create' | 'update' | 'unpublish'
  wpStatus: 'draft' | 'publish' | 'future'
  runAt: string
  status: 'queued' | 'running' | 'succeeded' | 'failed' | 'canceled'
  attempts: number
  lastError: string | null
  wpPostId: number | null
  createdAt: string
}

export interface DemoCalendarItem {
  id: string
  workspaceId: string
  siteId: string
  campaignId: string | null
  ideaId: string | null
  articleId: string | null
  title: string
  plannedDate: string
  status: 'planned' | 'in_progress' | 'done' | 'skipped'
}

export interface DemoPlan {
  code: string
  name: string
  description: string
  priceMonthMinor: number
  priceYearMinor: number
  currency: 'VND'
  isPublic: boolean
  isActive: boolean
  trialDays: number
  limits: Record<LimitKey, number>
  monthlyCredits: number
  highlight: boolean
}

export type LimitKey = 'sites' | 'seats' | 'articles' | 'images_ai' | 'keyword_lookups' | 'research_runs'

export interface DemoSubscription {
  workspaceId: string
  planCode: string
  interval: 'month' | 'year'
  status: 'trialing' | 'active' | 'past_due' | 'canceled'
  currentPeriodStart: string
  currentPeriodEnd: string
  cancelAtPeriodEnd: boolean
  paymentMethod: { brand: string; last4: string; exp: string } | null
}

export interface DemoInvoice {
  id: string
  workspaceId: string
  number: string
  status: 'open' | 'paid' | 'void' | 'refunded'
  description: string
  subtotalMinor: number
  taxMinor: number
  totalMinor: number
  gateway: 'demo_gateway'
  issuedAt: string
  paidAt: string | null
}

export interface DemoTransaction {
  id: string
  workspaceId: string
  invoiceId: string | null
  type: 'charge' | 'refund' | 'credit_purchase'
  amountMinor: number
  status: 'succeeded' | 'failed' | 'pending' | 'refunded'
  gatewayRef: string
  method: string
  createdAt: string
  failureReason: string | null
}

export interface DemoCreditEntry {
  id: string
  workspaceId: string
  delta: number
  balanceAfter: number
  reason: 'purchase' | 'grant' | 'consume' | 'refund' | 'expire'
  note: string
  createdAt: string
}

export interface DemoUsageCounter {
  workspaceId: string
  metric: LimitKey
  used: number
}

export interface DemoAiModel {
  id: string
  providerCode: string
  name: string
  capabilities: string[]
  inputPerMtokUsd: number
  outputPerMtokUsd: number
  imagePriceUsd: number | null
  enabled: boolean
}

export interface DemoAiProvider {
  code: string
  name: string
  enabled: boolean
  priority: number
  status: 'healthy' | 'degraded' | 'down'
  keyConfigured: boolean
  latencyP50: number
  errorRate: number
}

export interface DemoTaskRoute {
  taskKey: string
  label: string
  primaryModelId: string
  fallbackModelId: string | null
  maxOutputTokens: number
  temperature: number
}

export interface DemoAiUsageDay {
  date: string
  workspaceId: string
  providerCode: string
  taskKey: string
  requests: number
  inputTokens: number
  outputTokens: number
  costMicroUsd: number
  errors: number
}

export interface DemoQueueJob {
  id: string
  queue: 'default' | 'ai' | 'media' | 'publishing' | 'crawl'
  type: string
  workspaceId: string | null
  status: 'queued' | 'running' | 'succeeded' | 'failed'
  progress: number
  attempts: number
  startedAt: string | null
  createdAt: string
}

export interface DemoFailedJob {
  id: string
  queue: string
  jobClass: string
  workspaceId: string | null
  exception: string
  trace: string
  failedAt: string
  attempts: number
}

export interface DemoAuditLog {
  id: string
  workspaceId: string | null
  actorName: string
  actorType: 'user' | 'staff' | 'system'
  action: string
  subject: string
  ip: string
  createdAt: string
}

export interface DemoTicket {
  id: string
  workspaceId: string
  subject: string
  requester: string
  priority: 'low' | 'normal' | 'high' | 'urgent'
  status: 'open' | 'pending' | 'resolved'
  category: string
  messages: { from: 'customer' | 'staff' | 'note'; author: string; body: string; at: string }[]
  createdAt: string
}

export interface DemoSystemSettings {
  maintenanceMode: boolean
  registrationOpen: boolean
  defaultPlan: string
  trialDays: number
  globalAiBudgetUsd: number
  alertThresholdPct: number
  actionOnExceed: 'alert' | 'pause_batch' | 'pause_all'
  maxConcurrentJobs: number
  supportEmail: string
  announcement: string
  featureFlags: Record<string, boolean>
}

export interface DemoDb {
  version: number
  generatedAt: string
  workspaces: DemoWorkspace[]
  members: DemoMember[]
  sites: DemoSite[]
  campaigns: DemoCampaign[]
  keywords: DemoKeyword[]
  clusters: DemoCluster[]
  ideas: DemoIdea[]
  articles: DemoArticle[]
  factChecks: DemoFactCheck[]
  media: DemoMedia[]
  seoAudits: DemoSeoAudit[]
  publishJobs: DemoPublishJob[]
  calendar: DemoCalendarItem[]
  plans: DemoPlan[]
  subscriptions: DemoSubscription[]
  invoices: DemoInvoice[]
  transactions: DemoTransaction[]
  credits: DemoCreditEntry[]
  usage: DemoUsageCounter[]
  aiProviders: DemoAiProvider[]
  aiModels: DemoAiModel[]
  taskRoutes: DemoTaskRoute[]
  aiUsage: DemoAiUsageDay[]
  queueJobs: DemoQueueJob[]
  failedJobs: DemoFailedJob[]
  auditLogs: DemoAuditLog[]
  tickets: DemoTicket[]
  systemSettings: DemoSystemSettings
}
