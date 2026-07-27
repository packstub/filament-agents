<?php

namespace Packstub\Agents\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Packstub\Agents\Approvals\Proposal;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Mcp\ApprovableTool;
use Packstub\Agents\Models\PendingApproval;
use Packstub\Agents\Tests\Fixtures\Models\Widget;

#[IsIdempotent]
class UpdateWidget extends ApprovableTool
{
    protected string $description = 'Propose an update to a widget. Requires admin approval.';

    private const array FIELDS = ['name', 'description'];

    public static function requiredMode(): ToolMode
    {
        return ToolMode::Write;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The widget id.')->required(),
            'name' => $schema->string(),
            'description' => $schema->string(),
        ];
    }

    protected function proposal(Request $request): ?Proposal
    {
        $widget = Widget::query()->findOrFail($request->get('id'));

        $proposedChanges = [];

        foreach (self::FIELDS as $field) {
            if ($request->get($field) === null || $request->get($field) === $widget->{$field}) {
                continue;
            }

            $proposedChanges[$field] = [
                'from' => $widget->{$field},
                'to' => $request->get($field),
            ];
        }

        return new Proposal(
            arguments: $request->all(),
            proposedChanges: $proposedChanges,
            summary: "Update widget [{$widget->name}].",
        );
    }

    public function apply(PendingApproval $approval): array
    {
        $widget = Widget::query()->findOrFail($approval->arguments['id']);

        $previous = [];
        $updates = [];

        foreach ($approval->proposed_changes as $field => $change) {
            $previous[$field] = $widget->{$field};
            $updates[$field] = $change['to'];
        }

        $widget->fill($updates)->save();

        return ['previous' => $previous];
    }
}
