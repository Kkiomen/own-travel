<?php

declare(strict_types=1);

namespace App\Domain\Deal;

enum DealSort: string
{
    case Newest = 'newest';
    case Score = 'score';
    case Price = 'price';
}
