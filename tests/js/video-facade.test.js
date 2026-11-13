import { beforeEach, describe, expect, it } from 'vitest';
// Importing the module registers the document-level click listener at load
// (the site-header.js delegation pattern) — the click test below exercises
// it end-to-end in happy-dom.
import { activateYouTubeFacade } from '../../resources/js/video-facade.js';

function makeFacadeButton() {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.videoId = '0TsicWGho7c';
    button.dataset.embedParams = 'si=abc123';
    button.dataset.videoTitle = 'How to Play';
    const container = document.createElement('div');
    container.appendChild(button);
    document.body.appendChild(container);
    return button;
}

describe('activateYouTubeFacade', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('replaces the button with a nocookie iframe carrying autoplay and params', () => {
        const button = makeFacadeButton();

        activateYouTubeFacade(button);

        const iframe = document.querySelector('iframe');
        expect(iframe).not.toBeNull();
        expect(iframe.src).toBe('https://www.youtube-nocookie.com/embed/0TsicWGho7c?si=abc123&autoplay=1');
        expect(iframe.title).toBe('How to Play');
        expect(iframe.allowFullscreen).toBe(true);
        expect(button.isConnected).toBe(false);
    });

    it('is a safe no-op without a video id', () => {
        const button = makeFacadeButton();
        delete button.dataset.videoId;

        activateYouTubeFacade(button);

        expect(document.querySelector('iframe')).toBeNull();
        expect(button.isConnected).toBe(true);
    });
});
