export function isStoredAttachmentResponse(status, payload) {
    const attachment = payload?.data;

    return status === 201
        && Number.isSafeInteger(Number(attachment?.id))
        && Number(attachment.id) > 0
        && typeof attachment.download_url === 'string'
        && attachment.download_url.length > 0;
}

export function isPreviewableImage(attachment) {
    return ['image/jpeg', 'image/png', 'image/webp'].includes(attachment?.mime_type);
}
