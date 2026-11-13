import test from 'node:test';
import assert from 'node:assert/strict';
import { isPreviewableImage, isStoredAttachmentResponse } from '../src/services/attachment-utils.js';

test('accepts only the API attachment creation response contract', () => {
    const response = { data: { id: 42, download_url: 'https://api.example.edu/api/v1/attachments/42' } };

    assert.equal(isStoredAttachmentResponse(201, response), true);
    assert.equal(isStoredAttachmentResponse(200, response), false);
    assert.equal(isStoredAttachmentResponse(201, { data: { id: 42 } }), false);
    assert.equal(isStoredAttachmentResponse(201, { message: 'Login' }), false);
    assert.equal(isStoredAttachmentResponse(204, null), false);
});

test('allows only supported raster images for inline private previews', () => {
    for (const mimeType of ['image/jpeg', 'image/png', 'image/webp']) {
        assert.equal(isPreviewableImage({ mime_type: mimeType }), true);
    }
    for (const mimeType of ['application/pdf', 'image/svg+xml', 'image/heic', null]) {
        assert.equal(isPreviewableImage({ mime_type: mimeType }), false);
    }
});
