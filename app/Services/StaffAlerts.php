<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\StaffAlertMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email alerts to the staff address (shop.staff_alert_email). Events that
 * can repeat (rejected callbacks, failing jobs, outages) pass a throttle
 * key, so an attack or an outage sends one mail per period instead of a
 * flood. Routine pending work arrives as a digest (shop:staff-digest). Every
 * alert also lands in the notification centre of super admins and managers.
 */
class StaffAlerts
{
    /**
     * @param  list<string>  $lines
     * @param  bool  $direct  send now instead of queueing; for alerts about the queue itself
     */
    public function send(string $subject, array $lines, ?string $url = null, ?string $throttleKey = null, int $throttleMinutes = 60, bool $direct = false): bool
    {
        $address = (string) config('shop.staff_alert_email');
        if ($address === '') {
            return false;
        }
        if ($throttleKey !== null && ! Cache::add('staff-alert:'.$throttleKey, true, now()->addMinutes($throttleMinutes))) {
            return false;
        }

        $mail = new StaffAlertMail($subject, $lines, $url);
        if ($direct) {
            try {
                Mail::to($address)->sendNow($mail);
            } catch (Throwable $e) {
                Log::critical('Staff alert "{subject}" could not be sent: {reason}', ['subject' => $subject, 'reason' => $e->getMessage()]);

                return false;
            }
        } else {
            Mail::to($address)->queue($mail->afterCommit());
        }
        Log::info('Staff alert: {subject}', ['subject' => $subject]);
        // Also in the notification centre of super admins and managers; best effort,
        // since a health alert may be about the database itself.
        rescue(function () use ($subject, $lines, $url) {
            $notifier = app(Notifier::class);
            User::query()->whereIn('role', [UserRole::Admin->value, UserRole::Manager->value])->where('status', UserStatus::Active->value)
                ->each(fn (User $staff) => $notifier->record($staff, 'staff', $subject, $url, implode("\n", $lines)));
        }, report: false);

        return true;
    }
}
