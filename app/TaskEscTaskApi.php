<?php
declare(strict_types=1);

/** Application-level API gateway shared by REST v1 and the WhatsApp AI.
 * Caller identity and abilities are preserved; no privileged API bearer token
 * or network hop is needed to run inside the same TaskEsc transaction.
 */
final class TaskEscTaskApi
{
    public static function list(array $params): array
    {
        Auth::requireAbility('tasks.read');
        return Demands::list($params);
    }
    public static function create(array $params): array
    {
        Auth::requireAbility('tasks.create');
        return Demands::save($params);
    }
    public static function update(array $params): array
    {
        Auth::requireAbility('tasks.edit');
        return Demands::save($params);
    }
    public static function move(array $params): array
    {
        Auth::requireAbility('tasks.move');
        return Demands::stage($params);
    }
}
