// Copies each photo into memory before the Uppy uploaders send it.
//
// On Android, a photo chosen in Chrome is a handle onto the phone's photo
// picker, not the bytes themselves. Chrome reads it again only when the upload
// request goes out, and for some phones and photos that second read fails: the
// request dies before it leaves the phone, and tus reports a bare network
// error. The server sees the CORS preflight and then nothing (Discourse 10240).
// The app does not have this problem because its picker hands us a copy.
//
// Reading the file once, straight after it is chosen, and uploading that copy
// takes the picker out of the upload. If even that first read fails, we log it,
// so the failure is named rather than looking like a blocked connection.
//
// Register it with addPreProcessor AFTER Compressor, so it copies whatever
// Compressor produced and never holds a photo larger than the one we upload.
import { action } from '~/composables/useClientLog'

export function createInMemoryPreProcessor({ getUppy, uploader }) {
  return async function copyFilesIntoMemory(fileIDs) {
    const uppy = getUppy()
    if (!uppy) return

    for (const id of fileIDs || []) {
      const file = uppy.getFile(id)
      const data = file?.data
      if (!data || typeof data.arrayBuffer !== 'function') continue

      const startedAt = Date.now()

      try {
        const bytes = await data.arrayBuffer()
        const name = data.name || file.meta?.name || file.name
        const copy = new File([bytes], name, {
          type: data.type || file.type,
          lastModified: data.lastModified,
        })

        uppy.setFileState(id, { data: copy, size: copy.size })
      } catch (e) {
        // Leave the file alone: the upload still gets its chance, and this
        // event says why it is about to fail.
        console.error('Could not read photo before upload', id, e)
        action('upload_file_unreadable', {
          uploader,
          error_name: e?.name || null,
          reason: e?.message || String(e),
          file_size: file.size ?? data.size ?? null,
          file_type: file.type || data.type || null,
          elapsed_ms: Date.now() - startedAt,
        })
      }
    }
  }
}
