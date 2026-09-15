<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validTransitions(): array
    {
        return [
            'applied -> shortlisted' => ['applied', 'shortlisted'],
            'applied -> rejected' => ['applied', 'rejected'],
            'shortlisted -> interview_scheduled' => ['shortlisted', 'interview_scheduled'],
            'shortlisted -> applied' => ['shortlisted', 'applied'],
            'shortlisted -> rejected' => ['shortlisted', 'rejected'],
            'interview_scheduled -> interviewed' => ['interview_scheduled', 'interviewed'],
            'interview_scheduled -> rejected' => ['interview_scheduled', 'rejected'],
            'interviewed -> offered' => ['interviewed', 'offered'],
            'interviewed -> shortlisted' => ['interviewed', 'shortlisted'],
            'interviewed -> rejected' => ['interviewed', 'rejected'],
            'offered -> hired' => ['offered', 'hired'],
            'offered -> rejected' => ['offered', 'rejected'],
            'rejected -> shortlisted' => ['rejected', 'shortlisted'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'applied -> interviewed (skips shortlisted)' => ['applied', 'interviewed'],
            'applied -> offered' => ['applied', 'offered'],
            'applied -> hired' => ['applied', 'hired'],
            'shortlisted -> interviewed (skips interview_scheduled)' => ['shortlisted', 'interviewed'],
            'shortlisted -> offered' => ['shortlisted', 'offered'],
            'shortlisted -> hired' => ['shortlisted', 'hired'],
            'interview_scheduled -> shortlisted (only the system can do this)' => ['interview_scheduled', 'shortlisted'],
            'interview_scheduled -> offered' => ['interview_scheduled', 'offered'],
            'interview_scheduled -> applied' => ['interview_scheduled', 'applied'],
            'interviewed -> applied' => ['interviewed', 'applied'],
            'interviewed -> hired' => ['interviewed', 'hired'],
            'offered -> applied' => ['offered', 'applied'],
            'offered -> shortlisted' => ['offered', 'shortlisted'],
            'offered -> interviewed' => ['offered', 'interviewed'],
            'rejected -> applied' => ['rejected', 'applied'],
            'rejected -> interviewed' => ['rejected', 'interviewed'],
            'rejected -> hired' => ['rejected', 'hired'],
            'hired -> applied (terminal)' => ['hired', 'applied'],
            'hired -> shortlisted (terminal)' => ['hired', 'shortlisted'],
            'hired -> rejected (terminal)' => ['hired', 'rejected'],
        ];
    }

    #[DataProvider('validTransitions')]
    public function test_valid_transition_succeeds(string $from, string $to): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::from($from))->create();

        $response = $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => $to]);

        $response->assertOk()->assertJsonPath('data.status', $to);
        $this->assertSame($to, $application->fresh()->status->value);
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_is_rejected(string $from, string $to): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::from($from))->create();

        $response = $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => $to]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame($from, $application->fresh()->status->value);
    }

    public function test_hired_is_a_terminal_state_with_no_valid_manual_transitions(): void
    {
        $this->assertSame([], ApplicationStatus::Hired->allowedManualTransitions());
    }

    public function test_setting_status_to_its_current_value_is_rejected_as_a_no_op(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Shortlisted)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'shortlisted'])
            ->assertStatus(422);
    }

    public function test_candidate_cannot_update_application_status(): void
    {
        $candidate = User::factory()->create();
        $application = Application::factory()->status(ApplicationStatus::Applied)->create(['candidate_id' => $candidate->id]);

        $this->actingAs($candidate, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'shortlisted'])
            ->assertStatus(403);
    }

    public function test_status_update_rejects_an_invalid_enum_value(): void
    {
        $hr = User::factory()->hr()->create();
        $application = Application::factory()->status(ApplicationStatus::Applied)->create();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/applications/{$application->id}/status", ['status' => 'not-a-real-status'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }
}
