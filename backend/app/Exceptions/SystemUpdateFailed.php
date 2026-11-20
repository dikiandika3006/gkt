<?php

namespace App\Exceptions;

use RuntimeException;

/** Update database gagal di tengah jalan; membawa nama backup & output migrasi untuk ditampilkan ke admin. */
class SystemUpdateFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $backup, public readonly string $output)
    {
        parent::__construct($message);
    }
}
