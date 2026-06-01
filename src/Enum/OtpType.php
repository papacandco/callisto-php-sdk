<?php

declare(strict_types=1);

namespace Callisto\Sdk\Enum;

enum OtpType: string
{
    case Digit = 'digit';
    case Alpha = 'alpha';
    case Alphanumeric = 'alphanumeric';
}
