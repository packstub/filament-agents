<?php

namespace Packstub\Agents\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Packstub\Agents\Audit\RecordAuditEntry;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Models\AgentAuditEntry;
use Packstub\Agents\Tests\TestCase;

class AuditAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_entries_cannot_be_updated(): void
    {
        $entry = $this->makeEntry();

        $this->assertFalse($entry->update(['summary' => 'rewritten history']));
        $this->assertNotSame('rewritten history', $entry->fresh()->summary);
    }

    public function test_audit_entries_cannot_be_deleted(): void
    {
        $entry = $this->makeEntry();

        $this->assertFalse($entry->delete());
        $this->assertNotNull($entry->fresh());
    }

    public function test_audit_entries_have_no_updated_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('agent_audit_entries', 'updated_at'));
    }

    public function test_sensitive_argument_values_are_redacted(): void
    {
        $entry = app(RecordAuditEntry::class)->handle(
            null,
            'list-widgets',
            ToolMode::Read,
            AuditDecision::Allowed,
            [
                'license_key' => 'lk_super_secret',
                'nested' => [
                    'paddle_customer_id' => 'ctm_123',
                    'label' => 'safe value',
                ],
                'note' => str_repeat('a', 600),
            ],
        );

        $this->assertSame('[redacted]', $entry->arguments['license_key']);
        $this->assertSame('[redacted]', $entry->arguments['nested']['paddle_customer_id']);
        $this->assertSame('safe value', $entry->arguments['nested']['label']);
        $this->assertLessThanOrEqual(503, strlen($entry->arguments['note']));
    }

    private function makeEntry(): AgentAuditEntry
    {
        return app(RecordAuditEntry::class)->handle(
            null,
            'list-widgets',
            ToolMode::Read,
            AuditDecision::Allowed,
        );
    }
}
