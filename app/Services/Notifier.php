<?php

namespace App\Services;

use App\Mail\Contracts\InAppNotice;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a user-facing message: the email (queued) and the matching entry in
 * the user's notification centre. The entry is written in the caller's
 * transaction, so it exists exactly when the event does; emails that must
 * wait for the commit say so with afterCommit().
 */
class Notifier
{
    /** @param  bool  $email  false when the user turned this kind of email off; the in-app entry is still written */
    public function send(User $user, Mailable $mail, bool $email = true): void
    {
        if ($email) {
            Mail::to($user)->queue($mail);
        }
        if ($mail instanceof InAppNotice) {
            $this->record($user, $mail->inAppKind(), (string) $mail->envelope()->subject, $mail->inAppUrl());
        }
    }

    public function record(User $user, string $kind, string $title, ?string $url = null, ?string $body = null): UserNotification
    {
        return UserNotification::create([
            'user_id' => $user->id,
            'kind' => mb_substr($kind, 0, 32),
            'title' => mb_substr($title, 0, 255),
            'body' => $body !== null ? mb_substr($body, 0, 2000) : null,
            'url' => $url !== null ? mb_substr(self::localPath($url), 0, 500) : null,
        ]);
    }

    /** Entries link inside the shop only: absolute URLs are reduced to their path and query. */
    public static function localPath(string $url): string
    {
        $parts = parse_url($url);
        $path = '/'.ltrim(str_replace('\\', '/', (string) ($parts['path'] ?? '/')), '/');

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count();
    }
}
