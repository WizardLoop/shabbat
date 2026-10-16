<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Scheduling;

use Shabbat\UI\Lang;

final class TefillinAckText
{
    public static function label(string $language): string
    {
        return match (Lang::canonical($language)) {
            'en' => 'I put on tefillin',
            'ru' => 'Я надел тфилин',
            'fr' => "J'ai mis les téfilines",
            'es' => 'Me puse tefilín',
            'pt-br' => 'Coloquei tefilin',
            default => 'הנחתי',
        };
    }
    public static function feedback(string $language, bool $selected, int $count): string
    {
        $language = Lang::canonical($language);
        $caption = match ($language) {
            'en' => $selected ? 'Your acknowledgement was recorded.' : 'Your acknowledgement was removed.',
            'ru' => $selected ? 'Ваш ответ записан.' : 'Ваш ответ удалён.',
            'fr' => $selected ? 'Votre confirmation a été enregistrée.' : 'Votre confirmation a été retirée.',
            'es' => $selected ? 'Tu confirmación quedó registrada.' : 'Tu confirmación se ha eliminado.',
            'pt-br' => $selected ? 'Sua confirmação foi registrada.' : 'Sua confirmação foi removida.',
            default => $selected ? 'ההנחה שלך נרשמה.' : 'הסימון שלך בוטל.',
        };
        return $caption . ' (' . $count . ')';
    }
}
