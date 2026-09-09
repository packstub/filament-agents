<?php

namespace Packstub\Agents\Support;

use Illuminate\Support\Str;

/** An answer as HTML: CommonMark with raw HTML stripped and unsafe links dropped, for the chat page and the poll endpoint. */
class Markdown
{
    public static function render(string $text): string
    {
        return Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
