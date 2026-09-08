<?php

namespace Packstub\Agents\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Throwable;

/**
 * laravel/ai records a question and its answer together, once the answer has
 * arrived — so a question the provider never answered was lost. This store
 * lets the chat record the question first: storeQuestion() writes the row
 * before the provider is called, answering() tells the store which row the
 * turn belongs to, and the SDK then attaches the answer to it (the question
 * is neither stored twice nor fed back to the model as history).
 */
class AgentConversationStore extends DatabaseConversationStore
{
    /** The pre-stored question the running turn answers, if any. */
    protected ?string $answering = null;

    /** Open a conversation for the person, titled after the first question until the answer arrives. */
    public function startConversation(object $participant, string $prompt): string
    {
        return $this->storeConversation(
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            Str::limit($prompt, 50, preserveWords: true),
        );
    }

    /** Record a question before it is sent to the provider and return the message ID. */
    public function storeQuestion(string $conversationId, object $participant, string $agentClass, string $content): string
    {
        $messageId = (string) Str::uuid7();
        $now = now();

        $this->table($this->messagesTable())->insert($this->messageAttributes(
            $messageId,
            $conversationId,
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            $now,
            [
                'agent' => $agentClass,
                'role' => 'user',
                'content' => $content,
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'approval_state' => null,
            ],
        ));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /** The turn about to run answers this pre-stored question (null: none, the SDK stores the question itself). */
    public function answering(?string $messageId): void
    {
        $this->answering = $messageId;
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
    {
        if ($this->answering === null) {
            return parent::storeUserMessage($conversationId, $participantType, $participantId, $prompt);
        }

        $messageId = $this->answering;
        $this->answering = null;
        $this->touchConversation($conversationId, now());

        return $messageId;
    }

    /**
     * The history the model reads. While a pre-stored question is being answered it
     * is the conversation's last row, and the SDK sends it as the prompt — so it is
     * left out here rather than shown twice.
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $messages = parent::getLatestConversationMessages($conversationId, $limit);

        if ($this->answering !== null && $this->isUserMessage($messages->last())) {
            $messages->pop();
        }

        return $messages;
    }

    /** Title a conversation the way laravel/ai does once its first answer is in: a short provider-written line, else the question. */
    public function titleConversation(string $conversationId, string $prompt, TextProvider $provider): void
    {
        if (! (bool) config('ai.conversations.generate_title', true)) {
            return;
        }

        try {
            $response = $provider->textGenerationLoop()->generate(
                $provider,
                $provider->cheapestTextModel(),
                'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.',
                [new UserMessage(Str::limit($prompt, 500))],
            );

            if (($title = trim(Str::limit($response->text, 100))) !== '') {
                $this->table($this->conversationsTable())->where('id', $conversationId)->update(['title' => $title]);
            }
        } catch (Throwable) {
            // The question stays as the title.
        }
    }

    protected function isUserMessage(mixed $message): bool
    {
        return $message instanceof Message && $message->role === MessageRole::User;
    }
}
