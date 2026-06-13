<?php

use App\Exceptions\BggApiException;
use App\Exceptions\BggParseException;
use App\Services\BggClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->searchResponseXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse" total="3">
  <item type="boardgame" id="13">
    <name type="primary" value="Catan"/>
    <yearpublished value="1995"/>
  </item>
  <item type="boardgame" id="277">
    <name type="primary" value="Catan Card Game"/>
    <yearpublished value="1996"/>
  </item>
  <item type="boardgameexpansion" id="39952">
    <name type="primary" value="Catan: Seafarers"/>
    <yearpublished value="1996"/>
  </item>
</items>
XML;

    $this->client = new BggClient(
        baseUrl: 'https://boardgamegeek.com/xmlapi2',
        token: 'test-token',
        rateLimitSeconds: 0,
        maxRetries: 2,
        retrySleepSeconds: 0,
    );
});

it('searches BGG and returns parsed XML', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::response(
            $this->searchResponseXml,
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $result = $this->client->search('Catan');

    expect($result)->toBeInstanceOf(SimpleXMLElement::class);
    expect((string) $result['total'])->toBe('3');
    expect($result->item)->toHaveCount(3);
    expect((string) $result->item[0]['id'])->toBe('13');
    expect((string) $result->item[0]->name['value'])->toBe('Catan');
    expect((string) $result->item[0]->yearpublished['value'])->toBe('1995');
});

it('encodes the query parameter in the URL', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::response(
            $this->searchResponseXml,
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $this->client->search('Catan');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'query=Catan')
            && str_contains($request->url(), 'type=boardgame,boardgameexpansion');
    });
});

it('handles special characters in search query', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::response(
            $this->searchResponseXml,
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $this->client->search('A Game of Thrones');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'query=A+Game+of+Thrones')
            || str_contains($request->url(), 'query=A%20Game%20of%20Thrones');
    });
});

it('retries on 202 cache miss', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::sequence()
            ->push('', 202)
            ->push($this->searchResponseXml, 200, ['Content-Type' => 'application/xml']),
    ]);

    $result = $this->client->search('Catan');

    expect($result)->toBeInstanceOf(SimpleXMLElement::class);
    expect((string) $result['total'])->toBe('3');
    Http::assertSentCount(2);
});

it('throws BggApiException after exhausting retries on 202', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::response('', 202),
    ]);

    $this->client->search('Catan');
})->throws(BggApiException::class);

it('throws BggApiException on 401/403', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::response('', 401),
    ]);

    $this->client->search('Catan');
})->throws(BggApiException::class);

it('throws BggApiException on server error', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::response('', 500),
    ]);

    $this->client->search('Catan');
})->throws(BggApiException::class);

it('throws BggApiException on timeout', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $this->client->search('Catan');
})->throws(BggApiException::class);

it('throws BggParseException on invalid XML response', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::response(
            'not valid xml',
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $this->client->search('Catan');
})->throws(BggParseException::class);

it('returns empty items when no results found', function () {
    $emptyResponse = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse" total="0"/>
XML;

    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::response(
            $emptyResponse,
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $result = $this->client->search('NonExistentGame12345');

    expect($result)->toBeInstanceOf(SimpleXMLElement::class);
    expect((string) $result['total'])->toBe('0');
    expect($result->item)->toBeEmpty();
});

it('sends auth token when configured', function () {
    Http::fake([
        'boardgamegeek.com/xmlapi2/search*' => Http::response(
            $this->searchResponseXml,
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $this->client->search('Catan');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', ['Bearer test-token']);
    });
});
