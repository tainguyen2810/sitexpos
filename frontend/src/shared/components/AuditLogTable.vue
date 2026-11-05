<script setup lang="ts">
import type { AuditLogEntry } from '@/shared/api/types'
import { formatDateTime } from '@/shared/utils/format'

defineProps<{ entries: AuditLogEntry[]; showWorkspace?: boolean }>()
</script>

<template>
  <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-line text-sm">
      <thead class="bg-surface-muted text-left text-xs uppercase tracking-wide text-ink-subtle">
        <tr>
          <th class="px-5 py-3 font-medium">{{ $t('audit.time') }}</th>
          <th class="px-5 py-3 font-medium">{{ $t('audit.actor') }}</th>
          <th class="px-5 py-3 font-medium">{{ $t('audit.action') }}</th>
          <th v-if="showWorkspace" class="px-5 py-3 font-medium">{{ $t('admin.workspaceId') }}</th>
          <th class="px-5 py-3 font-medium">IP</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-line">
        <tr v-for="e in entries" :key="e.id" class="align-top">
          <td class="whitespace-nowrap px-5 py-3 text-ink-muted">{{ formatDateTime(e.created_at) }}</td>
          <td class="px-5 py-3">
            <template v-if="e.actor">
              <p class="font-medium">{{ e.actor.name }}</p>
              <p class="text-xs text-ink-muted">{{ e.actor.email }}</p>
            </template>
            <span v-else class="text-ink-muted">{{ $t('audit.system') }}</span>
            <p v-if="e.impersonator" class="text-xs text-warning-600">{{ $t('audit.via', { name: e.impersonator.name }) }}</p>
          </td>
          <td class="px-5 py-3">
            <code class="rounded bg-surface-muted px-1.5 py-0.5 text-xs">{{ e.action }}</code>
            <p v-if="e.subject_type" class="mt-1 text-xs text-ink-subtle">{{ e.subject_type }} {{ e.subject_id }}</p>
          </td>
          <td v-if="showWorkspace" class="px-5 py-3 font-mono text-xs text-ink-muted">{{ e.workspace_id ?? '—' }}</td>
          <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-ink-muted">{{ e.ip ?? '—' }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
