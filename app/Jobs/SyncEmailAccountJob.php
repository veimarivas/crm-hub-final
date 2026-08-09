<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Services\Email\EmailSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sincroniza una casilla.
 *
 * Un fallo queda **escrito en la casilla** (`last_error`) además de reventar el
 * job: una casilla que dejó de sincronizar hace tres días tiene que verse en la
 * pantalla de configuración, no solo en la tabla de jobs fallidos.
 */
class SyncEmailAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public readonly string $mailboxId) {}

    public function handle(EmailSync $sync): void
    {
        $mailbox = EmailAccount::find($this->mailboxId);

        if (! $mailbox || ! $mailbox->is_active) {
            return;
        }

        try {
            $sync->sync($mailbox);
        } catch (\Throwable $e) {
            $mailbox->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            throw $e;
        }
    }
}
