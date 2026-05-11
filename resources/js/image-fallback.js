/**
 * Graceful image fallback — replaces or hides broken <img> elements.
 *
 * Usage on <img> tags:
 *   data-fallback="placeholder"  → show a dice-icon placeholder (game system cards)
 *   data-fallback="hide"         → hide the img element
 *   data-fallback="initial"      → replace with parent's data-initial text (avatars)
 *   (no attribute)               → hide the img by default
 *
 * For avatar pattern: wrap the img in a container with data-initial="A"
 * so that when the img breaks, the initial letter replaces it.
 *
 * Uses capture-phase listener so it fires before any per-element onerror handlers.
 */
document.addEventListener('error', (e) => {
    const img = e.target;
    if (img.tagName !== 'IMG' || img.dataset.fallbackHandled) return;
    img.dataset.fallbackHandled = '1';

    const mode = img.dataset.fallback || 'hide';

    if (mode === 'placeholder' && img.parentElement) {
        const placeholder = document.createElement('div');
        placeholder.className = 'w-full h-full flex items-center justify-center bg-primary/5';
        placeholder.innerHTML = '<span class="material-symbols-outlined text-5xl text-primary/30" aria-hidden="true">casino</span>';
        img.replaceWith(placeholder);
    } else if (mode === 'initial' && img.parentElement) {
        const initial = img.parentElement.dataset.initial || '?';
        img.parentElement.innerHTML = initial;
    } else {
        img.style.display = 'none';
    }
}, true);
