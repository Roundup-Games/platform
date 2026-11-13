<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\post;

// Regression guard for the SQLSTATE 22021 incident (error tracking
// 2026-11-11): a lone UTF-16 surrogate posted to a public text form
// passed Laravel's `string`/`max` validation and 500'd on the Postgres
// insert. The global ScrubInvalidUtf8 middleware closes the gap for every
// form at once; these tests pin its contract through the full global
// middleware stack on a route that echoes its input back.

beforeEach(function () {
    Route::post('_testing/echo-input', function (Request $request) {
        return response()->json([
            'input' => $request->all(),
            'raw_content' => base64_encode($request->getContent()),
        ]);
    });
});

it('scrubs invalid UTF-8 sequences from form input', function () {
    $response = post('_testing/echo-input', [
        'name' => "Broken \xED\xA0\xBD Name",
        'email' => 'valid@example.com',
    ]);

    $response->assertOk();
    $name = $response->json('input.name');

    expect(mb_check_encoding($name, 'UTF-8'))->toBeTrue()
        ->and($name)->toBe('Broken ??? Name')
        ->and($response->json('input.email'))->toBe('valid@example.com');
});

it('scrubs invalid UTF-8 from query strings and nested form values', function () {
    // The query string rides on a POST request: the app's locale catch-all
    // owns unmatched GET paths, and the query bag is transformed the same
    // whichever method carries it.
    $post = post('_testing/echo-input?tag='.\rawurlencode("Bad \xED\xA0\xBD").'&keep=ok', [
        'tags' => ["first \xED\xA0\xBD", 'second'],
    ]);
    $post->assertOk();
    expect(mb_check_encoding((string) $post->json('input.tag'), 'UTF-8'))->toBeTrue()
        ->and($post->json('input.tag'))->toBe('Bad ???')
        ->and($post->json('input.keep'))->toBe('ok')
        ->and(mb_check_encoding((string) $post->json('input.tags.0'), 'UTF-8'))->toBeTrue()
        ->and($post->json('input.tags.1'))->toBe('second');
});

it('passes valid UTF-8 through byte-identical', function () {
    $valid = 'Grüße aus München 🎲 — schön';

    $response = post('_testing/echo-input', ['message' => $valid]);

    $response->assertOk();
    expect($response->json('input.message'))->toBe($valid);
});

it('leaves the raw request body untouched for webhook signature checks', function () {
    $raw = "name=Broken+\xED\xA0\xBD";

    $response = $this->call('POST', '_testing/echo-input', [], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $raw);

    $response->assertOk();
    // The parsed bag is scrubbed, but signature verifiers read the raw
    // body — it must still carry the original bytes.
    expect(base64_decode((string) $response->json('raw_content')))->toBe($raw)
        ->and(mb_check_encoding((string) $response->json('input.name'), 'UTF-8'))->toBeTrue();
});
