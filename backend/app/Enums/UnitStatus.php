<?php

namespace App\Enums;

enum UnitStatus: string
{
    case InStock = 'in_stock';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case ReturnedToSupplier = 'returned_to_supplier';
    case WrittenOff = 'written_off';
}
