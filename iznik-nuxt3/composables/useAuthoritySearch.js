export function normalizeAuthoritySearch(results, limit = 5) {
  // V2 Go API returns a bare array; V1 PHP returned { authorities: [...] }.
  const list = Array.isArray(results)
    ? results
    : Array.isArray(results?.authorities)
      ? results.authorities
      : []

  const trimmed = list.slice(0, limit)
  const ret = []
  for (const authority of trimmed) {
    if (authority && authority.name) {
      ret.push(authority)
    }
  }
  return ret
}
