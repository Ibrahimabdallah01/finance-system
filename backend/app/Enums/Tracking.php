<?php

namespace App\Enums;

enum Tracking: string
{
    case Serialized = 'serialized'; // every unit tracked by IMEI / serial
    case Quantity = 'quantity';     // counted stock, weighted-average cost
    case Direct = 'direct';         // sold directly, no stock kept
}
