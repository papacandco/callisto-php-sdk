<?php

declare(strict_types=1);

namespace Callisto\Sdk\Enum;

enum WhatsAppMediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';
    case Audio = 'audio';
}
