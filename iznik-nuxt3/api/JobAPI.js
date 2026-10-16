import BaseAPI from '@/api/BaseAPI'

export default class JobAPI extends BaseAPI {
  async fetchv2(lat, lng, category) {
    // No need to log errors about this request - it often times out.
    const params = {
      lat,
      lng,
    }

    if (category) {
      params.category = category
    }
    return await this.$getv2('/job', params, false)
  }

  async fetchOnev2(id, logError) {
    return await this.$getv2('/job/' + id, {}, logError)
  }

  async log(params) {
    // The email landing page sends the member on to the advert as soon as it has called this,
    // and leaving the page cancels an ordinary request: from 12 Oct 2026 a sixth of email-page
    // clicks, and seven in ten on iPhone Safari, never reached logs_jobs. keepalive lets the
    // request finish after the page has gone.
    await this.$postv2('/job', params, true, { keepalive: true })
  }
}
