export type Phase6Section = 'media' | 'seo' | 'publishing'

/** Tabs of each Phase 6 area: locale key under `p6.<ns>.nav` + route name. */
export const SECTION_NAV: Record<Phase6Section, { key: string; route: string }[]> = {
  media: [
    { key: 'studio', route: 'media' },
    { key: 'generator', route: 'media-generate' },
    { key: 'library', route: 'media-library' },
    { key: 'articles', route: 'media-article' },
  ],
  seo: [
    { key: 'audit', route: 'seo' },
    { key: 'metadata', route: 'seo-metadata' },
    { key: 'links', route: 'seo-links' },
  ],
  publishing: [
    { key: 'center', route: 'publishing' },
    { key: 'calendar', route: 'publishing-calendar' },
    { key: 'history', route: 'publishing-history' },
    { key: 'conflicts', route: 'publishing-conflicts' },
    { key: 'settings', route: 'publishing-settings' },
  ],
}

/** The publishing strings live under `p6.pub`, not `p6.publishing`. */
export function sectionLocaleNs(section: Phase6Section): 'media' | 'seo' | 'pub' {
  return section === 'publishing' ? 'pub' : section
}
