import { execSync } from 'child_process'
import { getDockerComposeCommand } from '../../utils/docker'

export default defineEventHandler(async () => {
  try {
    const DOCKER_COMPOSE = getDockerComposeCommand()
    console.log('Starting freegle-dev-live container with dev-live profile...')

    execSync(
      `${DOCKER_COMPOSE} --profile dev-live up -d freegle-dev-live`,
      { timeout: 120000, cwd: '/project' }
    )

    return { success: true, message: 'Container starting' }
  } catch (error: any) {
    console.error('Start live container error:', error)
    throw createError({
      statusCode: 500,
      message: error.message
    })
  }
})
