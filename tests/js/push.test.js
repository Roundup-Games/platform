import { describe, expect, it } from 'vitest';
import { toApiKeys, urlBase64ToUint8Array } from '../../resources/js/push.js';

describe('urlBase64ToUint8Array', () => {
    it('decodes a standard base64 string with padding', () => {
        // "Hello" → SGVsbG8=
        const bytes = urlBase64ToUint8Array('SGVsbG8=');
        expect(bytes).toBeInstanceOf(Uint8Array);
        expect([...bytes]).toEqual([72, 101, 108, 108, 111]);
    });

    it('adds missing padding for unpadded input', () => {
        // Same payload without the '=' pad.
        expect([...urlBase64ToUint8Array('SGVsbG8')]).toEqual([72, 101, 108, 108, 111]);
        // 'AQ' + '==' pad → [1]
        expect([...urlBase64ToUint8Array('AQ')]).toEqual([1]);
    });

    it('translates base64url characters (- and _) before decoding', () => {
        // '+/+' and '+/+/' written in base64url as '-_-' / '-_-_' decode to
        // [0xFB, 0xFF] / [0xFB, 0xFF, 0xBF] — the URL-safe alphabet real
        // VAPID keys use.
        expect([...urlBase64ToUint8Array('-_-')]).toEqual([251, 255]);
        expect([...urlBase64ToUint8Array('-_-_')]).toEqual([251, 255, 191]);
    });
});

describe('toApiKeys (p256dh → p256h remap)', () => {
    it('remaps p256dh to the short p256h name and keeps auth', () => {
        expect(toApiKeys({ p256dh: 'BC-key', auth: 'auth-token' })).toEqual({
            p256h: 'BC-key',
            auth: 'auth-token',
        });
    });

    it('falls back to empty strings for missing keys', () => {
        expect(toApiKeys({})).toEqual({ p256h: '', auth: '' });
        expect(toApiKeys(undefined)).toEqual({ p256h: '', auth: '' });
        expect(toApiKeys({ auth: 'only-auth' })).toEqual({ p256h: '', auth: 'only-auth' });
    });
});
