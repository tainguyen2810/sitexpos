import { inject, provide, type ComputedRef, type InjectionKey, type ShallowRef } from 'vue'
import type { ArticleDetail } from '@/shared/api/writer'

export interface ArticleContext {
  workspaceId: string
  articleId: string
  article: ShallowRef<ArticleDetail | null>
  /** Re-fetch the article (and notify tabs through `article`). */
  reload: () => Promise<unknown>
  /** Article is not being processed by AI and is in an editable status. */
  busy: ComputedRef<boolean>
  canEdit: ComputedRef<boolean>
  canApprove: ComputedRef<boolean>
}

const KEY: InjectionKey<ArticleContext> = Symbol('writer-article')

export function provideArticle(ctx: ArticleContext): void {
  provide(KEY, ctx)
}

export function useArticle(): ArticleContext {
  const ctx = inject(KEY)
  if (!ctx) throw new Error('useArticle() outside ArticlePage')
  return ctx
}
