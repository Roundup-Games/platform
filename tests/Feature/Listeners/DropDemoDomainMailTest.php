<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    // Use a real (non-fake) mailer so MessageSending/MessageSent actually fire.
    $this->mailer = Mail::mailer('array');

    // Count only messages that make it past DropDemoDomainMail.
    $this->sent = collect();
    Event::listen(MessageSent::class, fn () => $this->sent->push(true));
});

it('delivers mail to a real domain', function () {
    $this->mailer->raw('hi', fn ($m) => $m->to('someone@realdomain.test')->subject('real'));

    expect($this->sent)->toHaveCount(1);
});

it('drops mail to the demo domain (@example.org)', function () {
    $this->mailer->raw('hi', fn ($m) => $m->to('john.doe.1234@example.org')->subject('demo'));

    expect($this->sent)->toHaveCount(0);
});

it('drops mail when any reserved demo domain appears in to/cc/bcc', function () {
    // Blocked via Cc — entire message is dropped
    $this->mailer->raw('hi', fn ($m) => $m
        ->to('real@realdomain.test')
        ->cc('spy@example.net')
        ->subject('mixed'));
    expect($this->sent)->toHaveCount(0);

    // Blocked via Bcc
    $this->mailer->raw('hi', fn ($m) => $m
        ->to('real@realdomain.test')
        ->bcc('hidden@example.com')
        ->subject('bcc'));
    expect($this->sent)->toHaveCount(0);
});

it('is case-insensitive on the domain', function () {
    $this->mailer->raw('hi', fn ($m) => $m->to('User@EXAMPLE.ORG')->subject('case'));

    expect($this->sent)->toHaveCount(0);
});
