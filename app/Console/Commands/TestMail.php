<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Answers "why is no mail arriving?" in one line of deploy log.
 *
 * The real notifications are queued, which is right for a tasker waiting on a
 * clock-in button and wrong for diagnosis: the failure happens minutes later,
 * in another process, and never reaches the browser. On an instance with no
 * shell that is a fault nobody can see.
 *
 * So this sends SYNCHRONOUSLY, prints the resolved configuration, and reports
 * the exception in full. Whatever is wrong -- credentials, a blocked port, a
 * scheme the transport does not accept -- says so here by name.
 *
 * The password is never printed. Its length is, because "is it even set" and
 * "did the spaces get pasted in" are the two things worth knowing about it.
 */
class TestMail extends Command
{
    protected $signature = 'mail:test {to : Where to send the test message}';

    protected $description = 'Send a test message synchronously and report exactly what happened';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        $password = (string) config('mail.mailers.smtp.password');

        $this->line('');
        $this->info('Mail configuration in effect:');
        $this->table(['Setting', 'Value'], [
            ['mailer', config('mail.default')],
            ['host', config('mail.mailers.smtp.host')],
            ['port', config('mail.mailers.smtp.port')],
            ['scheme', config('mail.mailers.smtp.scheme') ?: '(unset — derived from port)'],
            ['username', config('mail.mailers.smtp.username')],
            ['password', $password === ''
                ? 'EMPTY'
                : strlen($password).' characters'
                    .(str_contains($password, ' ') ? ' — CONTAINS SPACES, Gmail will reject it' : ''),
            ],
            ['from', config('mail.from.address').' ('.config('mail.from.name').')'],
            ['queue', config('queue.default')],
        ]);

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER is "log". Nothing is sent anywhere -- messages are written');
            $this->warn('to the log channel instead. Set MAIL_MAILER=smtp.');
        }

        $this->line('');
        $this->info("Sending synchronously to {$to} ...");

        try {
            Mail::raw(
                "This is a test message from ".config('app.name').".\n\n"
                ."If you are reading it, SMTP is working and the queued shift\n"
                ."notifications will arrive the same way.\n\n"
                .rtrim((string) config('app.frontend_url'), '/'),
                fn ($message) => $message->to($to)->subject(config('app.name').' — mail test'),
            );

            $this->info('SENT. SMTP accepted the message.');
        } catch (Throwable $e) {
            $this->error('FAILED: '.$e::class);
            $this->error($e->getMessage());

            // The cause is usually one level down -- a socket error under a
            // transport exception -- and it is the level that names the real
            // problem.
            if ($e->getPrevious() !== null) {
                $this->error('Caused by: '.$e->getPrevious()::class);
                $this->error($e->getPrevious()->getMessage());
            }

            return self::FAILURE;
        }

        $this->reportQueueDepth();

        return self::SUCCESS;
    }

    /**
     * Pending and failed jobs, which is where the real notifications go.
     *
     * A growing `failed` count with a working test send means the transport is
     * fine and something about the queued messages specifically is not.
     */
    private function reportQueueDepth(): void
    {
        if (config('queue.default') !== 'database') {
            return;
        }

        try {
            $this->line('');
            $this->info(sprintf(
                'Queue: %d pending, %d failed.',
                DB::table('jobs')->count(),
                DB::table('failed_jobs')->count(),
            ));

            $latest = DB::table('failed_jobs')->orderByDesc('id')->first();

            if ($latest !== null) {
                $this->warn('Most recent failure:');
                $this->line(substr((string) $latest->exception, 0, 600));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
