<?php

declare(strict_types=1);

namespace Kestello\Cms\Events;

/**
 * Someone sent a form on the site and it passed every check. It is plain
 * data, so listeners (webhooks, a CRM, a newsletter) may run in the
 * background with #[Queued].
 *
 * @api
 */
final readonly class FormSubmitted
{
    /**
     * @param int $formId The form.
     * @param ?int $submissionId The stored submission, or null when the form does not keep submissions.
     * @param array<string, mixed> $data Field name => value. Uploaded files are lists of {name, size, type, key}.
     */
    public function __construct(
        public int $formId,
        public ?int $submissionId,
        public array $data,
    ) {}
}
