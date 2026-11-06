/**
 * Site header chrome (public layout): popover pinning, mobile drawer,
 * scrolled bar state, shared theme segments, and the recent-accounts stamp.
 *
 * Everything is document-level event delegation so it survives Livewire
 * wire:navigate swaps without re-wiring: popover `toggle` events do not
 * bubble, but they DO pass through the capture phase — one capture
 * listener on document covers every popover, including ones injected by
 * later navigations.
 */

var REPIN = null;

function popoverTrigger(id) {
    return document.querySelector('[popovertarget="' + id + '"]');
}

function pinPopover(popover) {
    if (!popover.matches(':popover-open')) {
        return;
    }
    var trigger = popoverTrigger(popover.id);
    if (!trigger) {
        return;
    }
    var rect = trigger.getBoundingClientRect();
    popover.style.top = Math.round(rect.bottom + 6) + 'px';
    popover.style.left = 'auto';
    popover.style.right = Math.max(8, Math.round(window.innerWidth - rect.right)) + 'px';
}

function onToggleCapture(event) {
    var popover = event.target;
    if (!(popover instanceof HTMLElement) || !popover.matches('.site-popover')) {
        return;
    }
    var trigger = popoverTrigger(popover.id);
    var open = popover.matches(':popover-open');
    if (trigger) {
        trigger.setAttribute('aria-expanded', String(open));
    }
    if (open) {
        pinPopover(popover);
    } else {
        popover.style.top = '';
        popover.style.right = '';
    }
}

/* Repin while open — the bar is sticky, so the trigger moves with scroll. */
function repinOpenPopovers() {
    document.querySelectorAll('.site-popover:popover-open').forEach(pinPopover);
}

/* ── Theme segments (shared: popover + drawer + any future instance) ── */

function resolveDark(mode) {
    return mode === 'system'
        ? window.matchMedia('(prefers-color-scheme: dark)').matches
        : mode === 'dark';
}

function applyTheme(mode, persist) {
    document.documentElement.classList.toggle('dark', resolveDark(mode));
    document.querySelectorAll('[data-theme-seg]').forEach(function (button) {
        button.setAttribute('aria-pressed', String(button.dataset.themeSeg === mode));
    });
    if (persist) {
        try {
            localStorage.setItem('theme', mode);
        } catch (error) {
            /* storage blocked — theme stays a session-only choice */
        }
    }
}

/* ── Recent-accounts stamp (written from authenticated pages) ── */

function stampRecentAccount(payload) {
    try {
        var raw = localStorage.getItem('roundup.auth.recent');
        var list = raw ? JSON.parse(raw) : null;
        if (!Array.isArray(list)) {
            list = [];
        }
        list = list.filter(function (row) {
            return row && typeof row.email === 'string'
                && row.email.toLowerCase() !== payload.email.toLowerCase();
        });
        list.unshift({
            email: payload.email,
            name: payload.name,
            method: payload.method,
            at: Date.now(),
        });
        localStorage.setItem('roundup.auth.recent', JSON.stringify(list.slice(0, 3)));
    } catch (error) {
        /* blocked storage or corrupted payload — the chooser simply won't show */
    }
}

/* ── Mobile drawer (native <dialog>) ── */

function openDrawer(dialog, opener) {
    if (dialog && !dialog.open) {
        dialog.dataset.openerId = opener && opener.id ? opener.id : '';
        dialog.showModal();
    }
}

function onDelegatedClick(event) {
    var drawerButton = event.target.closest('[data-site-drawer]');
    if (drawerButton) {
        var dialog = document.getElementById('site-drawer');
        if (dialog) {
            openDrawer(dialog, drawerButton);
        }
        return;
    }

    var themeButton = event.target.closest('[data-theme-seg]');
    if (themeButton) {
        applyTheme(themeButton.dataset.themeSeg, true);
    }
}

function onDrawerClick(event) {
    if (!(event.target instanceof HTMLElement)) {
        return;
    }
    var link = event.target.closest('a');
    if (link) {
        link.closest('dialog').close();
        return;
    }
    if (event.target.closest('#site-drawer-close, [data-drawer-close]')) {
        this.close();
        return;
    }
    if (event.target === this) {
        this.close();
    }
}

function onDrawerClose() {
    var opener = document.getElementById(this.dataset.openerId || '');
    if (opener) {
        opener.focus();
    }
}

/* ── Scrolled bar state ── */

function onScroll() {
    var header = document.getElementById('site-header');
    if (header) {
        header.classList.toggle('is-scrolled', window.scrollY > 10);
    }
}

/* ── Wiring (idempotent — document-level listeners attach once) ── */

var wired = false;

function wireSiteHeader() {
    if (wired) {
        onScroll();
        return;
    }
    wired = true;

    document.addEventListener('toggle', onToggleCapture, true);
    window.addEventListener('scroll', repinOpenPopovers, { passive: true });
    window.addEventListener('resize', repinOpenPopovers);
    window.addEventListener('scroll', onScroll, { passive: true });

    document.addEventListener('click', onDelegatedClick);
    document.addEventListener('livewire:navigated', onScroll);

    var drawer = document.getElementById('site-drawer');
    if (drawer) {
        drawer.addEventListener('click', onDrawerClick);
        drawer.addEventListener('close', onDrawerClose);
    }

    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', function () {
            var mode;
            try {
                mode = localStorage.getItem('theme') || 'system';
            } catch (error) {
                mode = 'system';
            }
            if (mode === 'system') {
                applyTheme('system', false);
            }
        });

    /* Auth-side stamp: the app layout injects window.__roundupAuthStamp
       (name/email/method) right after a successful sign-in round-trip. */
    if (window.__roundupAuthStamp && window.__roundupAuthStamp.email) {
        stampRecentAccount(window.__roundupAuthStamp);
        window.__roundupAuthStamp = null;
    }

    /* Sync the shared theme segments with the stored preference (the
       pre-paint script already applied the class; this only aligns the
       buttons' aria-pressed state). */
    var storedTheme;
    try {
        storedTheme = localStorage.getItem('theme') || 'system';
    } catch (error) {
        storedTheme = 'system';
    }
    applyTheme(storedTheme, false);

    onScroll();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireSiteHeader);
} else {
    wireSiteHeader();
}

document.addEventListener('livewire:navigated', function () {
    /* wire:navigate replaces the DOM but keeps module state — re-run the
       drawer binding for the fresh <dialog> and re-stamp if present. */
    var drawer = document.getElementById('site-drawer');
    if (drawer && !drawer.dataset.wired) {
        drawer.dataset.wired = '1';
        drawer.addEventListener('click', onDrawerClick);
        drawer.addEventListener('close', onDrawerClose);
    }
    if (window.__roundupAuthStamp && window.__roundupAuthStamp.email) {
        stampRecentAccount(window.__roundupAuthStamp);
        window.__roundupAuthStamp = null;
    }
});

/* Keep any open popover pinned on Livewire navigations away. */
REPIN = repinOpenPopovers;

export { pinPopover, applyTheme, repinOpenPopovers };
