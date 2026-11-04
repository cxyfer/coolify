<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $token = $this->user->createToken('description-validation', ['write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->token = $token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

dataset('descriptions the UI does not allow', [
    'a character outside the allowed set' => ['API [v2]: production', 'The description may only contain letters'],
    'more than 255 characters' => [str_repeat('a', 256), 'The description may not be greater than 255 characters.'],
]);

dataset('a description the UI allows', [
    'Unicode letters and allowed punctuation' => ['Café API (v2), staging & production!'],
]);

function descriptionApiRequest(string $method, string $uri, array $payload): TestResponse
{
    return test()->withToken(test()->token)->json($method, $uri, $payload);
}

function descriptionApiApplication(): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'description' => 'Original description',
    ]);
}

function descriptionApiService(): Service
{
    return Service::factory()->create([
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'environment_id' => test()->environment->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
        'description' => 'Original description',
    ]);
}

function descriptionApiDatabase(): StandaloneRedis
{
    return create_standalone_redis(test()->environment->id, test()->destination, ['description' => 'Original description']);
}

function descriptionApiServer(): Server
{
    return Server::factory()->create(['team_id' => test()->team->id, 'description' => 'Original description']);
}

function descriptionApiPrivateKey(): PrivateKey
{
    return PrivateKey::factory()->create(['team_id' => test()->team->id, 'description' => 'Original description']);
}

describe('applications', function () {
    it('returns 422 for a new application with a description the UI does not allow and creates none', function (string $description, string $message) {
        $response = descriptionApiRequest('POST', '/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'ports_exposes' => '80',
            'description' => $description,
        ]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        $this->assertDatabaseCount('applications', 0);
    })->with('descriptions the UI does not allow');

    it('returns 422 for an update with a description the UI does not allow and keeps the old one', function (string $description, string $message) {
        $application = descriptionApiApplication();

        $response = descriptionApiRequest('PATCH', "/api/v1/applications/{$application->uuid}", ['description' => $description]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        expect($application->refresh()->description)->toBe('Original description');
    })->with('descriptions the UI does not allow');

    it('saves an update with a description the UI allows', function (string $description) {
        $application = descriptionApiApplication();

        $response = descriptionApiRequest('PATCH', "/api/v1/applications/{$application->uuid}", ['description' => $description]);

        $response->assertOk();
        expect($application->refresh()->description)->toBe($description);
    })->with('a description the UI allows');
});

describe('services', function () {
    it('returns 422 for a new service with a description the UI does not allow and creates none', function (string $description, string $message) {
        $response = descriptionApiRequest('POST', '/api/v1/services', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_compose_raw' => base64_encode("services:\n  web:\n    image: nginx:alpine\n"),
            'description' => $description,
        ]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        $this->assertDatabaseCount('services', 0);
    })->with('descriptions the UI does not allow');

    it('returns 422 for an update with a description the UI does not allow and keeps the old one', function (string $description, string $message) {
        $service = descriptionApiService();

        $response = descriptionApiRequest('PATCH', "/api/v1/services/{$service->uuid}", ['description' => $description]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        expect($service->refresh()->description)->toBe('Original description');
    })->with('descriptions the UI does not allow');

    it('saves an update with a description the UI allows', function (string $description) {
        $service = descriptionApiService();

        $response = descriptionApiRequest('PATCH', "/api/v1/services/{$service->uuid}", ['description' => $description]);

        $response->assertOk();
        expect($service->refresh()->description)->toBe($description);
    })->with('a description the UI allows');
});

describe('databases', function () {
    it('returns 422 for a new database with a description the UI does not allow and creates none', function (string $description, string $message) {
        $response = descriptionApiRequest('POST', '/api/v1/databases/postgresql', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'description' => $description,
        ]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        $this->assertDatabaseCount('standalone_postgresqls', 0);
    })->with('descriptions the UI does not allow');

    it('returns 422 for an update with a description the UI does not allow and keeps the old one', function (string $description, string $message) {
        $database = descriptionApiDatabase();

        $response = descriptionApiRequest('PATCH', "/api/v1/databases/{$database->uuid}", ['description' => $description]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        expect($database->refresh()->description)->toBe('Original description');
    })->with('descriptions the UI does not allow');

    it('saves an update with a description the UI allows', function (string $description) {
        $database = descriptionApiDatabase();

        $response = descriptionApiRequest('PATCH', "/api/v1/databases/{$database->uuid}", ['description' => $description]);

        $response->assertOk();
        expect($database->refresh()->description)->toBe($description);
    })->with('a description the UI allows');
});

describe('servers', function () {
    it('returns 422 for a new server with a description the UI does not allow and creates none', function (string $description, string $message) {
        $privateKey = descriptionApiPrivateKey();

        $response = descriptionApiRequest('POST', '/api/v1/servers', [
            'name' => 'Description test server',
            'ip' => '192.0.2.20',
            'private_key_uuid' => $privateKey->uuid,
            'description' => $description,
        ]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        $this->assertDatabaseMissing('servers', ['ip' => '192.0.2.20']);
    })->with('descriptions the UI does not allow');

    it('returns 422 for an update with a description the UI does not allow and keeps the old one', function (string $description, string $message) {
        $server = descriptionApiServer();

        $response = descriptionApiRequest('PATCH', "/api/v1/servers/{$server->uuid}", ['description' => $description]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        expect($server->refresh()->description)->toBe('Original description');
    })->with('descriptions the UI does not allow');

    it('saves an update with a description the UI allows', function (string $description) {
        $server = descriptionApiServer();

        $response = descriptionApiRequest('PATCH', "/api/v1/servers/{$server->uuid}", ['description' => $description]);

        $response->assertCreated();
        expect($server->refresh()->description)->toBe($description);
    })->with('a description the UI allows');
});

describe('private keys', function () {
    it('returns 422 for a new private key with a description the UI does not allow and creates none', function (string $description, string $message) {
        $response = descriptionApiRequest('POST', '/api/v1/security/keys', [
            'name' => 'Deploy key',
            'description' => $description,
            'private_key' => PrivateKey::factory()->make(['team_id' => $this->team->id])->private_key,
        ]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        $this->assertDatabaseCount('private_keys', 0);
    })->with('descriptions the UI does not allow');

    it('returns 422 for an update with a description the UI does not allow and keeps the old one', function (string $description, string $message) {
        $privateKey = descriptionApiPrivateKey();

        $response = descriptionApiRequest('PATCH', "/api/v1/security/keys/{$privateKey->uuid}", ['description' => $description]);

        $response->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['description' => $message]);
        expect($privateKey->refresh()->description)->toBe('Original description');
    })->with('descriptions the UI does not allow');

    it('saves an update with a description the UI allows', function (string $description) {
        $privateKey = descriptionApiPrivateKey();

        $response = descriptionApiRequest('PATCH', "/api/v1/security/keys/{$privateKey->uuid}", ['description' => $description]);

        $response->assertCreated();
        expect($privateKey->refresh()->description)->toBe($description);
    })->with('a description the UI allows');
});
