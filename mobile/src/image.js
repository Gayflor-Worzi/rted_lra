import { manipulateAsync, SaveFormat } from 'expo-image-manipulator'

// Field uploads travel over slow mobile links, so photos are compacted before
// they leave the device:
//   - camera shots (sensor photos, usually 8–12 MP) are downscaled to at most
//     1600px wide and recompressed — ~200–400 KB instead of several MB;
//   - gallery shots are recompressed at their original resolution (never
//     upscaled when already small).
// This also drops the picker's `base64: true` full-resolution allocation, the
// usual Android OOM trigger when saving evidence in the field.
const MAX_WIDTH = 1600

/**
 * Downscale + recompress a photo to a compact JPEG, returning a `data:image/jpeg` URI.
 * Returns null on any failure so callers can fall back gracefully.
 */
export async function shrinkPhoto(uri) {
  try {
    const res = await manipulateAsync(uri, [{ resize: { width: MAX_WIDTH } }], {
      compress: 0.65,
      format: SaveFormat.JPEG,
      base64: true,
    })
    if (res?.base64) return `data:image/jpeg;base64,${res.base64}`
  } catch {}
  return null
}

/** Recompress a photo at its original resolution (gallery picks). Never upscales. */
export async function recompressPhoto(uri) {
  try {
    const res = await manipulateAsync(uri, [], {
      compress: 0.65,
      format: SaveFormat.JPEG,
      base64: true,
    })
    if (res?.base64) return `data:image/jpeg;base64,${res.base64}`
  } catch {}
  return null
}

/**
 * Best-available upload value for a picked photo `{ uri, fromCamera }`.
 * Camera shots are downscaled; gallery shots are recompressed. Falls back to an
 * already-encoded base64 (`base64`/`dataUri`) then finally the raw uri so a
 * capture is never lost if image processing is unavailable.
 */
export async function photoToUpload(photo) {
  if (!photo) return undefined
  if (photo.dataUri) return photo.dataUri
  if (photo.base64) return `data:image/jpeg;base64,${photo.base64}`
  const dataUri = photo.fromCamera ? await shrinkPhoto(photo.uri) : await recompressPhoto(photo.uri)
  if (dataUri) return dataUri
  return photo.uri
}