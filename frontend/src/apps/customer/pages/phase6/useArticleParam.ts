import { ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { seoApi, type SeoArticleRow } from '@/shared/api/seo'
import { usePhase6Perms } from './usePhase6'

/** Article list for SEO screens + the selected article kept in the route (`:articleId?`). */
export function useArticleParam(routeName: string, onChange: (id: string) => void) {
  const route = useRoute()
  const router = useRouter()
  const { workspaceId } = usePhase6Perms()
  const articles = shallowRef<SeoArticleRow[]>([])
  const listError = shallowRef<unknown>(null)
  const listLoading = ref(true)
  const articleId = ref(typeof route.params.articleId === 'string' ? route.params.articleId : '')
  async function loadList() {
    listLoading.value = true
    listError.value = null
    try {
      articles.value = await seoApi.articles(workspaceId.value)
    } catch (e) {
      listError.value = e
    } finally {
      listLoading.value = false
    }
  }
  void loadList()
  watch(articleId, (id) => {
    void router.replace({ name: routeName, params: { workspaceId: workspaceId.value, articleId: id || undefined } })
    onChange(id)
  }, { immediate: true })
  return { articles, articleId, listError, listLoading, loadList }
}
