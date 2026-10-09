<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Writes structured, append-only entries to the audit_log table.
 */
class AuditLogger
{
    public function __construct(
        private readonly RequestId $requestId,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(string $action, ?Model $target = null, array $metadata = [], ?User $actor = null): AuditLog
    {
        $actor ??= Auth::user();
        // Console commands (scheduler, queue workers) have no client IP.
        $ip = app()->runningInConsole() && ! app()->runningUnitTests() ? null : app(Request::class)->ip();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target ? (string) $target->getKey() : null,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'ip' => $ip,
            'request_id' => $this->requestId->get(),
        ]);
    }
}
