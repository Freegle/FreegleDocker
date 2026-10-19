import BaseAPI from '@/api/BaseAPI'

// Royal Mail address data (PAF) imports, for the SysAdmin Addresses tab. Admin only.
export default class PafAPI extends BaseAPI {
  fetchImports() {
    return this.$getv2('/sysadmin/paf')
  }

  startImport(key, edition) {
    // A wrong key or a second request is an expected refusal, not one for Sentry.
    return this.$postv2('/sysadmin/paf', { key, edition }, false)
  }
}
