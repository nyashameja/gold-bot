<?php

declare(strict_types=1);

namespace GoldBot\Domain\Market\Enums;

enum StructureType: string
{
    case SwingHigh = 'SWING_HIGH';
    case SwingLow  = 'SWING_LOW';
    /** Break of structure: trend continuation. */
    case Bos       = 'BOS';
    /** Change of character: the first sign a trend may be reversing. */
    case Choch     = 'CHOCH';
}
