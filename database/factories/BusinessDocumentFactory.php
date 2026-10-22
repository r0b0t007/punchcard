<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentKind;
use App\Models\Business;
use App\Models\BusinessDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A verification document sent by a business, waiting for review; no file
 * is written (SubmitDocument writes one). organization_id is filled from
 * the business. Create inside TenantContext::bypass().
 *
 * @extends Factory<BusinessDocument>
 */
class BusinessDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'kind' => DocumentKind::IdDocument,
            'path' => 'factory/'.Str::random(40).'.enc',
            'key_id' => str_repeat('0', 16),
            'mime' => 'application/pdf',
            'size' => 120_000,
        ];
    }
}
