<?php

namespace App\Enums;

enum ItemCondition: string
{
    case New = 'new';
    case LikeNew = 'like_new';
    case Used = 'used';
    case Refurbished = 'refurbished';
    case ForParts = 'for_parts';
}
