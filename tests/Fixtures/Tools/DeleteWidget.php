<?php

namespace Packstub\Agents\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Packstub\Agents\Approvals\Proposal;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Mcp\ApprovableTool;
use Packstub\Agents\Models\PendingApproval;
use Packstub\Agents\Tests\Fixtures\Models\Widget;

#[IsDestructive]
class DeleteWidget extends ApprovableTool
{
    protected string $description = 'Propose deleting a widget. Requires admin approval.';

    public static function requiredMode(): ToolMode
    {
        return ToolMode::Destructive;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The widget id.')->required(),
        ];
    }

    protected function proposal(Request $request): ?Proposal
    {
        $widget = Widget::query()->findOrFail($request->get('id'));

        return new Proposal(
            arguments: $request->all(),
            proposedChanges: [
                'action' => ['from' => null, 'to' => "Delete widget [{$widget->name}]."],
            ],
            summary: "Delete widget [{$widget->name}].",
        );
    }

    public function apply(PendingApproval $approval): array
    {
        $widget = Widget::query()->findOrFail($approval->arguments['id']);

        $previous = $widget->only(['id', 'name', 'description']);

        $widget->delete();

        return ['previous' => $previous];
    }
}
