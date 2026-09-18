<?php

namespace Tests\Feature\Auth;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CnicSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cnic_is_stored_encrypted_at_rest(): void
    {
        $plainCnic = '12345-1234567-1';
        $user = User::factory()->withCnic($plainCnic)->create();

        $rawValue = DB::table('users')->where('id', $user->id)->value('cnic');

        $this->assertNotSame($plainCnic, $rawValue);
        $this->assertStringNotContainsString($plainCnic, $rawValue);
        $this->assertStringNotContainsString('12345', $rawValue);
        // Proves it's genuinely encrypted (round-trips through the app's
        // encrypter), not just obfuscated some other way.
        $this->assertSame($plainCnic, Crypt::decryptString($rawValue));
    }

    public function test_cnic_hash_column_is_not_the_encrypted_cnic_value(): void
    {
        $plainCnic = '12345-1234567-1';
        $user = User::factory()->withCnic($plainCnic)->create();

        $row = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame($row->cnic, $row->cnic_hash);
        $this->assertSame(User::hashCnic($plainCnic), $row->cnic_hash);
        // The hash is one-way — it must not be reversible via the app's encrypter.
        $this->assertStringNotContainsString($plainCnic, $row->cnic_hash);
    }

    public function test_candidate_sees_their_own_cnic_on_their_application(): void
    {
        $candidate = User::factory()->withCnic('12345-1234567-1')->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);

        $response = $this->actingAs($candidate, 'sanctum')->getJson("/api/applications/{$application->id}");

        $response->assertOk()->assertJsonPath('data.candidate.cnic', '12345-1234567-1');
    }

    public function test_admin_sees_a_candidates_cnic_on_their_application(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->withCnic('12345-1234567-1')->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/applications/{$application->id}");

        $response->assertOk()->assertJsonPath('data.candidate.cnic', '12345-1234567-1');
    }

    public function test_hr_cannot_see_a_candidates_cnic_via_application_show(): void
    {
        $hr = User::factory()->hr()->create();
        $candidate = User::factory()->withCnic('12345-1234567-1')->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);

        $response = $this->actingAs($hr, 'sanctum')->getJson("/api/applications/{$application->id}");

        $response->assertOk()->assertJsonMissingPath('data.candidate.cnic');
    }

    public function test_assistant_hr_cannot_see_a_candidates_cnic_via_application_show(): void
    {
        $assistantHr = User::factory()->assistantHr()->create();
        $candidate = User::factory()->withCnic('12345-1234567-1')->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);

        $response = $this->actingAs($assistantHr, 'sanctum')->getJson("/api/applications/{$application->id}");

        $response->assertOk()->assertJsonMissingPath('data.candidate.cnic');
    }

    public function test_hr_cannot_see_a_candidates_cnic_via_applications_index(): void
    {
        $hr = User::factory()->hr()->create();
        $candidate = User::factory()->withCnic('12345-1234567-1')->create();
        Application::factory()->create(['candidate_id' => $candidate->id]);

        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/applications');

        $response->assertOk();
        $this->assertStringNotContainsString('12345-1234567-1', $response->getContent());
        foreach ($response->json('data') as $applicationData) {
            $this->assertArrayNotHasKey('cnic', $applicationData['candidate']);
        }
    }

    public function test_another_candidate_cannot_view_a_different_candidates_application_at_all(): void
    {
        $otherCandidate = User::factory()->create();
        $candidate = User::factory()->withCnic('12345-1234567-1')->create();
        $application = Application::factory()->create(['candidate_id' => $candidate->id]);

        $response = $this->actingAs($otherCandidate, 'sanctum')->getJson("/api/applications/{$application->id}");

        $response->assertStatus(403);
    }

    public function test_candidate_sees_their_own_cnic_immediately_in_the_login_response(): void
    {
        $user = User::factory()->withCnic('12345-1234567-1')->create(['password' => 'password123']);

        $response = $this->postJson('/api/login', [
            'identifier' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonPath('user.cnic', '12345-1234567-1');
    }
}
