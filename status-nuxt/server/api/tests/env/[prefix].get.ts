import { execSync } from 'child_process'

// On-demand test environment creation. Called by the Playwright testEnv fixture.
// GET /api/tests/env/:prefix
// Returns the JSON from create-test-env.php for the given prefix.
// Idempotent: reuses existing data if prefix already exists.

const cache: Record<string, any> = {}

export default defineEventHandler(async (event) => {
  const prefix = getRouterParam(event, 'prefix')

  if (!prefix || !/^[a-z0-9]+$/.test(prefix)) {
    throw createError({ statusCode: 400, message: 'Invalid prefix' })
  }

  // Return cached result if already created this session
  if (cache[prefix]) {
    return cache[prefix]
  }

  try {
    const output = execSync(
      `docker exec freegle-apiv1 php /var/www/iznik/install/create-test-env.php ${prefix}`,
      { encoding: 'utf8', timeout: 30000 }
    )
    const env = JSON.parse(output.trim())
    cache[prefix] = env
    return env
  } catch (e: any) {
    console.error(`Failed to create test env for ${prefix}:`, e.message)
    throw createError({
      statusCode: 500,
      message: `Failed to create test environment: ${e.message}`,
    })
  }
})
