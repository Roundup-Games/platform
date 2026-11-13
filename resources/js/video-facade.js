/**
 * YouTube facade activation (CWV 2026-11).
 *
 * The Blade <x-youtube-facade> renders a poster + play button and defers
 * the real embed to this handler: on click the button is replaced by a
 * youtube-nocookie.com iframe with autoplay. The listener is registered at
 * document level (the site-header.js pattern), so facades keep working
 * across Livewire morphs and late-arriving markup without re-binding.
 *
 * Play analytics come free via PostHog autocapture on the <button> click.
 */

/**
 * Swap one facade button for the real player iframe.
 *
 * @param {HTMLButtonElement & { dataset: DOMStringMap }} button
 * @returns {void}
 */
export function activateYouTubeFacade(button) {
    const id = button.dataset.videoId;
    if (!id) return;

    const params = new URLSearchParams(button.dataset.embedParams || '');
    params.set('autoplay', '1');

    const iframe = document.createElement('iframe');
    iframe.src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?${params.toString()}`;
    iframe.title = button.dataset.videoTitle || 'YouTube video';
    iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
    iframe.allowFullscreen = true;
    iframe.referrerPolicy = 'strict-origin-when-cross-origin';
    iframe.className = 'absolute inset-0 w-full h-full';

    button.replaceWith(iframe);
}

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-youtube-facade-play]');
    if (button instanceof HTMLButtonElement) {
        activateYouTubeFacade(button);
    }
});
