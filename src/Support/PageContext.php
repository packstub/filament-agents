<?php

namespace Packstub\Agents\Support;

use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Contracts\AgentResource;

/**
 * What the person was looking at when they opened the chat ("orders/12"),
 * so "why is this one stuck?" needs no order number. Only the record's
 * compact summary is injected; the agent calls a tool for the rest.
 */
class PageContext
{
    /** @return array{label: string, summary: array<string, mixed>}|null */
    public static function resolve(?string $ref): ?array
    {
        if (! $ref || ! preg_match('/^([a-z0-9_]+)\/([A-Za-z0-9-]+)$/', $ref, $m) || ! AgentResources::has($m[1])) {
            return null;
        }

        $resource = AgentResources::find($m[1]);
        $record = self::record($resource, $m[2]);

        if (! $record) {
            return null;
        }

        return [
            'label' => $resource::agentContextLabel($record),
            'summary' => $resource::agentSummary($record),
        ];
    }

    /** The context reference for the page currently being served, if it is a record page of a known resource. */
    public static function fromRequest(): ?string
    {
        $route = request()->route();
        $record = $route?->parameter('record');
        $name = (string) $route?->getName();

        if ($record instanceof Model) {
            $resource = AgentResources::forModel($record);

            return $resource ? $resource::agentKey().'/'.$record->getKey() : null;
        }

        if (! is_scalar($record) || $name === '') {
            return null;
        }

        foreach (AgentResources::all() as $key => $resource) {
            if (str_contains($name, '.resources.'.$resource::getSlug().'.')) {
                return $key.'/'.$record;
            }
        }

        return null;
    }

    /** @param  class-string<AgentResource>  $resource */
    protected static function record(string $resource, string $id): ?Model
    {
        $record = $resource::resolveRecordRouteBinding($id);

        return $record instanceof Model ? $record : null;
    }
}
