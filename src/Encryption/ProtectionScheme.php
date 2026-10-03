<?php

declare(strict_types=1);

namespace Foxws\Media\Encryption;

/**
 * Common Encryption schemes. The pattern-based schemes (Cens, Cbcs) only apply to video streams.
 */
enum ProtectionScheme: string
{
    case Cenc = 'cenc';
    case Cbc1 = 'cbc1';
    case Cens = 'cens';
    case Cbcs = 'cbcs';
}
