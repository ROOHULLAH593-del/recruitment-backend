<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RejectionReasonTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejecting_with_a_reason_persists_it(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'Not enough relevant experience for this role.',
            ])
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', 'Not enough relevant experience for this role.');

        $this->assertSame('Not enough relevant experience for this role.', $application->fresh()->rejection_reason);
    }

    public function test_rejecting_without_a_reason_is_fine(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', null);

        $this->assertNull($application->fresh()->rejection_reason);
    }

    public function test_a_reason_sent_for_a_non_rejection_transition_is_ignored(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Applied)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", [
                'status' => 'shortlisted',
                'rejection_reason' => 'This should not be saved.',
            ])
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', null);

        $this->assertNull($application->fresh()->rejection_reason);
    }

    public function test_reason_is_cleared_when_later_un_rejected(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')->patchJson("/api/applications/{$application->id}/status", [
            'status' => 'rejected',
            'rejection_reason' => 'Initially rejected.',
        ])->assertOk();
        $this->assertSame('Initially rejected.', $application->fresh()->rejection_reason);

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'shortlisted'])
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', null);

        $this->assertNull($application->fresh()->rejection_reason);
    }

    public function test_rejection_email_includes_the_reason_when_present(): void
    {
        $application = Application::factory()->status(ApplicationStatus::Rejected)->create([
            'rejection_reason' => 'Looking for a candidate with more leadership experience.',
        ])->load('job', 'candidate');

        $mail = (new ApplicationStatusChanged($application, ApplicationStatus::Shortlisted))->toMail($application->candidate);

        $this->assertTrue(collect($mail->introLines)->contains(
            fn ($line) => str_contains($line, 'Looking for a candidate with more leadership experience.')
        ));
    }

    public function test_rejection_email_has_no_reason_line_when_absent(): void
    {
        $application = Application::factory()->status(ApplicationStatus::Rejected)->create([
            'rejection_reason' => null,
        ])->load('job', 'candidate');

        $mail = (new ApplicationStatusChanged($application, ApplicationStatus::Shortlisted))->toMail($application->candidate);

        $allLines = implode(' ', [...$mail->introLines, ...$mail->outroLines]);
        $this->assertStringNotContainsString('Feedback', $allLines);
        $this->assertStringNotContainsString('null', $allLines);
    }

    public function test_application_resource_exposes_rejection_reason(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')->patchJson("/api/applications/{$application->id}/status", [
            'status' => 'rejected',
            'rejection_reason' => 'A visible reason.',
        ])->assertOk();

        $this->actingAs($hr, 'sanctum')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', 'A visible reason.');
    }
}
