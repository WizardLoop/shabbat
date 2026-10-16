<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\EventHandler\Query\ChatButtonQuery;
use Shabbat\Runtime\{AdminIntegration, Application};
use Shabbat\UI\{Lang, OperatorController, OperatorDraft};

/** Real navigation trait with native SDK value objects, without constructing a Telegram session. */
final class AdminNavigationFixture
{
    use AdminIntegration;

    public function __construct(private Application $app)
    {
    }

    private function v3App(): Application
    {
        return $this->app;
    }

    private function v3Text(string $user, string $key, array $values = []): string
    {
        return (new Lang())->text($this->app->settings->getUser($user)['language'], $key, $values);
    }

    private function v3OperatorDraft(): OperatorDraft
    {
        return new OperatorDraft($this->app, fn(string $user, bool $mutate = false): bool => $mutate
            ? $this->app->adminAccess->canMutate($user, 'broadcast') : $this->app->adminAccess->canView($user, 'broadcast'));
    }

    private function v3OperatorUi(): OperatorController
    {
        return new OperatorController($this->app->settings, new Lang(), $this->app->adminAccess->isAdmin(...), $this->app->adminAccess);
    }

    public function getSelf(): array
    {
        return ['id' => 999];
    }

    public function getInfo(int $id): array
    {
        return ['type' => 'user','User' => ['first_name' => 'Fixture user']];
    }

    public function draft(): OperatorDraft
    {
        return $this->v3OperatorDraft();
    }

    public function adminPrompt(): void
    {
        $this->v4Admin()->callback('1', 'adm:add');
    }

    public function callback(string $data, int $user = 1, ?int $chat = null, int $panel = 200, bool $frontend = true): bool
    {
        $chat ??= $user;
        $class = new \ReflectionClass(ChatButtonQuery::class);
        $query = $class->newInstanceWithoutConstructor();
        foreach (['data' => $data,'userId' => $user,'chatId' => $chat,'messageId' => $panel,'queryId' => 99,'chatInstance' => 777] as $key => $value) {
            $class->getProperty($key)->getDeclaringClass()->getProperty($key)->setValue($query, $value);
        }
        $event = ['user_id' => (string)$user,'chat_id' => (string)$chat,'private' => $chat === $user,'message_id' => $panel,'data' => $data,'query_id' => '99'];
        $handled = $this->v4HandleCallback($query, $event);
        return $handled || ($frontend && $this->app->ui->handleCallback($event));
    }

    public function message(string $text, int $user = 1): bool
    {
        $class = new \ReflectionClass(PrivateMessage::class);
        $message = $class->newInstanceWithoutConstructor();
        foreach (['message' => $text,'senderId' => $user,'chatId' => $user,'id' => 600] as $key => $value) {
            $class->getProperty($key)->getDeclaringClass()->getProperty($key)->setValue($message, $value);
        }
        return $this->v4HandleMessage($message, ['user_id' => (string)$user,'private' => true,'text' => $text,'message_id' => 600,'entities' => []]);
    }
}
