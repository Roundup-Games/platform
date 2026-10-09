import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { showOfflineToast, showToast } from '../../resources/js/toast.js';

describe('showToast', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('appends a toast with role=status, aria-live=polite, message, and icon', () => {
        const toast = showToast({ id: 't1', message: 'Saved successfully', icon: 'cloud_off' });

        expect(document.body.contains(toast)).toBe(true);
        expect(toast.id).toBe('t1');
        expect(toast.getAttribute('role')).toBe('status');
        expect(toast.getAttribute('aria-live')).toBe('polite');
        expect(toast.textContent).toContain('Saved successfully');

        const icon = toast.querySelector('span[aria-hidden="true"]');
        expect(icon).not.toBeNull();
        expect(icon.textContent).toBe('cloud_off');
    });

    it('applies surface variant classes by default and primary variant on request', () => {
        const surface = showToast({ id: 's1', message: 'surface', variant: 'surface' });
        expect(surface.className).toContain('bg-surface');
        expect(surface.className).toContain('border-outline-variant');
        expect(surface.className).toContain('sm:w-96');

        const primary = showToast({ id: 's2', message: 'primary', variant: 'primary' });
        expect(primary.className).toContain('bg-primary');
        expect(primary.className).toContain('text-on-primary');
    });

    it('replaces an existing toast with the same id instead of duplicating', () => {
        showToast({ id: 'dedupe', message: 'first' });
        showToast({ id: 'dedupe', message: 'second' });

        const toasts = document.querySelectorAll('#dedupe');
        expect(toasts.length).toBe(1);
        expect(toasts[0].textContent).toContain('second');
    });

    it('omits the icon element when no icon is given', () => {
        const toast = showToast({ id: 'no-icon', message: 'plain' });
        expect(toast.querySelector('span[aria-hidden="true"]')).toBeNull();
    });

    it('auto-dismisses after ttlMs', () => {
        vi.useFakeTimers();
        showToast({ id: 'ttl', message: 'fleeting', ttlMs: 1000 });

        expect(document.getElementById('ttl')).not.toBeNull();
        vi.advanceTimersByTime(1000);
        expect(document.getElementById('ttl')).toBeNull();
    });

    it('keeps the toast indefinitely when ttlMs is 0', () => {
        vi.useFakeTimers();
        showToast({ id: 'sticky', message: 'sticky', ttlMs: 0 });

        vi.advanceTimersByTime(60_000);
        expect(document.getElementById('sticky')).not.toBeNull();
    });

    it('dismiss button removes the toast and cancels the pending auto-dismiss', () => {
        vi.useFakeTimers();
        showToast({ id: 'dismiss', message: 'dismissible', ttlMs: 1000 });

        document.querySelector('#dismiss [data-toast-dismiss]').click();
        expect(document.getElementById('dismiss')).toBeNull();

        // Advancing past the original TTL must not throw or resurrect anything.
        expect(() => vi.advanceTimersByTime(2000)).not.toThrow();
        expect(document.getElementById('dismiss')).toBeNull();
    });

    it('action button invokes onAction with the toast and cancels auto-dismiss', () => {
        vi.useFakeTimers();
        const onAction = vi.fn();
        const toast = showToast({
            id: 'action',
            message: 'update available',
            actionLabel: 'Update',
            onAction,
            ttlMs: 1000,
        });

        const actionButton = toast.querySelector('[data-toast-action]');
        expect(actionButton.textContent).toBe('Update');

        actionButton.click();
        expect(onAction).toHaveBeenCalledOnce();
        expect(onAction).toHaveBeenCalledWith(toast);

        // Auto-dismiss cancelled so the action is never interrupted mid-flight.
        vi.advanceTimersByTime(5000);
        expect(document.getElementById('action')).not.toBeNull();

        // Dismiss button still works afterwards.
        toast.querySelector('[data-toast-dismiss]').click();
        expect(document.getElementById('action')).toBeNull();
    });
});

describe('showOfflineToast', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('renders the offline toast with default copy and cloud_off icon', () => {
        showOfflineToast();

        const toast = document.getElementById('offline-action-toast');
        expect(toast).not.toBeNull();
        expect(toast.getAttribute('role')).toBe('status');
        expect(toast.textContent).toContain("You're offline — connect to complete this action");
        expect(toast.querySelector('span[aria-hidden="true"]').textContent).toBe('cloud_off');
    });

    it('uses localized strings from window.__pwaOfflineToast when present', () => {
        vi.stubGlobal('__pwaOfflineToast', { offline: 'Keine Verbindung' });

        showOfflineToast();

        expect(document.getElementById('offline-action-toast').textContent).toContain('Keine Verbindung');
    });

    it('replaces a previous offline toast rather than stacking', () => {
        showOfflineToast();
        showOfflineToast();
        expect(document.querySelectorAll('#offline-action-toast').length).toBe(1);
    });
});
