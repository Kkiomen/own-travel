<?php

declare(strict_types=1);

namespace App\Domain\Deal;

enum DealType: string
{
    /** A single leg. Cheap, but useless on its own if the way back is not. */
    case Flight = 'flight';

    /** Both legs, priced together, with a stay short enough to take as leave. */
    case RoundTrip = 'round_trip';

    /** A packaged trip announced on a blog. */
    case Trip = 'trip';
}
