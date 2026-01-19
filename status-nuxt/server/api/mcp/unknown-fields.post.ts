/**
 * Classify unknown log fields (admin action)
 *
 * POST /api/mcp/unknown-fields
 * Body: {
 *   action: 'classify' | 'clear-classified'
 *   source?: string  // Required for classify
 *   field?: string   // Required for classify
 *   classification?: 'PUBLIC' | 'SENSITIVE'  // Required for classify
 *   classifiedBy?: string
 * }
 */

import {
  classifyField,
  clearClassifiedFields,
  generateSchemaCode,
} from '../../utils/unknown-fields'

interface ClassifyRequest {
  action: 'classify' | 'clear-classified'
  source?: string
  field?: string
  classification?: 'PUBLIC' | 'SENSITIVE'
  classifiedBy?: string
}

export default defineEventHandler(async (event) => {
  const body = await readBody<ClassifyRequest>(event)

  if (!body.action) {
    throw createError({
      statusCode: 400,
      message: 'action is required',
    })
  }

  if (body.action === 'classify') {
    if (!body.source || !body.field || !body.classification) {
      throw createError({
        statusCode: 400,
        message: 'source, field, and classification are required for classify action',
      })
    }

    if (!['PUBLIC', 'SENSITIVE'].includes(body.classification)) {
      throw createError({
        statusCode: 400,
        message: 'classification must be PUBLIC or SENSITIVE',
      })
    }

    const success = classifyField(
      body.source,
      body.field,
      body.classification,
      body.classifiedBy
    )

    if (!success) {
      throw createError({
        statusCode: 404,
        message: `Unknown field ${body.source}.${body.field} not found`,
      })
    }

    // Return updated code snippet
    const code = generateSchemaCode()

    return {
      success: true,
      message: `Field ${body.source}.${body.field} classified as ${body.classification}`,
      codeSnippet: code,
    }
  }

  if (body.action === 'clear-classified') {
    const count = clearClassifiedFields()

    return {
      success: true,
      message: `Cleared ${count} classified fields from tracker`,
      clearedCount: count,
    }
  }

  throw createError({
    statusCode: 400,
    message: `Unknown action: ${body.action}`,
  })
})
