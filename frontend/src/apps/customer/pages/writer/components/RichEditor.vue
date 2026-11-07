<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isSafeHref, sanitizeArticleHtml } from '@/shared/utils/articleHtml'
import AppIcon from '@/shared/ui/AppIcon.vue'
import { useToast } from '@/shared/composables/useToast'

/**
 * Minimal rich-text editor (contenteditable). Everything that enters the
 * document — initial HTML, pastes, inserted citations — goes through the
 * article sanitizer first; the server sanitizes again on save.
 */
const props = defineProps<{ html: string; readonly?: boolean }>()
const emit = defineEmits<{ change: [html: string] }>()
const { t } = useI18n()
const toast = useToast()
const el = ref<HTMLDivElement | null>(null)
let lastEmitted = ''

function load(html: string) {
  if (!el.value) return
  const clean = sanitizeArticleHtml(html)
  el.value.innerHTML = clean
  lastEmitted = clean
}
onMounted(() => load(props.html))
watch(() => props.html, (h) => {
  if (h !== lastEmitted) load(h)
})

function current(): string {
  return sanitizeArticleHtml(el.value?.innerHTML ?? '')
}
function changed() {
  const html = current()
  lastEmitted = html
  emit('change', html)
}

function exec(cmd: string, value?: string) {
  if (props.readonly) return
  el.value?.focus()
  document.execCommand(cmd, false, value)
  changed()
}
function block(tag: 'h2' | 'h3' | 'p' | 'pre' | 'blockquote') {
  exec('formatBlock', tag)
}
function link() {
  const url = window.prompt(t('writer.editor.linkPrompt'))
  if (url === null) return
  if (!isSafeHref(url)) {
    toast.error(t('writer.editor.linkInvalid'))
    return
  }
  exec('createLink', url.trim())
}
function table() {
  exec('insertHTML', '<table><thead><tr><th>…</th><th>…</th></tr></thead><tbody><tr><td>…</td><td>…</td></tr></tbody></table><p></p>')
}
function onPaste(e: ClipboardEvent) {
  if (props.readonly) return
  e.preventDefault()
  const html = e.clipboardData?.getData('text/html')
  const text = e.clipboardData?.getData('text/plain') ?? ''
  if (html) document.execCommand('insertHTML', false, sanitizeArticleHtml(html))
  else document.execCommand('insertText', false, text)
  changed()
}

/** Text of the current selection and the heading (h2) of the section it is in. */
function selectionContext(): { text: string; heading: string | null } | null {
  const sel = window.getSelection()
  if (!sel || sel.rangeCount === 0 || !el.value) return null
  const range = sel.getRangeAt(0)
  if (!el.value.contains(range.commonAncestorContainer)) return null
  let node: Node | null = range.startContainer
  while (node && node.parentNode !== el.value) node = node.parentNode
  let heading: string | null = null
  for (let n: Node | null = node; n; n = n.previousSibling) {
    if (n instanceof HTMLElement && n.tagName === 'H2') {
      heading = n.textContent ?? ''
      break
    }
  }
  return { text: sel.toString(), heading }
}

/** Insert a citation link to a fetched evidence item at the caret. */
function insertCitation(evidenceId: string, ref: number, url: string | null) {
  if (props.readonly || !el.value) return
  el.value.focus()
  const safe = url && isSafeHref(url) ? url : '#'
  const a = document.createElement('a')
  a.setAttribute('data-evidence-id', evidenceId)
  a.setAttribute('href', safe)
  a.textContent = `[${ref}]`
  document.execCommand('insertHTML', false, a.outerHTML)
  changed()
}

defineExpose({ current, selectionContext, insertCitation, load })

const tools: { key: string; icon?: string; label: string; run: () => void }[] = [
  { key: 'h2', label: 'H2', run: () => block('h2') },
  { key: 'h3', label: 'H3', run: () => block('h3') },
  { key: 'p', label: '¶', run: () => block('p') },
  { key: 'bold', label: 'B', run: () => exec('bold') },
  { key: 'italic', label: 'I', run: () => exec('italic') },
  { key: 'ul', label: '•', run: () => exec('insertUnorderedList') },
  { key: 'ol', label: '1.', run: () => exec('insertOrderedList') },
  { key: 'link', icon: 'link', label: '', run: link },
  { key: 'unlink', label: '⨯🔗', run: () => exec('unlink') },
  { key: 'table', label: '▦', run: table },
  { key: 'code', label: '</>', run: () => block('pre') },
  { key: 'quote', label: '❝', run: () => block('blockquote') },
  { key: 'undo', label: '↶', run: () => exec('undo') },
  { key: 'redo', label: '↷', run: () => exec('redo') },
]
</script>

<template>
  <div class="rounded-xl bg-surface shadow-xs ring-1 ring-line">
    <div v-if="!readonly" class="sticky top-0 z-10 flex flex-wrap gap-1 border-b border-line bg-surface p-2" role="toolbar">
      <button
        v-for="tool in tools"
        :key="tool.key"
        type="button"
        class="min-w-8 rounded px-2 py-1 text-sm text-ink-muted hover:bg-surface-muted hover:text-ink"
        :title="t(`writer.editor.toolbar.${tool.key}`)"
        :aria-label="t(`writer.editor.toolbar.${tool.key}`)"
        @mousedown.prevent
        @click="tool.run"
      >
        <AppIcon v-if="tool.icon" :name="tool.icon as 'link'" :size="14" />
        <template v-else>{{ tool.label }}</template>
      </button>
    </div>
    <div
      ref="el"
      class="article-content min-h-[24rem] max-w-none px-5 py-4 focus:outline-none"
      :contenteditable="readonly ? 'false' : 'true'"
      role="textbox"
      aria-multiline="true"
      data-testid="rich-editor"
      @input="changed"
      @paste="onPaste"
    />
  </div>
</template>
