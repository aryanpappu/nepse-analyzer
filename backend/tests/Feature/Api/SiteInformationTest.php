<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\FeatureTestCase;

class SiteInformationTest extends FeatureTestCase
{
    public function test_only_public_operator_details_are_exposed(): void
    {
        config([
            'public_site.operator_name' => 'Example Operator',
            'public_site.contact_email' => 'support@example.com',
            'public_site.privacy_url' => 'https://example.com/privacy',
            'public_site.terms_url' => 'https://example.com/terms',
            'mail.mailers.smtp.password' => 'not-public',
        ]);

        $this->getJson('/api/site-information')->assertOk()
            ->assertJsonPath('data.operator_name', 'Example Operator')
            ->assertJsonPath('data.contact_email', 'support@example.com')
            ->assertJsonPath('data.privacy_url', 'https://example.com/privacy')
            ->assertJsonCount(5, 'data')
            ->assertDontSee('not-public');
    }

    public function test_invalid_contact_and_unsafe_policy_links_are_not_published(): void
    {
        config([
            'public_site.contact_email' => 'not-an-email',
            'public_site.privacy_url' => 'javascript:alert(1)',
            'public_site.terms_url' => 'http://example.com/terms',
        ]);

        $this->getJson('/api/site-information')->assertOk()
            ->assertJsonPath('data.contact_email', null)
            ->assertJsonPath('data.privacy_url', null)
            ->assertJsonPath('data.terms_url', null);
    }
}
