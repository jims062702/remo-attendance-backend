<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Sends through Brevo's transactional API over HTTPS.
 *
 * SMTP is not an option on this host. Render blocks outbound connections on
 * the mail ports -- 25, 465 and 587 all time out rather than refuse, which is
 * what made the Gmail attempt look like a credentials problem for so long. An
 * HTTP API talks to port 443 like everything else, so it is never blocked.
 *
 * Written by hand because Laravel ships first-party transports for Resend,
 * Postmark and SES but not for Brevo. Brevo earns the extra ~80 lines by being
 * the only free tier that verifies a single sender ADDRESS rather than a whole
 * domain: this deployment has no domain of its own -- the frontend lives on a
 * vercel.app subdomain -- so domain verification is not available to it.
 *
 * Extends AbstractTransport rather than Symfony's AbstractApiTransport, which
 * would drag in symfony/http-client for one request. Guzzle is already here.
 */
class BrevoTransport extends AbstractTransport
{
    private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(private readonly string $key)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        $sender = $email->getFrom()[0] ?? $envelope->getSender();

        $payload = array_filter([
            'sender' => $this->address($sender),
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'replyTo' => ($replyTo = $email->getReplyTo()[0] ?? null)
                ? $this->address($replyTo)
                : null,
            'subject' => $email->getSubject(),
            'htmlContent' => $email->getHtmlBody(),
            'textContent' => $email->getTextBody(),
            'attachment' => $this->attachments($email),
        ], fn ($value) => $value !== null && $value !== []);

        try {
            $response = Http::withHeaders([
                'api-key' => $this->key,
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ])
                // Generous, because a queue worker waiting is nobody waiting --
                // but bounded, because a worker blocked forever on one message
                // stops delivering every message behind it.
                ->timeout(30)
                ->post(self::ENDPOINT, $payload);
        } catch (Throwable $e) {
            // Network-level: DNS, TLS, timeout. Rethrown as a TransportException
            // so the queue treats it as a retryable failure rather than a fatal
            // one, which is what it usually is.
            throw new TransportException(
                'Could not reach the Brevo API: '.$e->getMessage(), 0, $e,
            );
        }

        if ($response->failed()) {
            // Brevo answers a rejection with a code and a message; both are
            // worth surfacing, because "sender not verified" and "quota
            // exceeded" need completely different responses from a human.
            throw new TransportException(sprintf(
                'Brevo rejected the message (HTTP %d): %s',
                $response->status(),
                $response->json('message') ?? $response->body(),
            ));
        }

        if ($id = $response->json('messageId')) {
            $message->setMessageId((string) $id);
        }
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array<string, string>>
     */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $address): array => $this->address($address), $addresses);
    }

    /**
     * @return array<string, string>
     */
    private function address(Address $address): array
    {
        return array_filter([
            'email' => $address->getAddress(),
            'name' => $address->getName(),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * Nothing in this application attaches anything today. Handled anyway: a
     * transport that silently drops attachments is a trap for whoever adds the
     * first one.
     *
     * @return array<int, array<string, string>>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();

            $attachments[] = [
                'content' => base64_encode($attachment->getBody()),
                'name' => $headers->getHeaderParameter('content-disposition', 'filename')
                    ?? 'attachment',
            ];
        }

        return $attachments;
    }

    public function __toString(): string
    {
        return 'brevo+api://api.brevo.com';
    }
}
