import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetchAdminv2 = vi.fn()
const mockAddWorrywordv2 = vi.fn()
const mockDeleteWorrywordv2 = vi.fn()
const mockAddSpamKeywordv2 = vi.fn()
const mockDeleteSpamKeywordv2 = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    config: {
      fetchAdminv2: mockFetchAdminv2,
      addWorrywordv2: mockAddWorrywordv2,
      deleteWorrywordv2: mockDeleteWorrywordv2,
      addSpamKeywordv2: mockAddSpamKeywordv2,
      deleteSpamKeywordv2: mockDeleteSpamKeywordv2,
    },
  }),
}))

describe('systemconfig store', () => {
  let useSystemConfigStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/systemconfig')
    useSystemConfigStore = mod.useSystemConfigStore
  })

  it('starts with empty state', () => {
    const store = useSystemConfigStore()
    expect(store.worrywords).toEqual([])
    expect(store.spam_keywords).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  describe('worrywords', () => {
    it('fetchWorrywords stores results', async () => {
      const store = useSystemConfigStore()
      store.init({})
      const words = [{ id: 1, keyword: 'danger', type: 'Review' }]
      mockFetchAdminv2.mockResolvedValue(words)

      await store.fetchWorrywords()

      expect(mockFetchAdminv2).toHaveBeenCalledWith('worry_words')
      expect(store.worrywords).toEqual(words)
      expect(store.loading).toBe(false)
    })

    it('fetchWorrywords handles null response', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockFetchAdminv2.mockResolvedValue(null)

      await store.fetchWorrywords()

      expect(store.worrywords).toEqual([])
    })

    it('fetchWorrywords handles error', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockFetchAdminv2.mockRejectedValue(new Error('Network error'))

      await store.fetchWorrywords()

      expect(store.error).toBe('Network error')
      expect(store.worrywords).toEqual([])
      expect(store.loading).toBe(false)
    })

    it('addWorryword calls API and refetches', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockAddWorrywordv2.mockResolvedValue()
      mockFetchAdminv2.mockResolvedValue([{ id: 1, keyword: 'test', type: 'Review' }])

      await store.addWorryword('test')

      expect(mockAddWorrywordv2).toHaveBeenCalledWith({
        keyword: 'test',
        type: 'Review',
        substance: '',
      })
      expect(mockFetchAdminv2).toHaveBeenCalled()
    })

    it('addWorryword trims whitespace', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockAddWorrywordv2.mockResolvedValue()
      mockFetchAdminv2.mockResolvedValue([])

      await store.addWorryword('  spaced  ')

      expect(mockAddWorrywordv2).toHaveBeenCalledWith(
        expect.objectContaining({ keyword: 'spaced' })
      )
    })

    it('addWorryword skips empty string', async () => {
      const store = useSystemConfigStore()
      store.init({})

      await store.addWorryword('')
      await store.addWorryword('   ')

      expect(mockAddWorrywordv2).not.toHaveBeenCalled()
    })

    it('addWorryword skips duplicate keyword', async () => {
      const store = useSystemConfigStore()
      store.init({})
      store.worrywords = [{ id: 1, keyword: 'existing' }]

      await store.addWorryword('existing')

      expect(mockAddWorrywordv2).not.toHaveBeenCalled()
    })

    it('addWorryword accepts custom type', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockAddWorrywordv2.mockResolvedValue()
      mockFetchAdminv2.mockResolvedValue([])

      await store.addWorryword('word', 'Block')

      expect(mockAddWorrywordv2).toHaveBeenCalledWith(
        expect.objectContaining({ type: 'Block' })
      )
    })

    it('deleteWorryword calls API and refetches', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockDeleteWorrywordv2.mockResolvedValue()
      mockFetchAdminv2.mockResolvedValue([])

      await store.deleteWorryword(5)

      expect(mockDeleteWorrywordv2).toHaveBeenCalledWith(5)
      expect(mockFetchAdminv2).toHaveBeenCalled()
    })

    it('deleteWorryword handles error', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockDeleteWorrywordv2.mockRejectedValue(new Error('Not found'))

      await store.deleteWorryword(999)

      expect(store.error).toBe('Not found')
      expect(store.loading).toBe(false)
    })
  })

  describe('spam keywords', () => {
    it('fetchSpamKeywords stores results', async () => {
      const store = useSystemConfigStore()
      store.init({})
      const keywords = [{ id: 1, word: 'spam', type: 'Literal' }]
      mockFetchAdminv2.mockResolvedValue(keywords)

      await store.fetchSpamKeywords()

      expect(mockFetchAdminv2).toHaveBeenCalledWith('spam_keywords')
      expect(store.spam_keywords).toEqual(keywords)
    })

    it('fetchSpamKeywords handles null', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockFetchAdminv2.mockResolvedValue(null)

      await store.fetchSpamKeywords()

      expect(store.spam_keywords).toEqual([])
    })

    it('fetchSpamKeywords handles error', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockFetchAdminv2.mockRejectedValue(new Error('Fail'))

      await store.fetchSpamKeywords()

      expect(store.error).toBe('Fail')
      expect(store.spam_keywords).toEqual([])
    })

    it('addSpamKeyword calls API and refetches', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockAddSpamKeywordv2.mockResolvedValue()
      mockFetchAdminv2.mockResolvedValue([])

      await store.addSpamKeyword('badword')

      expect(mockAddSpamKeywordv2).toHaveBeenCalledWith({
        word: 'badword',
        type: 'Literal',
        exclude: '',
        action: 'Review',
      })
    })

    it('addSpamKeyword skips empty', async () => {
      const store = useSystemConfigStore()
      await store.addSpamKeyword('')
      expect(mockAddSpamKeywordv2).not.toHaveBeenCalled()
    })

    it('addSpamKeyword skips duplicate', async () => {
      const store = useSystemConfigStore()
      store.spam_keywords = [{ id: 1, word: 'existing' }]

      await store.addSpamKeyword('existing')

      expect(mockAddSpamKeywordv2).not.toHaveBeenCalled()
    })

    it('deleteSpamKeyword calls API and refetches', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockDeleteSpamKeywordv2.mockResolvedValue()
      mockFetchAdminv2.mockResolvedValue([])

      await store.deleteSpamKeyword(3)

      expect(mockDeleteSpamKeywordv2).toHaveBeenCalledWith(3)
    })
  })

  describe('fetchAll', () => {
    it('fetches both worrywords and spam keywords', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockFetchAdminv2
        .mockResolvedValueOnce([{ id: 1, keyword: 'worry' }])
        .mockResolvedValueOnce([{ id: 2, word: 'spam' }])

      await store.fetchAll()

      expect(mockFetchAdminv2).toHaveBeenCalledTimes(2)
    })
  })

  describe('getters', () => {
    it('getWorrywords returns worrywords', () => {
      const store = useSystemConfigStore()
      store.worrywords = [{ id: 1, keyword: 'test' }]
      expect(store.getWorrywords).toEqual([{ id: 1, keyword: 'test' }])
    })

    it('getSpamKeywords returns spam_keywords', () => {
      const store = useSystemConfigStore()
      store.spam_keywords = [{ id: 1, word: 'spam' }]
      expect(store.getSpamKeywords).toEqual([{ id: 1, word: 'spam' }])
    })

    it('isLoading reflects loading state', () => {
      const store = useSystemConfigStore()
      expect(store.isLoading).toBe(false)
      store.loading = true
      expect(store.isLoading).toBe(true)
    })

    it('hasError reflects error state', () => {
      const store = useSystemConfigStore()
      expect(store.hasError).toBe(false)
      store.error = 'Something broke'
      expect(store.hasError).toBe(true)
    })

    it('getWorrywordKeywords extracts keywords', () => {
      const store = useSystemConfigStore()
      store.worrywords = [
        { id: 1, keyword: 'danger' },
        { id: 2, keyword: 'help' },
      ]
      expect(store.getWorrywordKeywords).toEqual(['danger', 'help'])
    })

    it('getSpamKeywordWords extracts words', () => {
      const store = useSystemConfigStore()
      store.spam_keywords = [
        { id: 1, word: 'buy' },
        { id: 2, word: 'click' },
      ]
      expect(store.getSpamKeywordWords).toEqual(['buy', 'click'])
    })
  })
})
