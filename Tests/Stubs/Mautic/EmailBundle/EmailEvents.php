<?php

declare(strict_types=1);

namespace Mautic\EmailBundle;

final class EmailEvents
{
    public const EMAIL_ON_SEND = 'mautic.email.on_send';

    public const EMAIL_ON_OPEN = 'mautic.email.on_open';

    public const EMAIL_ON_REPLY = 'mautic.email_on_reply';

    public const EMAIL_FAILED = 'mautic.on_email_failed';

    public const EMAIL_POST_SAVE = 'mautic.email_post_save';

    public const EMAIL_POST_DELETE = 'mautic.email_post_delete';
}
