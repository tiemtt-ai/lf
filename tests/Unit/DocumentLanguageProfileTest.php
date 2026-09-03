<?php

namespace Tests\Unit;

use App\Services\DocumentLanguageProfile;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentLanguageProfileTest extends TestCase
{
    public function test_one_two_and_three_locale_profiles_are_canonical_unordered_sets(): void
    {
        $profiles = app(DocumentLanguageProfile::class);

        $this->assertSame(['vi'], $profiles->canonical('vi'));
        $this->assertSame(['en', 'vi'], $profiles->canonical(['vi', 'en']));
        $this->assertSame(['en', 'ko', 'vi'], $profiles->canonical(['vi', 'ko', 'en']));
        $this->assertSame(
            $profiles->serialize(['vi', 'ko', 'en']),
            $profiles->serialize(['en', 'vi', 'ko'])
        );
    }

    public function test_duplicate_more_than_three_invalid_and_unsupported_profiles_fail_closed(): void
    {
        foreach ([['vi', 'vi'], ['vi', 'en', 'ko', 'fr'], ['not a locale'], ['fr']] as $input) {
            try {
                app(DocumentLanguageProfile::class)->canonical($input);
                $this->fail('Profile must fail closed.');
            } catch (InvalidArgumentException $exception) {
                $this->assertContains($exception->getMessage(), [
                    'document_language_profile_invalid',
                    'document_language_profile_unsupported',
                ]);
            }
        }
    }
}
