<?php
declare(strict_types=1);

namespace Onion\Framework\Http\Resources\Processors\Types;

enum ProcessorState
{
    case SIZE;
    case DATA;
    case TERMINATED;
}
