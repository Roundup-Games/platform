<?php

use App\Enums\NotificationCategory;
use App\Listeners\TagProjectMail;
use App\Models\Game;
use App\Models\User;
use App\Notifications\Channels\TaggedMailChannel;
use App\Notifications\WaitlistPromoted;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

// ══════════════════════════════════════════════════════
// TagProjectMail (M080/S06) — shared-Mailpit project
// separation. The auto-discovered MessageSending listener
// stamps X-Tags: <mail.project_tag> on every outgoing
// message (notification mail AND Mailables/raw), config-
// gated so prod (MAIL_PROJECT_TAG unset) sends no header.
//
// Sends run through a real (non-fake) array mailer so
// MessageSending actually fires (phpunit.xml MAIL_MAILER=
// array). All listeners on the event share the same Symfony
// message object, so headers stamped by earlier listeners
// are visible on the captured message regardless of
// registration order.
// ══════════════════════════════════════════════════════

beforeEach(function () {
    // T02 wires MAIL_PROJECT_TAG into the dev .env and phpunit.xml does
    // not pin it, so the env value leaks into test config — reset to the
    // prod shape; every case states its own tag explicitly.
    config(['mail.project_tag' => null]);

    $this->mailer = Mail::mailer('array');

    $this->capturedMails = collect();
    Event::listen(MessageSending::class, function (MessageSending $event): void {
        $this->capturedMails->push($event->message);
    });
});

it('stamps the project tag header on raw mail when the tag is configured', function () {
    config(['mail.project_tag' => 'roundup-games-dev']);

    // Non-example-domain recipient only: DropDemoDomainMail halts
    // example.{org,com,net} sends inside until() before later listeners
    // run; the .test TLD passes through.
    $this->mailer->raw('hi', fn ($m) => $m->to('someone@realdomain.test')->subject('tag test'));

    expect($this->capturedMails)->toHaveCount(1)
        ->and($this->capturedMails[0]->getHeaders()->get(TagProjectMail::HEADER_NAME)?->getValue())
        ->toBe('roundup-games-dev');
});

it('stamps the project tag on notification mail next to the category metadata header', function () {
    config(['mail.project_tag' => 'roundup-games-dev']);

    $user = User::factory()->create();
    $game = Game::factory()->create();

    app(NotificationService::class)->send(
        $user,
        new WaitlistPromoted($game, Carbon::parse('next friday 18:00')),
        NotificationCategory::WaitlistPromoted,
    );

    $headers = $this->capturedMails[0]->getHeaders();

    expect($this->capturedMails)->toHaveCount(1)
        ->and($headers->get(TagProjectMail::HEADER_NAME)?->getValue())->toBe('roundup-games-dev')
        ->and($headers->get('X-Metadata-'.TaggedMailChannel::CATEGORY_TAG)?->getValue())->toBe('waitlist_promoted');
});

it('sends no project tag header when none is configured (prod shape)', function () {
    $this->mailer->raw('hi', fn ($m) => $m->to('someone@realdomain.test')->subject('prod shape'));

    expect($this->capturedMails)->toHaveCount(1)
        ->and($this->capturedMails[0]->getHeaders()->get(TagProjectMail::HEADER_NAME))->toBeNull();
});

it('keeps a sender-stamped tag header instead of duplicating it', function () {
    config(['mail.project_tag' => 'roundup-games-dev']);

    $this->mailer->raw('hi', fn ($m) => $m
        ->to('someone@realdomain.test')
        ->subject('custom tag')
        ->getHeaders()->addTextHeader(TagProjectMail::HEADER_NAME, 'custom-tag'));

    // Headers::all($name) yields a generator — materialize it for the
    // count/value assertions.
    $stamped = iterator_to_array($this->capturedMails[0]->getHeaders()->all(TagProjectMail::HEADER_NAME));

    expect($this->capturedMails)->toHaveCount(1)
        ->and($stamped)->toHaveCount(1)
        ->and($stamped[0]->getValue())->toBe('custom-tag');
});
