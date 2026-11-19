<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\DeadlineSetting;
use App\Models\EvidenceTemplate;
use App\Models\ExportTemplate;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomizationTest extends TestCase
{
    use RefreshDatabase;

    // Custom fields

    public function test_a_custom_field_can_be_created_listed_shown_updated_and_deleted(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $field = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'shipment',
                'label' => 'Priority Level',
                'field_type' => 'select',
                'options' => ['low', 'high'],
                'is_required' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.field_key', 'priority_level')
            ->assertJsonPath('data.is_active', true)
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/custom-fields?resource_type=shipment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $field['id']);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/custom-fields/'.$field['id'], [
                'label' => 'Priority',
                'field_key' => 'renamed_key',
                'sort_order' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Priority')
            ->assertJsonPath('data.field_key', 'priority_level')
            ->assertJsonPath('data.sort_order', 3);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/custom-fields/'.$field['id'])
            ->assertOk();

        $this->assertDatabaseMissing('custom_fields', ['id' => $field['id']]);
    }

    public function test_custom_field_creation_validates_key_options_and_duplicates(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        // Select without options is rejected.
        $this->withHeaders($headers)
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'shipment',
                'label' => 'Priority',
                'field_type' => 'select',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options');

        // One option is not enough.
        $this->withHeaders($headers)
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'shipment',
                'label' => 'Priority',
                'field_type' => 'select',
                'options' => ['only-one'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options');

        // A bad explicit key is rejected.
        $this->withHeaders($headers)
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'shipment',
                'field_key' => 'Bad Key!',
                'label' => 'Priority',
                'field_type' => 'text',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('field_key');

        $this->withHeaders($headers)
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'shipment',
                'label' => 'Priority',
                'field_type' => 'text',
            ])
            ->assertCreated();

        // Same key for the same resource is a duplicate.
        $this->withHeaders($headers)
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'shipment',
                'label' => 'Priority',
                'field_type' => 'text',
            ])
            ->assertUnprocessable();

        // The same key for the other resource is fine.
        $this->withHeaders($headers)
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'claim',
                'label' => 'Priority',
                'field_type' => 'text',
            ])
            ->assertCreated();
    }

    public function test_at_most_twenty_active_custom_fields_per_resource(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        for ($i = 0; $i < 20; $i++) {
            $organization->customFields()->create([
                'resource_type' => 'shipment',
                'field_key' => 'field_'.$i,
                'label' => 'Field '.$i,
                'field_type' => 'text',
            ]);
        }

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'shipment',
                'label' => 'Field 21',
                'field_type' => 'text',
            ])
            ->assertUnprocessable();

        // An inactive definition does not count against the limit.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'shipment',
                'label' => 'Field 21',
                'field_type' => 'text',
                'is_active' => false,
            ])
            ->assertCreated();
    }

    public function test_custom_field_values_round_trip_on_shipments(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        $this->createField($organization, 'shipment', 'Priority', 'select', ['low', 'high']);
        $this->createField($organization, 'shipment', 'Gate Number', 'text');
        $this->createField($organization, 'shipment', 'Checked In', 'boolean');
        $this->createField($organization, 'shipment', 'Delivery Date', 'date');
        $this->createField($organization, 'shipment', 'Pallet Count', 'number');

        $shipment = $this->withHeaders($headers)
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-CUSTOM-1',
                'broker_or_customer_name' => 'Test Broker Co.',
                'custom_field_values' => [
                    'priority' => 'high',
                    'gate_number' => 'G12',
                    'checked_in' => 'true',
                    'delivery_date' => '2026-11-19',
                    'pallet_count' => 14,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.custom_field_values.priority', 'high')
            ->assertJsonPath('data.custom_field_values.checked_in', true)
            ->assertJsonPath('data.custom_field_values.pallet_count', 14)
            ->json('data');

        // A partial update merges over the stored values.
        $this->withHeaders($headers)
            ->putJson('/api/v1/shipments/'.$shipment['id'], [
                'custom_field_values' => ['gate_number' => 'G13'],
            ])
            ->assertOk()
            ->assertJsonPath('data.custom_field_values.gate_number', 'G13')
            ->assertJsonPath('data.custom_field_values.priority', 'high');
    }

    public function test_custom_field_value_validation_errors(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        $this->createField($organization, 'shipment', 'Priority', 'select', ['low', 'high']);
        $this->createField($organization, 'shipment', 'Pallet Count', 'number');
        $this->createField($organization, 'shipment', 'Delivery Date', 'date');
        $this->createField($organization, 'shipment', 'Reference Code', 'text', null, true);

        // Unknown key.
        $this->withHeaders($headers)
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-CUSTOM-2',
                'broker_or_customer_name' => 'Test Broker Co.',
                'custom_field_values' => ['nope' => 'x'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_field_values.nope');

        // Bad select option.
        $this->withHeaders($headers)
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-CUSTOM-3',
                'broker_or_customer_name' => 'Test Broker Co.',
                'custom_field_values' => ['priority' => 'urgent'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_field_values.priority');

        // Non-numeric number.
        $this->withHeaders($headers)
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-CUSTOM-4',
                'broker_or_customer_name' => 'Test Broker Co.',
                'custom_field_values' => ['pallet_count' => 'many'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_field_values.pallet_count');

        // Invalid date.
        $this->withHeaders($headers)
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-CUSTOM-5',
                'broker_or_customer_name' => 'Test Broker Co.',
                'custom_field_values' => ['delivery_date' => '2026-13-01'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_field_values.delivery_date');

        // Missing required field.
        $this->withHeaders($headers)
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-CUSTOM-6',
                'broker_or_customer_name' => 'Test Broker Co.',
                'custom_field_values' => ['priority' => 'low'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_field_values.reference_code');
    }

    public function test_custom_field_values_round_trip_on_claims(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization);

        $this->createField($organization, 'claim', 'Broker Contact', 'text');

        $claim = $this->withHeaders($headers)
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 100.00,
                'custom_field_values' => ['broker_contact' => 'Ada'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.custom_field_values.broker_contact', 'Ada')
            ->json('data');

        $this->withHeaders($headers)
            ->putJson('/api/v1/claims/'.$claim['id'], [
                'custom_field_values' => ['broker_contact' => 'Grace'],
            ])
            ->assertOk()
            ->assertJsonPath('data.custom_field_values.broker_contact', 'Grace');
    }

    public function test_deleting_a_custom_field_strips_its_values(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        $field = $this->createField($organization, 'shipment', 'Priority', 'text');
        $shipment = $this->createShipment($organization, [
            'custom_field_values' => ['priority' => 'high'],
        ]);

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/custom-fields/'.$field->getKey())
            ->assertOk();

        $this->assertSame([], $shipment->fresh()->custom_field_values ?? []);
    }

    public function test_custom_fields_are_tenant_isolated(): void
    {
        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $field = $this->createField($organizationA, 'shipment', 'Priority', 'text');

        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organizationB))
            ->getJson('/api/v1/custom-fields/'.$field->getKey())
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($organizationB))
            ->putJson('/api/v1/custom-fields/'.$field->getKey(), ['label' => 'Other'])
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($organizationB))
            ->deleteJson('/api/v1/custom-fields/'.$field->getKey())
            ->assertNotFound();
    }

    public function test_custom_field_list_requires_a_resource_type(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/custom-fields')
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/custom-fields?resource_type=wrong')
            ->assertUnprocessable();
    }

    // Event categories

    public function test_a_disabled_category_rejects_new_events_but_old_events_stay_readable(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization);

        $category = $this->withHeaders($headers)
            ->postJson('/api/v1/event-categories', ['label' => 'Extra Stop'])
            ->assertCreated()
            ->json('data');

        $event = $this->withHeaders($headers)
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $category['id'],
                'event_date' => '2026-11-12',
            ])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($headers)
            ->putJson('/api/v1/event-categories/'.$category['id'], ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withHeaders($headers)
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $category['id'],
                'event_date' => '2026-11-13',
            ])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->getJson('/api/v1/events/'.$event['id'])
            ->assertOk()
            ->assertJsonPath('data.id', $event['id']);
    }

    public function test_a_category_created_with_defaults_prefills_new_events(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization);

        // Defaults supplied at creation must stick (they used to be
        // silently dropped by the create endpoint; only update accepted
        // them). Found by the parent run's live smoke test, 2026-11-19.
        $category = $this->withHeaders($headers)
            ->postJson('/api/v1/event-categories', [
                'label' => 'Weigh Station Delay',
                'default_estimated_amount' => 150.00,
                'default_currency' => 'USD',
            ])
            ->assertCreated()
            ->assertJsonPath('data.default_estimated_amount', '150.00')
            ->assertJsonPath('data.default_currency', 'USD')
            ->json('data');

        $this->withHeaders($headers)
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $category['id'],
                'event_date' => '2026-11-19',
            ])
            ->assertCreated()
            ->assertJsonPath('data.estimated_amount', '150.00')
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_a_category_default_amount_prefills_new_events(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization);

        $category = $this->withHeaders($headers)
            ->postJson('/api/v1/event-categories', ['label' => 'Lumper Help'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($headers)
            ->putJson('/api/v1/event-categories/'.$category['id'], [
                'default_estimated_amount' => 150.00,
                'default_currency' => 'USD',
            ])
            ->assertOk()
            ->assertJsonPath('data.default_estimated_amount', '150.00');

        $this->withHeaders($headers)
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $category['id'],
                'event_date' => '2026-11-12',
            ])
            ->assertCreated()
            ->assertJsonPath('data.estimated_amount', '150.00')
            ->assertJsonPath('data.currency', 'USD');

        // An explicit amount is never overwritten by the default.
        $this->withHeaders($headers)
            ->postJson('/api/v1/shipments/'.$shipment->getKey().'/events', [
                'event_category_id' => $category['id'],
                'event_date' => '2026-11-12',
                'estimated_amount' => 90.00,
                'currency' => 'EUR',
            ])
            ->assertCreated()
            ->assertJsonPath('data.estimated_amount', '90.00')
            ->assertJsonPath('data.currency', 'EUR');
    }

    public function test_renaming_a_category_recomputes_the_slug_and_collisions_fail(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/event-categories', ['label' => 'Extra Stop'])
            ->assertCreated()
            ->json('data');

        $second = $this->withHeaders($headers)
            ->postJson('/api/v1/event-categories', ['label' => 'Extra Miles'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($headers)
            ->putJson('/api/v1/event-categories/'.$second['id'], ['label' => 'Extra Stop'])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->putJson('/api/v1/event-categories/'.$second['id'], ['label' => 'Extra Distance'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'extra-distance');

        // Renaming onto a system slug also collides.
        $this->systemCategory('detention');

        $this->withHeaders($headers)
            ->putJson('/api/v1/event-categories/'.$first['id'], ['label' => 'Detention'])
            ->assertUnprocessable();
    }

    public function test_system_categories_cannot_be_updated_or_deleted(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $category = $this->systemCategory('detention');

        $this->withHeaders($headers)
            ->putJson('/api/v1/event-categories/'.$category->getKey(), ['label' => 'Changed'])
            ->assertNotFound();

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/event-categories/'.$category->getKey())
            ->assertNotFound();
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization);

        $used = $this->withHeaders($headers)
            ->postJson('/api/v1/event-categories', ['label' => 'Used Category'])
            ->assertCreated()
            ->json('data');

        $this->createEvent($shipment, ['event_category_id' => $used['id']]);

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/event-categories/'.$used['id'])
            ->assertUnprocessable();

        $unused = $this->withHeaders($headers)
            ->postJson('/api/v1/event-categories', ['label' => 'Unused Category'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/event-categories/'.$unused['id'])
            ->assertOk();
    }

    // Evidence templates

    public function test_evidence_templates_crud_and_apply_is_idempotent(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $template = $this->withHeaders($headers)
            ->postJson('/api/v1/evidence-templates', [
                'name' => 'Detention pack',
                'description' => 'Standard detention evidence.',
                'items' => [
                    ['document_category' => 'bill_of_lading', 'label' => 'Bill of lading'],
                    ['document_category' => 'proof_of_delivery', 'label' => 'Proof of delivery', 'is_required' => false],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Detention pack')
            ->assertJsonCount(2, 'data.items')
            ->json('data');

        // A duplicate name is rejected.
        $this->withHeaders($headers)
            ->postJson('/api/v1/evidence-templates', [
                'name' => 'Detention pack',
                'items' => [
                    ['document_category' => 'receipt', 'label' => 'Receipt'],
                ],
            ])
            ->assertUnprocessable();

        // An invalid document category is rejected.
        $this->withHeaders($headers)
            ->postJson('/api/v1/evidence-templates', [
                'name' => 'Bad pack',
                'items' => [
                    ['document_category' => 'not_a_category', 'label' => 'Bad'],
                ],
            ])
            ->assertUnprocessable();

        $applied = $this->withHeaders($headers)
            ->postJson('/api/v1/evidence-templates/'.$template['id'].'/apply-to-claim/'.$claim->getKey())
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $applied['created']);
        $this->assertSame(0, $applied['skipped']);
        $this->assertSame($template['id'], $applied['template_id']);
        $this->assertSame((string) $claim->getKey(), $applied['claim_id']);
        $this->assertDatabaseCount('claim_requirements', 2);

        // Applying the same template again creates nothing.
        $appliedAgain = $this->withHeaders($headers)
            ->postJson('/api/v1/evidence-templates/'.$template['id'].'/apply-to-claim/'.$claim->getKey())
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $appliedAgain['created']);
        $this->assertSame(2, $appliedAgain['skipped']);
        $this->assertDatabaseCount('claim_requirements', 2);

        // Updating with items replaces them wholesale.
        $this->withHeaders($headers)
            ->putJson('/api/v1/evidence-templates/'.$template['id'], [
                'items' => [
                    ['document_category' => 'receipt', 'label' => 'Receipt'],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.document_category', 'receipt');

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/evidence-templates/'.$template['id'])
            ->assertOk();

        $this->assertDatabaseMissing('evidence_templates', ['id' => $template['id']]);
        $this->assertDatabaseMissing('evidence_template_items', ['evidence_template_id' => $template['id']]);
    }

    public function test_evidence_template_apply_is_tenant_isolated(): void
    {
        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $template = EvidenceTemplate::create([
            'organization_id' => $organizationA->getKey(),
            'name' => 'Pack',
        ]);
        $template->items()->create([
            'document_category' => 'receipt',
            'label' => 'Receipt',
        ]);
        $shipmentA = $this->createShipment($organizationA);
        $claimA = $this->createClaim($organizationA, $shipmentA);

        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipmentB = $this->createShipment($organizationB);
        $claimB = $this->createClaim($organizationB, $shipmentB);

        // Org B cannot apply org A's template to its own claim.
        $this->withHeaders($this->orgHeaders($organizationB))
            ->postJson('/api/v1/evidence-templates/'.$template->getKey().'/apply-to-claim/'.$claimB->getKey())
            ->assertNotFound();

        // Org B cannot apply its way into org A's claim either.
        $this->withHeaders($this->orgHeaders($organizationB))
            ->postJson('/api/v1/evidence-templates/'.$template->getKey().'/apply-to-claim/'.$claimA->getKey())
            ->assertNotFound();
    }

    // Deadline settings

    public function test_deadline_settings_crud_and_duplicate_scopes(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        $global = $this->withHeaders($headers)
            ->postJson('/api/v1/deadline-settings', [
                'scope_type' => 'global',
                'submit_within_days' => 30,
                'reminder_days_before' => [7, 3],
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope_type', 'global')
            ->assertJsonPath('data.scope_value', null)
            ->json('data');

        // A second global setting is a duplicate.
        $this->withHeaders($headers)
            ->postJson('/api/v1/deadline-settings', [
                'scope_type' => 'global',
                'submit_within_days' => 10,
            ])
            ->assertUnprocessable();

        // A non-global scope needs a value.
        $this->withHeaders($headers)
            ->postJson('/api/v1/deadline-settings', [
                'scope_type' => 'claim_category',
                'submit_within_days' => 10,
            ])
            ->assertUnprocessable();

        // Days outside 1-365 are rejected.
        $this->withHeaders($headers)
            ->postJson('/api/v1/deadline-settings', [
                'scope_type' => 'claim_category',
                'scope_value' => 'detention',
                'submit_within_days' => 400,
            ])
            ->assertUnprocessable();

        $category = $this->withHeaders($headers)
            ->postJson('/api/v1/deadline-settings', [
                'scope_type' => 'claim_category',
                'scope_value' => 'detention',
                'submit_within_days' => 10,
            ])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($headers)
            ->postJson('/api/v1/deadline-settings', [
                'scope_type' => 'claim_category',
                'scope_value' => 'detention',
                'submit_within_days' => 12,
            ])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->putJson('/api/v1/deadline-settings/'.$category['id'], [
                'submit_within_days' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('data.submit_within_days', 12);

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/deadline-settings/'.$global['id'])
            ->assertOk();

        $this->assertDatabaseMissing('deadline_settings', ['id' => $global['id']]);
    }

    public function test_deadline_resolve_precedence_is_claim_then_event_then_global(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        $this->createDeadlineSetting($organization, 'global', null, 30);
        $this->createDeadlineSetting($organization, 'event_category', 'detention', 5);
        $this->createDeadlineSetting($organization, 'claim_category', 'detention', 10);

        // Claim category wins over event category.
        $this->withHeaders($headers)
            ->getJson('/api/v1/deadline-settings/resolve?claim_category=detention&event_category=detention')
            ->assertOk()
            ->assertJsonPath('data.submit_within_days', 10);

        // Event category wins over global.
        $this->withHeaders($headers)
            ->getJson('/api/v1/deadline-settings/resolve?claim_category=layover&event_category=detention')
            ->assertOk()
            ->assertJsonPath('data.submit_within_days', 5);

        // Global is the fallback.
        $this->withHeaders($headers)
            ->getJson('/api/v1/deadline-settings/resolve?claim_category=layover&event_category=layover')
            ->assertOk()
            ->assertJsonPath('data.submit_within_days', 30);

        // No parameters still resolves the global setting.
        $this->withHeaders($headers)
            ->getJson('/api/v1/deadline-settings/resolve')
            ->assertOk()
            ->assertJsonPath('data.submit_within_days', 30);
    }

    public function test_deadline_resolve_returns_null_when_nothing_matches(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/deadline-settings/resolve?claim_category=detention')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_claim_creation_applies_the_deadline_default_when_no_deadline_given(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization);

        $this->createDeadlineSetting($organization, 'global', null, 14, [7, 3]);

        $claim = $this->withHeaders($headers)
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($claim['submission_deadline_at']);
        $this->assertSame(
            now()->addDays(14)->toDateString(),
            \Carbon\Carbon::parse($claim['submission_deadline_at'])->toDateString()
        );

        $this->assertDatabaseHas('claim_deadlines', [
            'claim_id' => $claim['id'],
            'timezone' => 'UTC',
            'source_reference' => 'Organization deadline default',
        ]);
    }

    public function test_an_explicit_claim_deadline_wins_over_the_default(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization);

        $this->createDeadlineSetting($organization, 'global', null, 14);

        $claim = $this->withHeaders($headers)
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
                'submission_deadline_at' => '2026-12-01 12:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.submission_deadline_at', '2026-12-01T12:00:00.000000Z')
            ->json('data');

        $this->assertDatabaseMissing('claim_deadlines', ['claim_id' => $claim['id']]);
    }

    // Export templates

    public function test_export_templates_crud_and_a_single_default(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/export-templates', [
                'resource_type' => 'shipment',
                'name' => 'Minimal',
                'columns' => ['internal_reference', 'status'],
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->json('data');

        // An unknown column is rejected.
        $this->withHeaders($headers)
            ->postJson('/api/v1/export-templates', [
                'resource_type' => 'shipment',
                'name' => 'Bad',
                'columns' => ['not_a_column'],
            ])
            ->assertUnprocessable();

        // A duplicate name for the same resource is rejected.
        $this->withHeaders($headers)
            ->postJson('/api/v1/export-templates', [
                'resource_type' => 'shipment',
                'name' => 'Minimal',
                'columns' => ['status'],
            ])
            ->assertUnprocessable();

        $second = $this->withHeaders($headers)
            ->postJson('/api/v1/export-templates', [
                'resource_type' => 'shipment',
                'name' => 'Full',
                'columns' => ['internal_reference', 'status', 'notes'],
                'is_default' => true,
            ])
            ->assertCreated()
            ->json('data');

        // Creating a second default clears the first.
        $this->assertFalse((bool) ExportTemplate::find($first['id'])->is_default);
        $this->assertTrue((bool) ExportTemplate::find($second['id'])->is_default);

        // set-default moves the default back.
        $this->withHeaders($headers)
            ->postJson('/api/v1/export-templates/'.$first['id'].'/set-default')
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertTrue((bool) ExportTemplate::find($first['id'])->is_default);
        $this->assertFalse((bool) ExportTemplate::find($second['id'])->is_default);

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/export-templates/'.$second['id'])
            ->assertOk();
    }

    public function test_available_columns_include_custom_fields(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        $this->createField($organization, 'shipment', 'Priority', 'text');

        $columns = $this->withHeaders($headers)
            ->getJson('/api/v1/export-templates/available-columns?resource_type=shipment')
            ->assertOk()
            ->json('data');

        $keys = array_column($columns, 'key');

        $this->assertContains('internal_reference', $keys);
        $this->assertContains('status', $keys);
        $this->assertContains('custom:priority', $keys);
        $this->assertCount(17, $keys);

        $this->withHeaders($headers)
            ->getJson('/api/v1/export-templates/available-columns')
            ->assertUnprocessable();
    }

    public function test_shipment_export_uses_a_template_and_default_export_is_unchanged(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);

        $this->createField($organization, 'shipment', 'Priority', 'text');
        $shipment = $this->createShipment($organization, [
            'internal_reference' => 'CT-EXP-1',
            'custom_field_values' => ['priority' => 'high'],
        ]);

        $template = $this->withHeaders($headers)
            ->postJson('/api/v1/export-templates', [
                'resource_type' => 'shipment',
                'name' => 'Priority only',
                'columns' => ['internal_reference', 'custom:priority', 'status'],
            ])
            ->assertCreated()
            ->json('data');

        $response = $this->withHeaders($headers)
            ->get('/api/v1/shipments/export?export_template_id='.$template['id']);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('internal_reference,custom:priority,status', $content);
        $this->assertStringContainsString('CT-EXP-1,high,draft', $content);

        // A template from another organization is rejected.
        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organizationB))
            ->get('/api/v1/shipments/export?export_template_id='.$template['id'])
            ->assertUnprocessable();

        // Without a template the default 16 columns are used. The
        // org B check above switched the authenticated user, so
        // authenticate as the org A owner again first.
        Sanctum::actingAs($user);

        $response = $this->withHeaders($headers)
            ->get('/api/v1/shipments/export');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString(
            'internal_reference,external_load_reference,broker_or_customer_name,carrier,driver_name,driver_contact,pickup_location,delivery_location,pickup_appointment,delivery_appointment,actual_arrival_at,actual_departure_at,base_rate,currency,status,notes',
            $content
        );
        $this->assertStringContainsString('CT-EXP-1', $content);
    }

    public function test_claims_export_uses_a_template_and_the_default_columns(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization, [
            'internal_reference' => 'CT-CLAIM-EXP-1',
        ]);
        $claim = $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CLM-2026-0099',
            'requested_amount' => 250.00,
        ]);

        $template = $this->withHeaders($headers)
            ->postJson('/api/v1/export-templates', [
                'resource_type' => 'claim',
                'name' => 'Refs only',
                'columns' => ['claim_reference', 'shipment_reference', 'status'],
            ])
            ->assertCreated()
            ->json('data');

        $response = $this->withHeaders($headers)
            ->get('/api/v1/claims/export?export_template_id='.$template['id']);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('claim_reference,shipment_reference,status', $content);
        $this->assertStringContainsString('CLM-2026-0099,CT-CLAIM-EXP-1,potential', $content);

        // A shipment template cannot drive a claim export.
        $shipmentTemplate = $this->withHeaders($headers)
            ->postJson('/api/v1/export-templates', [
                'resource_type' => 'shipment',
                'name' => 'Ship only',
                'columns' => ['internal_reference'],
            ])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($headers)
            ->get('/api/v1/claims/export?export_template_id='.$shipmentTemplate['id'])
            ->assertUnprocessable();

        // Without a template the default claim columns are used.
        $response = $this->withHeaders($headers)
            ->get('/api/v1/claims/export');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString(
            'claim_reference,shipment_reference,broker_or_customer_name,claim_category,description,requested_amount,accepted_amount,recovered_amount,currency,status,submission_deadline_at,submitted_at,created_at',
            $content
        );
        $this->assertStringContainsString('CLM-2026-0099', $content);
    }

    public function test_claims_export_requires_export_reports(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->get('/api/v1/claims/export')
            ->assertForbidden();
    }

    // Organization settings

    public function test_organization_settings_validate_keys_and_merge(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        // Unknown settings keys are rejected.
        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'settings' => ['surprise_key' => 'x'],
        ])->assertUnprocessable();

        // Bad values are rejected.
        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'settings' => ['default_currency' => 'USDD'],
        ])->assertUnprocessable();

        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'settings' => ['date_format' => 'Y/m/d'],
        ])->assertUnprocessable();

        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'settings' => ['claim_reference_prefix' => 'bad prefix!'],
        ])->assertUnprocessable();

        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'settings' => ['claim_reference_prefix' => 'TOOLONGPREFIX'],
        ])->assertUnprocessable();

        // Valid settings save, and a later partial update merges.
        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'settings' => [
                'default_currency' => 'EUR',
                'date_format' => 'd/m/Y',
                'claim_reference_prefix' => 'CT',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.default_currency', 'EUR')
            ->assertJsonPath('data.settings.date_format', 'd/m/Y')
            ->assertJsonPath('data.settings.claim_reference_prefix', 'CT');

        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'settings' => ['date_format' => 'm/d/Y'],
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.default_currency', 'EUR')
            ->assertJsonPath('data.settings.date_format', 'm/d/Y')
            ->assertJsonPath('data.settings.claim_reference_prefix', 'CT');
    }

    public function test_claim_reference_prefix_is_used_for_new_claims_only(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $headers = $this->orgHeaders($organization);
        $shipment = $this->createShipment($organization);

        $this->putJson('/api/v1/organizations/'.$organization->getKey(), [
            'settings' => ['claim_reference_prefix' => 'CT'],
        ])->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 100.00,
            ])
            ->assertCreated()
            ->assertJsonPath('data.claim_reference', 'CT-'.now()->format('Y').'-0001');
    }

    public function test_claim_reference_defaults_to_clm_without_a_prefix(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 100.00,
            ])
            ->assertCreated()
            ->assertJsonPath('data.claim_reference', 'CLM-'.now()->format('Y').'-0001');
    }

    // Permissions

    public function test_manage_customization_endpoints_reject_viewers_and_dispatchers(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $headers = $this->orgHeaders($organization);

        $this->withHeaders($headers)
            ->postJson('/api/v1/custom-fields', [
                'resource_type' => 'shipment',
                'label' => 'Priority',
                'field_type' => 'text',
            ])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->postJson('/api/v1/evidence-templates', [
                'name' => 'Pack',
                'items' => [['document_category' => 'receipt', 'label' => 'Receipt']],
            ])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->postJson('/api/v1/deadline-settings', [
                'scope_type' => 'global',
                'submit_within_days' => 30,
            ])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->postJson('/api/v1/export-templates', [
                'resource_type' => 'shipment',
                'name' => 'Pack',
                'columns' => ['status'],
            ])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->getJson('/api/v1/deadline-settings')
            ->assertForbidden();

        [, $dispatcherOrg] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);

        $this->withHeaders($this->orgHeaders($dispatcherOrg))
            ->postJson('/api/v1/evidence-templates', [
                'name' => 'Pack',
                'items' => [['document_category' => 'receipt', 'label' => 'Receipt']],
            ])
            ->assertForbidden();
    }

    public function test_admin_and_operations_manager_can_manage_customization(): void
    {
        foreach ([OrganizationRole::Admin, OrganizationRole::OperationsManager] as $role) {
            [, $organization] = $this->actingAsMemberOf($role);

            $this->withHeaders($this->orgHeaders($organization))
                ->postJson('/api/v1/custom-fields', [
                    'resource_type' => 'shipment',
                    'label' => 'Priority',
                    'field_type' => 'text',
                ])
                ->assertCreated();

            $this->withHeaders($this->orgHeaders($organization))
                ->postJson('/api/v1/deadline-settings', [
                    'scope_type' => 'global',
                    'submit_within_days' => 30,
                ])
                ->assertCreated();
        }
    }

    public function test_viewers_can_read_custom_fields_for_resources_they_can_view(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/custom-fields?resource_type=shipment')
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/custom-fields?resource_type=claim')
            ->assertOk();
    }

    // Helpers

    protected function createField(
        $organization,
        string $resourceType,
        string $label,
        string $type,
        ?array $options = null,
        bool $required = false,
    ): CustomField {
        return $organization->customFields()->create([
            'resource_type' => $resourceType,
            'field_key' => Str::slug($label, '_'),
            'label' => $label,
            'field_type' => $type,
            'is_required' => $required,
            'options' => $options,
        ]);
    }

    protected function createDeadlineSetting(
        $organization,
        string $scopeType,
        ?string $scopeValue,
        int $days,
        ?array $reminders = null,
    ): DeadlineSetting {
        return $organization->deadlineSettings()->create([
            'scope_type' => $scopeType,
            'scope_value' => $scopeValue,
            'submit_within_days' => $days,
            'reminder_days_before' => $reminders,
        ]);
    }
}
