@php
    $jsCategories = collect($cookieConsentConfig['categories'] ?? [])->map(function ($cat, $key) {
        return [
            'key' => $key,
            'required' => $cat['required'] ?? false,
            'default' => $cat['default'] ?? false,
        ];
    })->values()->all();
@endphp

@if($cookieConsentConfig['enabled'])
    {{-- Always render dialog HTML so showCookieDialog can re-show it --}}
    @include('cookie-consent::dialogContents')

    <script>
        window.laravelCookieConsent = (function () {
            const COOKIE_NAME = '{{ $cookieConsentConfig["cookie_name"] }}';
            const COOKIE_LIFETIME = {{ $cookieConsentConfig["cookie_lifetime"] }};
            const COOKIE_DOMAIN = '{{ config("session.domain") ?? request()->getHost() }}';
            const categories = @json($jsCategories);

            function getConsent() {
                const raw = getCookie(COOKIE_NAME);
                if (!raw) return null;
                try { return JSON.parse(decodeURIComponent(raw)); } catch { return null; }
            }

            function getCookie(name) {
                const match = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
                return match ? match.pop() : null;
            }

            function setConsentCookie(consent) {
                const date = new Date();
                date.setTime(date.getTime() + (COOKIE_LIFETIME * 24 * 60 * 60 * 1000));
                const encoded = encodeURIComponent(JSON.stringify(consent));
                document.cookie = COOKIE_NAME + '=' + encoded
                    + ';expires=' + date.toUTCString()
                    + ';domain=' + COOKIE_DOMAIN
                    + ';path=/'
                    + (location.protocol === 'https:' ? ';secure' : '')
                    + ';samesite=lax';
            }

            function buildConsentFromCheckboxes() {
                const consent = { necessary: true };
                document.querySelectorAll('.js-cookie-consent-category').forEach(function (el) {
                    consent[el.dataset.category] = el.checked;
                });
                consent.necessary = true;
                return consent;
            }

            function buildAcceptAllConsent() {
                const consent = { necessary: true };
                categories.forEach(function (cat) {
                    consent[cat.key] = true;
                });
                return consent;
            }

            function buildRejectOptionalConsent() {
                const consent = { necessary: true };
                categories.forEach(function (cat) {
                    consent[cat.key] = cat.required ? true : false;
                });
                return consent;
            }

            function saveAndClose(consent) {
                setConsentCookie(consent);
                hideCookieDialog();
                document.dispatchEvent(new CustomEvent('cookieConsentChanged', {
                    detail: { consent: consent },
                    bubbles: true,
                }));
            }

            function hideCookieDialog() {
                document.querySelectorAll('.js-cookie-consent').forEach(function (el) {
                    el.style.display = 'none';
                });
            }

            function showCookieDialog() {
                const dialog = document.querySelector('.js-cookie-consent');
                if (!dialog) return;

                const consent = getConsent();
                document.querySelectorAll('.js-cookie-consent-category').forEach(function (el) {
                    if (consent) {
                        el.checked = consent[el.dataset.category] ?? el.dataset.default === 'true';
                    } else {
                        el.checked = el.dataset.default === 'true';
                    }
                });

                dialog.style.display = '';
            }

            function hasConsented(category) {
                const consent = getConsent();
                if (!consent) return false;
                return consent[category] === true;
            }

            // Bind buttons
            document.querySelectorAll('.js-cookie-consent-accept-all').forEach(function (btn) {
                btn.addEventListener('click', function () { saveAndClose(buildAcceptAllConsent()); });
            });
            document.querySelectorAll('.js-cookie-consent-accept-selected').forEach(function (btn) {
                btn.addEventListener('click', function () { saveAndClose(buildConsentFromCheckboxes()); });
            });
            document.querySelectorAll('.js-cookie-consent-reject-optional').forEach(function (btn) {
                btn.addEventListener('click', function () { saveAndClose(buildRejectOptionalConsent()); });
            });

            // If already consented, hide immediately (but dialog HTML stays in DOM for re-show)
            if (getConsent()) {
                hideCookieDialog();
            }

            return {
                hasConsented: hasConsented,
                showCookieDialog: showCookieDialog,
                hideCookieDialog: hideCookieDialog,
                getConsent: getConsent,
                buildAcceptAllConsent: buildAcceptAllConsent,
            };
        })();
    </script>
@endif
