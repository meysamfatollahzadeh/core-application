import { mdiDomain, mdiAccountGroup, mdiSitemap, mdiOfficeBuilding, mdiAccountTie, mdiShieldAccount, mdiKeyChain, mdiShieldKey } from '@mdi/js'
import { adminSections, buildAdminNavigation } from '#shared/utils/admin-navigation'

export function useAdminSections() {
  const { state } = useAccess()
  const icons = { companies: mdiDomain, positions: mdiSitemap, buildings: mdiOfficeBuilding, personnel: mdiAccountGroup, assignments: mdiAccountTie, roles: mdiShieldAccount, grants: mdiKeyChain }
  const groupIcons = { organization: mdiDomain, personnel: mdiAccountGroup, access: mdiShieldKey }
  const sections = adminSections.map(section => ({ ...section, icon: icons[section.key] }))
  const groups = computed(() => buildAdminNavigation(state.value).map(group => ({
    ...group, icon: groupIcons[group.key], children: group.children.map(section => ({ ...section, icon: icons[section.key] })),
  })))
  const available = computed(() => groups.value.flatMap(group => group.children))
  return { sections, available, groups }
}
