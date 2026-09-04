<?php

use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

it('refuses a request without a token as JSON, whatever the client accepts', function () {
    $call = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

    // No Accept header at all: the framework would otherwise redirect to a login route the panel app does not name.
    post('/mcp', $call)
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Unauthenticated.']);

    post('/mcp', $call, ['Accept' => 'text/html'])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Unauthenticated.']);

    postJson('/mcp', $call, ['Accept' => 'application/json, text/event-stream'])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Unauthenticated.']);
});
