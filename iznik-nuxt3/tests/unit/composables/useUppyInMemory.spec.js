import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createInMemoryPreProcessor } from '~/composables/useUppyInMemory'

const mockAction = vi.hoisted(() => vi.fn())
vi.mock('~/composables/useClientLog', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, action: (...args) => mockAction(...args) }
})

function fakeUppy(files = []) {
  const state = new Map(files.map((f) => [f.id, f]))

  return {
    getFile: (id) => state.get(id),
    setFileState: vi.fn((id, patch) => {
      state.set(id, { ...state.get(id), ...patch })
    }),
    peek: (id) => state.get(id),
  }
}

function pickedFile(data, overrides = {}) {
  return {
    id: 'file-1',
    name: 'photo.jpg',
    type: 'image/jpeg',
    size: data.size,
    data,
    meta: { name: 'photo.jpg', type: 'image/jpeg' },
    ...overrides,
  }
}

describe('createInMemoryPreProcessor', () => {
  beforeEach(() => {
    mockAction.mockClear()
  })

  it('replaces the picked file with an in-memory copy of the same bytes', async () => {
    const original = new File(['abcdef'], 'photo.jpg', {
      type: 'image/jpeg',
      lastModified: 1234,
    })
    const uppy = fakeUppy([pickedFile(original)])

    await createInMemoryPreProcessor({
      getUppy: () => uppy,
      uploader: 'photo',
    })(['file-1'])

    const copy = uppy.peek('file-1').data
    expect(copy).not.toBe(original)
    expect(copy).toBeInstanceOf(File)
    expect(copy.name).toBe('photo.jpg')
    expect(copy.type).toBe('image/jpeg')
    expect(copy.lastModified).toBe(1234)
    expect(await copy.text()).toBe('abcdef')
    expect(uppy.peek('file-1').size).toBe(6)
    expect(mockAction).not.toHaveBeenCalled()
  })

  it('keeps the original and logs why when the phone will not let us read it', async () => {
    const unreadable = {
      size: 263997,
      type: 'image/jpeg',
      arrayBuffer: () =>
        Promise.reject(
          Object.assign(new Error('The requested file could not be read'), {
            name: 'NotReadableError',
          })
        ),
    }
    const uppy = fakeUppy([pickedFile(unreadable)])

    await createInMemoryPreProcessor({ getUppy: () => uppy, uploader: 'our' })([
      'file-1',
    ])

    expect(uppy.setFileState).not.toHaveBeenCalled()
    expect(uppy.peek('file-1').data).toBe(unreadable)
    expect(mockAction).toHaveBeenCalledWith(
      'upload_file_unreadable',
      expect.objectContaining({
        uploader: 'our',
        error_name: 'NotReadableError',
        reason: 'The requested file could not be read',
        file_size: 263997,
        file_type: 'image/jpeg',
      })
    )
  })

  it('reads every file in the batch, even after one fails', async () => {
    const bad = {
      size: 1,
      type: 'image/jpeg',
      arrayBuffer: () => Promise.reject(new Error('nope')),
    }
    const good = new File(['xy'], 'b.jpg', { type: 'image/jpeg' })
    const uppy = fakeUppy([
      pickedFile(bad, { id: 'a' }),
      pickedFile(good, { id: 'b', name: 'b.jpg' }),
    ])

    await createInMemoryPreProcessor({
      getUppy: () => uppy,
      uploader: 'photo',
    })(['a', 'b'])

    expect(uppy.peek('b').data).not.toBe(good)
    expect(await uppy.peek('b').data.text()).toBe('xy')
  })

  it('ignores files with nothing to read and a missing Uppy', async () => {
    const uppy = fakeUppy([{ id: 'remote', data: {} }])
    const pre = createInMemoryPreProcessor({
      getUppy: () => uppy,
      uploader: 'photo',
    })

    await pre(['remote', 'missing'])
    await createInMemoryPreProcessor({ getUppy: () => null, uploader: 'x' })([
      'remote',
    ])

    expect(uppy.setFileState).not.toHaveBeenCalled()
  })
})
