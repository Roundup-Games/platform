<?php

use App\Services\PostHogConsentChecker;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

describe('PostHogConsentChecker', function () {
    it('returns true when analytics consent is granted', function () {
        $checker = new PostHogConsentChecker();

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', json_encode([
            'necessary' => true,
            'analytics' => true,
            'marketing' => false,
        ]));
        $request = Request::createFromBase($symfonyRequest);

        expect($checker->hasAnalyticsConsent($request))->toBeTrue();
    });

    it('returns false when analytics consent is denied', function () {
        $checker = new PostHogConsentChecker();

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', json_encode([
            'necessary' => true,
            'analytics' => false,
            'marketing' => false,
        ]));
        $request = Request::createFromBase($symfonyRequest);

        expect($checker->hasAnalyticsConsent($request))->toBeFalse();
    });

    it('returns false when cookie is missing', function () {
        $checker = new PostHogConsentChecker();

        $request = Request::create('/games', 'GET');

        expect($checker->hasAnalyticsConsent($request))->toBeFalse();
    });

    it('returns false when cookie is malformed JSON', function () {
        $checker = new PostHogConsentChecker();

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', 'not-json');
        $request = Request::createFromBase($symfonyRequest);

        expect($checker->hasAnalyticsConsent($request))->toBeFalse();
    });

    it('returns false when analytics key is missing from cookie', function () {
        $checker = new PostHogConsentChecker();

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', json_encode([
            'necessary' => true,
        ]));
        $request = Request::createFromBase($symfonyRequest);

        expect($checker->hasAnalyticsConsent($request))->toBeFalse();
    });

    it('handles already-decoded array cookie value', function () {
        $checker = new PostHogConsentChecker();

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        // Simulate Laravel decrypting the cookie into an array
        $symfonyRequest->cookies->set('cookie_consent', [
            'necessary' => true,
            'analytics' => true,
            'marketing' => false,
        ]);
        $request = Request::createFromBase($symfonyRequest);

        expect($checker->hasAnalyticsConsent($request))->toBeTrue();
    });

    it('returns full consent state via getConsentState', function () {
        $checker = new PostHogConsentChecker();

        $symfonyRequest = SymfonyRequest::create('/games', 'GET');
        $symfonyRequest->cookies->set('cookie_consent', json_encode([
            'necessary' => true,
            'analytics' => true,
            'marketing' => false,
        ]));
        $request = Request::createFromBase($symfonyRequest);

        $state = $checker->getConsentState($request);
        expect($state)->toBe([
            'necessary' => true,
            'analytics' => true,
            'marketing' => false,
        ]);
    });

    it('returns null from getConsentState when cookie is missing', function () {
        $checker = new PostHogConsentChecker();

        $request = Request::create('/games', 'GET');

        expect($checker->getConsentState($request))->toBeNull();
    });
});
