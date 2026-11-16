<?php

namespace Database\Seeders;

use App\Models\EventCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The seven system event categories from the Phase 1 specification.
 *
 * System categories have no organization and are visible to every
 * organization. The seeder is idempotent: running it twice does not
 * duplicate categories.
 */
class EventCategorySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * System categories: slug, label, description.
     *
     * @return array<int, array{string, string, string}>
     */
    public static function categories(): array
    {
        return [
            ['detention', 'Detention', 'Time spent waiting at pickup or delivery beyond the agreed free time.'],
            ['layover', 'Layover', 'An overnight or extended delay between legs of a shipment.'],
            ['tonu', 'Truck Ordered Not Used (TONU)', 'A truck was ordered and dispatched, but the load was cancelled.'],
            ['lumper', 'Lumper', 'Third-party loading or unloading labor paid for at a facility.'],
            ['additional_stop', 'Additional Stop', 'An extra pickup or delivery stop not in the original agreement.'],
            ['additional_miles', 'Additional Miles', 'Miles driven beyond the agreed route.'],
            ['other', 'Other', 'Any other shipment event worth recording.'],
        ];
    }

    public function run(): void
    {
        foreach (self::categories() as [$slug, $label, $description]) {
            EventCategory::firstOrCreate(
                ['slug' => $slug, 'organization_id' => null],
                ['label' => $label, 'description' => $description]
            );
        }
    }
}
