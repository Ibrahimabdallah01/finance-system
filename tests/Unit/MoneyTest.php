<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;

class MoneyTest extends TestCase
{
    public function test_addition_is_exact_where_floats_are_not(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('1250.50', Money::add(1000, '250.50'));
    }

    public function test_subtraction_can_go_negative(): void
    {
        $this->assertSame('-0.01', Money::sub('100.00', '100.01'));
    }

    public function test_sum_of_a_list_and_of_nothing(): void
    {
        $this->assertSame('60.60', Money::sum(['10.10', '20.20', '30.30']));
        $this->assertSame('0.00', Money::sum([]));
    }

    public function test_multiplication_rounds_half_away_from_zero(): void
    {
        $this->assertSame('27000.00', Money::mul('150000', '0.18'));
        $this->assertSame('300.00', Money::mul('100.00', 3));
        $this->assertSame('3.33', Money::mul('10.00', '0.333'));
        $this->assertSame('0.01', Money::mul('0.01', '0.5'));
        $this->assertSame('-0.01', Money::mul('-0.01', '0.5'));
        $this->assertSame('1.25', Money::mul('2.50', '0.5'));
    }

    public function test_compare(): void
    {
        $this->assertSame(1, Money::compare('10.00', '9.99'));
        $this->assertSame(0, Money::compare('5', '5.00'));
        $this->assertSame(-1, Money::compare('1.00', '1.01'));
    }

    public function test_format(): void
    {
        $this->assertSame('TSh 150,000', Money::format(150000));
        $this->assertSame('TSh 1,234,567.50', Money::format('1234567.5'));
        $this->assertSame('-TSh 5,000', Money::format('-5000'));
        $this->assertSame('TSh 999', Money::format('999'));
        $this->assertSame('TSh 0', Money::format('0'));
        $this->assertSame('150,000', Money::format(150000, false));
    }

    public function test_more_than_two_decimals_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::add('1.005', '1');
    }

    public function test_non_numeric_text_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::normalize('abc');
    }

    public function test_floats_are_rejected(): void
    {
        $this->expectException(TypeError::class);
        Money::add(1.5, '1');
    }
}
