<?php

namespace Tests\Unit;

use App\Services\SpeechLanguageProfile;
use InvalidArgumentException;
use Tests\TestCase;

class SpeechLanguageProfileTest extends TestCase
{
    public function test_profiles_are_canonical_unordered_sets_of_up_to_three_locales(): void
    {
        $profiles = app(SpeechLanguageProfile::class);

        $this->assertSame(['vi'], $profiles->canonical('vi'));
        $this->assertSame(['ko', 'vi'], $profiles->canonical(['vi', 'ko']));
        $this->assertSame(['en', 'ko', 'vi'], $profiles->canonical(['vi', 'en', 'ko']));
        $this->assertSame('en,ko,vi', $profiles->serialize(['ko', 'vi', 'en']));
        $this->assertSame(['ko', 'vi'], $profiles->fromProfile('diarization=off;locales=ko,vi'));
        $this->assertSame(['vi'], $profiles->fromProfile('diarization=off;locale=vi'));
    }

    public function test_duplicate_invalid_oversized_and_unsupported_profiles_fail_closed(): void
    {
        foreach ([['vi', 'vi'], ['vi', 'en', 'ko', 'fr'], ['not a locale'], ['fr'], []] as $input) {
            try {
                app(SpeechLanguageProfile::class)->canonical($input);
                $this->fail('Profile must fail closed.');
            } catch (InvalidArgumentException $exception) {
                $this->assertContains($exception->getMessage(), [
                    'speech_language_profile_invalid',
                    'speech_language_profile_unsupported',
                ]);
            }
        }
    }
}
