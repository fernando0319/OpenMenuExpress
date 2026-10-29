<?php

namespace Tests\Unit\Pricing;

use App\Enums\PricingMode;
use App\Services\Pricing\ItemPriceCalculator;
use App\Services\Pricing\SelectedGroup;
use App\Services\Pricing\SelectedOption;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ItemPriceCalculatorTest extends TestCase
{
    private ItemPriceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ItemPriceCalculator;
    }

    public function test_item_without_options_costs_base_times_quantity(): void
    {
        $price = $this->calculator->calculate(2500, 3);

        $this->assertSame(2500, $price->unitTotalCents());
        $this->assertSame(7500, $price->totalCents());
    }

    public function test_sum_mode_adds_every_option_times_its_quantity(): void
    {
        $extras = new SelectedGroup(PricingMode::Sum, [
            new SelectedOption(500, 2), // 2× bacon
            new SelectedOption(300),    // 1× cheddar
        ]);

        $price = $this->calculator->calculate(2000, 2, [$extras]);

        $this->assertSame(1300, $price->optionsPerUnitCents);
        $this->assertSame(6600, $price->totalCents());
    }

    public function test_highest_mode_charges_only_the_most_expensive_option(): void
    {
        $flavors = new SelectedGroup(PricingMode::Highest, [
            new SelectedOption(4500), // calabresa
            new SelectedOption(5900), // camarão
        ]);

        $price = $this->calculator->calculate(0, 1, [$flavors]);

        $this->assertSame(5900, $price->totalCents());
    }

    public function test_average_mode_charges_the_mean_of_the_options(): void
    {
        $flavors = new SelectedGroup(PricingMode::Average, [
            new SelectedOption(4500),
            new SelectedOption(5900),
        ]);

        $price = $this->calculator->calculate(0, 1, [$flavors]);

        $this->assertSame(5200, $price->totalCents());
    }

    public function test_average_mode_is_weighted_by_quantity_and_rounds_half_up(): void
    {
        $flavors = new SelectedGroup(PricingMode::Average, [
            new SelectedOption(4000, 2),
            new SelectedOption(5001),
        ]);

        // (4000 × 2 + 5001) / 3 = 4333.67 → 4334
        $price = $this->calculator->calculate(0, 1, [$flavors]);

        $this->assertSame(4334, $price->totalCents());
    }

    public function test_base_price_is_kept_when_a_group_uses_highest_mode(): void
    {
        $crust = new SelectedGroup(PricingMode::Sum, [new SelectedOption(800)]);
        $flavors = new SelectedGroup(PricingMode::Highest, [
            new SelectedOption(4500),
            new SelectedOption(5900),
        ]);

        $price = $this->calculator->calculate(1000, 1, [$crust, $flavors]);

        $this->assertSame(1000 + 800 + 5900, $price->totalCents());
    }

    public function test_empty_group_adds_nothing(): void
    {
        $price = $this->calculator->calculate(1500, 1, [new SelectedGroup(PricingMode::Average, [])]);

        $this->assertSame(1500, $price->totalCents());
    }

    public function test_quantity_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(1000, 0);
    }

    public function test_option_price_cannot_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SelectedOption(-1);
    }
}
