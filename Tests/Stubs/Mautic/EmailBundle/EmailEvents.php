<?php

declare(strict_types=1);

namespace Mautic\EmailBundle;

final class EmailEvents
{
    public const EMAIL_ON_SEND = 'mautic.email.on_send';

    public const EMAIL_ON_OPEN = 'mautic.email.on_open';

    public const EMAIL_ON_DISPLAY = 'mautic.email_on_display';

    public const EMAIL_ON_REPLY = 'mautic.email_on_reply';

    public const EMAIL_FAILED = 'mautic.on_email_failed';

    public const EMAIL_RESEND = 'mautic.on_email_resend';

    public const ON_SENT_EMAIL_TO_USER = 'mautic.email.on_sent_email_to_user';

    public const ON_TRANSPORT_WEBHOOK = 'mautic.email.on_transport_webhook';

    public const EMAIL_POST_SAVE = 'mautic.email_post_save';

    public const EMAIL_POST_DELETE = 'mautic.email_post_delete';
}
