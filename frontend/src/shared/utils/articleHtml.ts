import DOMPurify from 'dompurify'

/**
 * Client-side mirror of the backend HtmlSanitizer allow-list for article
 * content. The server sanitizes again on every save; this keeps the editor
 * from ever holding (or rendering) script, event handlers, styles, iframes
 * or javascript: links — e.g. from a paste.
 */
export const ARTICLE_TAGS = [
  'h1', 'h2', 'h3', 'h4', 'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'sup', 'sub', 'mark',
  'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'caption', 'pre', 'code',
  'a', 'div', 'section', 'aside', 'figure', 'figcaption',
]
export const ARTICLE_ATTRS = ['href', 'title', 'data-evidence-id', 'data-internal', 'class', 'colspan', 'rowspan', 'scope', 'start', 'data-section']
const CLASSES = new Set(['callout', 'callout-info', 'callout-warning', 'faq', 'comparison', 'specs', 'steps', 'citation', 'cta'])

/** http(s), relative path or #anchor — nothing else (no javascript:, data:, mailto:...). */
export function isSafeHref(href: string): boolean {
  const v = href.trim()
  if (v === '') return false
  if (v.startsWith('#') || (v.startsWith('/') && !v.startsWith('//'))) return true
  try {
    const u = new URL(v)
    return u.protocol === 'http:' || u.protocol === 'https:'
  } catch {
    return false
  }
}

export function sanitizeArticleHtml(html: string): string {
  const fragment = DOMPurify.sanitize(html, {
    ALLOWED_TAGS: ARTICLE_TAGS,
    ALLOWED_ATTR: ARTICLE_ATTRS,
    ALLOW_DATA_ATTR: false,
    KEEP_CONTENT: true,
    RETURN_DOM_FRAGMENT: true,
  }) as DocumentFragment
  fragment.querySelectorAll('a').forEach((a) => {
    const href = a.getAttribute('href')
    if (href !== null && !isSafeHref(href)) a.removeAttribute('href')
    if (!a.hasAttribute('href') && !a.hasAttribute('data-evidence-id')) a.replaceWith(...Array.from(a.childNodes))
  })
  fragment.querySelectorAll('[class]').forEach((el) => {
    const keep = (el.getAttribute('class') ?? '').split(/\s+/).filter((c) => CLASSES.has(c))
    if (keep.length) el.setAttribute('class', keep.join(' '))
    else el.removeAttribute('class')
  })
  const div = document.createElement('div')
  div.appendChild(fragment)
  return div.innerHTML
}

/** Word count of the visible text (matches what editors expect, roughly). */
export function wordCount(html: string): number {
  const div = document.createElement('div')
  div.innerHTML = sanitizeArticleHtml(html)
  const text = (div.textContent ?? '').trim()
  return text === '' ? 0 : text.split(/\s+/).length
}
