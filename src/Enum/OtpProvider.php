<?php

declare(strict_types=1);

namespace Callisto\Sdk\Enum;

enum OtpProvider: string
{
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
}
