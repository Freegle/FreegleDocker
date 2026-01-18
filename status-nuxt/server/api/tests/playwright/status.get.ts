// TODO: Implement actual Go test status tracking
// This will need to track test execution state

export default defineEventHandler(async () => {
  return {
    status: 'idle',
    message: '',
    logs: '',
    progress: {
      total: 0,
      completed: 0,
      passed: 0,
      failed: 0,
    },
  }
})
