<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The hand-written Brevo transport.
 *
 * Worth testing precisely because it is hand-written: the first-party
 * transports are covered by Laravel's own suite, and this one is not covered
 * by anybody's. Every assertion here is about the shape of the request Brevo
 * receives, because that shape is the contract.
 */
beforeEach(function (): void {
    config([
        'mail.default' => 'brevo',
        'services.brevo.key' => 'test-key',
        'mail.from.address' => 'shift@example.com',
        'mail.from.name' => 'Remo Attendance',
    ]);
});

it('posts the message to the Brevo API', function (): void {
    Http::fake([
        'api.brevo.com/*' => Http::response(['messageId' => '<abc@brevo>'], 201),
    ]);

    Mail::raw('The body', fn ($m) => $m->to('tasker@example.com')->subject('A subject'));

    Http::assertSent(function ($request): bool {
        expect($request->url())->toBe('https://api.brevo.com/v3/smtp/email')
            ->and($request->method())->toBe('POST')
            // The key travels in a header, never in the URL or the body.
            ->and($request->header('api-key')[0])->toBe('test-key');

        $body = $request->data();

        expect($body['sender']['email'])->toBe('shift@example.com')
            ->and($body['sender']['name'])->toBe('Remo Attendance')
            ->and($body['to'])->toBe([['email' => 'tasker@example.com']])
            ->and($body['subject'])->toBe('A subject')
            ->and($body['textContent'])->toContain('The body');

        return true;
    });
});

it('sends the HTML body of a real notification', function (): void {
    // The shift notifications are HTML. A transport that only carried the text
    // part would deliver blank messages and look like it was working.
    Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);

    Mail::html('<p>Hello</p>', fn ($m) => $m->to('tasker@example.com')->subject('HTML'));

    Http::assertSent(function ($request): bool {
        expect($request->data()['htmlContent'])->toContain('<p>Hello</p>');

        return true;
    });
});

it('carries cc and reply-to when they are set', function (): void {
    Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);

    Mail::raw('body', function ($m): void {
        $m->to('one@example.com')
            ->cc('two@example.com')
            ->replyTo('admin@example.com', 'Ops')
            ->subject('s');
    });

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        expect($body['cc'])->toBe([['email' => 'two@example.com']])
            ->and($body['replyTo'])->toBe(['email' => 'admin@example.com', 'name' => 'Ops']);

        return true;
    });
});

it('raises a transport exception when Brevo rejects the message', function (): void {
    // The two rejections that matter -- an unverified sender and an exhausted
    // quota -- both arrive this way, and both need the reason to survive.
    Http::fake([
        'api.brevo.com/*' => Http::response(
            ['code' => 'invalid_parameter', 'message' => 'Sender is not valid'],
            400,
        ),
    ]);

    expect(fn () => Mail::raw('body', fn ($m) => $m->to('t@example.com')->subject('s')))
        ->toThrow(TransportException::class, 'Sender is not valid');
});

it('raises a transport exception when the API cannot be reached', function (): void {
    // Rethrown rather than allowed to escape as a Guzzle error, so the queue
    // treats it as a retryable failure -- which a network blip is.
    Http::fake(fn () => throw new RuntimeException('Connection timed out'));

    expect(fn () => Mail::raw('body', fn ($m) => $m->to('t@example.com')->subject('s')))
        ->toThrow(TransportException::class, 'Could not reach the Brevo API');
});

it('refuses to build the mailer without a key', function (): void {
    config(['services.brevo.key' => null, 'mail.mailers.brevo.key' => null]);

    expect(fn () => Mail::mailer('brevo'))
        ->toThrow(InvalidArgumentException::class, 'BREVO_KEY is not set');
});
