<?php

namespace App\Mail\Concerns;

use App\Support\EmailTemplates;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Lets staff override a mail's subject and plain-text body on
 * /admin/email-templates. Without an override the translated built-in
 * view is used. templateData() supplies the placeholder values.
 */
trait HasEditableTemplate
{
    /**
     * Queued mails survive a mail server hiccup or a worker restart: they are
     * retried with backoff instead of failing on the first error.
     */
    public int $tries = 5;

    /** @return list<int> seconds before each retry */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    abstract public static function templateKey(): string;

    /** @return array<string, string> placeholder name (without braces) => value */
    abstract public function templateData(): array;

    protected function templatedEnvelope(string $defaultSubject): Envelope
    {
        $template = EmailTemplates::override(static::templateKey());

        return new Envelope(subject: $template === null ? $defaultSubject : EmailTemplates::render($template['subject'], $this->allTemplateData()));
    }

    protected function templatedContent(string $defaultView): Content
    {
        $template = EmailTemplates::override(static::templateKey());

        return $template === null
            ? new Content(text: $defaultView)
            : new Content(text: 'mail.custom', with: ['customBody' => EmailTemplates::render($template['body'], $this->allTemplateData())]);
    }

    /** @return array<string, string> */
    public function allTemplateData(): array
    {
        return EmailTemplates::globals() + $this->templateData();
    }
}
