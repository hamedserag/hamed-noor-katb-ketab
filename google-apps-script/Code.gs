const FOLDER_ID = '16GHLIT64O9zGlpI8SCbAX3vDfDJziw6K';
const MAX_BYTES = 10 * 1024 * 1024;

function doPost(e) {
  try {
    const payload = JSON.parse((e && e.postData && e.postData.contents) || '{}');
    const expectedSecret = PropertiesService.getScriptProperties().getProperty('UPLOAD_SECRET');

    if (!expectedSecret || payload.secret !== expectedSecret) {
      return jsonOutput({ success: false, message: 'Unauthorized.' });
    }

    if (!payload.fileName || !payload.mimeType || !payload.dataBase64) {
      return jsonOutput({ success: false, message: 'Missing upload fields.' });
    }

    const allowedTypes = [
      'image/jpeg',
      'image/png',
      'image/webp',
      'image/heic',
      'image/heif'
    ];

    if (!allowedTypes.includes(payload.mimeType)) {
      return jsonOutput({ success: false, message: 'Unsupported image type.' });
    }

    const bytes = Utilities.base64Decode(payload.dataBase64);
    if (bytes.length <= 0 || bytes.length > MAX_BYTES) {
      return jsonOutput({ success: false, message: 'Image exceeds the 10 MB limit.' });
    }

    const folder = DriveApp.getFolderById(FOLDER_ID);
    const blob = Utilities.newBlob(bytes, payload.mimeType, safeName(payload.fileName));
    const file = folder.createFile(blob);

    const guestName = String(payload.guestName || '').trim();
    const originalName = String(payload.originalName || '').trim();
    const description = [
      'Uploaded from the Hamed & Noor wedding invitation',
      guestName ? 'Guest: ' + guestName : '',
      originalName ? 'Original file: ' + originalName : ''
    ].filter(Boolean).join('\n');

    if (description) {
      file.setDescription(description);
    }

    return jsonOutput({
      success: true,
      fileId: file.getId(),
      fileUrl: file.getUrl(),
      fileName: file.getName()
    });
  } catch (error) {
    return jsonOutput({
      success: false,
      message: String(error && error.message ? error.message : error)
    });
  }
}

function safeName(value) {
  return String(value || 'photo')
    .replace(/[\\/:*?"<>|]/g, '_')
    .substring(0, 220);
}

function jsonOutput(value) {
  return ContentService
    .createTextOutput(JSON.stringify(value))
    .setMimeType(ContentService.MimeType.JSON);
}
