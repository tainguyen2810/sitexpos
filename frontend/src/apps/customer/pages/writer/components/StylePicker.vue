<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import { BUILTIN_STYLES, BUILTIN_STYLE_ICONS, STYLE_ORIGIN_ICONS, hasStyle, type BuiltinStyleKey, type Preset } from '@/shared/api/writer'

export type StyleChoice = { kind: 'preset'; id: string } | { kind: 'builtin'; key: BuiltinStyleKey } | null

const props = defineProps<{ presets: Preset[]; teachTo?: RouteLocationRaw }>()
const model = defineModel<StyleChoice>({ default: null })
const { t } = useI18n()

const showAll = ref(false)
// Presets with a learned/applied style first; plain presets (tone only) still count as "yours".
const mine = computed(() => [...props.presets].sort((a, b) => Number(b.is_default) - Number(a.is_default) || Number(hasStyle(b)) - Number(hasStyle(a))))
const builtins = computed(() => {
  const all = [...BUILTIN_STYLES]
  if (showAll.value) return all
  const first = all.slice(0, 3)
  // Keep a chosen ready-made style visible even when the list is folded.
  if (model.value?.kind === 'builtin' && !first.includes(model.value.key)) first.push(model.value.key)
  return first
})
const hidden = computed(() => BUILTIN_STYLES.filter((k) => !builtins.value.includes(k)))

function sourceLine(p: Preset): string {
  const origin = p.style_sources?.origin
  const n = p.style_sources?.used?.length ?? p.style_draft?.sample_count ?? 0
  if (origin && origin.type !== 'builtin') return `${STYLE_ORIGIN_ICONS[origin.type]} ${t(`writer.styles.origin.${origin.type}`)}${n ? ` · ${t('writer.styles.samples', { n })}` : ''}`
  if (origin?.type === 'builtin') return t('writer.styles.fromBuiltin', { name: t(`writer.builtin.${origin.label}.name`) })
  if (hasStyle(p)) return t('writer.styles.learned')
  return p.tone ? p.tone : t('writer.styles.noLearned')
}
const isPreset = (id: string) => model.value?.kind === 'preset' && model.value.id === id
const isBuiltin = (k: BuiltinStyleKey) => model.value?.kind === 'builtin' && model.value.key === k
const card = 'relative flex flex-col rounded-xl border-[1.5px] bg-surface p-3 text-left transition hover:border-brand-300 focus-visible:outline-2'
const on = 'border-brand-600 bg-brand-50 ring-4 ring-brand-100'
</script>

<template>
  <div data-testid="style-picker">
    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('writer.simple.style.mine') }}</p>
    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3" role="radiogroup" :aria-label="t('writer.simple.style.mine')">
      <button
        v-for="p in mine" :key="p.id" type="button" role="radio" :aria-checked="isPreset(p.id)"
        :class="[card, isPreset(p.id) ? on : 'border-line']" @click="model = { kind: 'preset', id: p.id }"
      >
        <span v-if="p.is_default" class="absolute right-2.5 top-2.5 rounded-full bg-success-50 px-2 py-0.5 text-[11px] font-semibold text-success-600">{{ t('writer.presets.default') }}</span>
        <span class="pr-16 text-sm font-semibold text-ink">{{ p.name }}</span>
        <span class="mt-0.5 text-xs text-ink-muted">{{ sourceLine(p) }}</span>
        <span v-if="p.style_profile?.summary" class="mt-1 line-clamp-2 text-xs text-ink-muted">{{ p.style_profile.summary }}</span>
      </button>
      <RouterLink
        v-if="teachTo" :to="teachTo" data-testid="teach-style"
        class="flex min-h-[88px] flex-col items-center justify-center rounded-xl border-[1.5px] border-dashed border-brand-200 bg-brand-50/50 p-3 text-center text-brand-700 hover:border-brand-400"
      >
        <span class="text-lg leading-none" aria-hidden="true">＋</span>
        <span class="mt-1 text-sm font-semibold">{{ t('writer.styles.teach') }}</span>
        <span class="text-xs text-ink-muted">{{ t('writer.styles.teachHint') }}</span>
      </RouterLink>
    </div>

    <p class="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('writer.simple.style.builtin') }}</p>
    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3" role="radiogroup" :aria-label="t('writer.simple.style.builtin')">
      <button
        v-for="k in builtins" :key="k" type="button" role="radio" :aria-checked="isBuiltin(k)" :data-testid="`builtin-${k}`"
        :class="[card, isBuiltin(k) ? on : 'border-line']" @click="model = { kind: 'builtin', key: k }"
      >
        <span class="text-lg leading-none" aria-hidden="true">{{ BUILTIN_STYLE_ICONS[k] }}</span>
        <span class="mt-1.5 text-sm font-semibold text-ink">{{ t(`writer.builtin.${k}.name`) }}</span>
        <span class="text-xs text-ink-muted">{{ t(`writer.builtin.${k}.desc`) }}</span>
      </button>
    </div>
    <button v-if="hidden.length" type="button" class="mt-2 text-sm font-medium text-brand-600 hover:underline" @click="showAll = true">
      {{ t('writer.simple.style.more', { n: hidden.length, names: hidden.map((k) => t(`writer.builtin.${k}.name`)).join(' · ') }) }} →
    </button>
  </div>
</template>
