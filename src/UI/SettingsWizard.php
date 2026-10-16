<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

/** Pure presentation definitions; existing form parsing and repository writes retain canonical validation. */
final class SettingsWizard
{
    private static ?Lang $lang = null;
    private const FIELDS = [
        'shabbat' => ['candle_minutes','havdalah_method','havdalah_value','close_before_candles_minutes','open_after_havdalah_minutes','close_message_enabled','open_message_enabled','times_alert_enabled','times_alert_time','times_alert_mode','times_alert_before_minutes','quiet_start','quiet_end','target_topic_id','lock_block_flags','delete_holy_user_messages','delete_holy_bot_messages'],
        'zmanim' => ['time','days','format'],
        'daf' => ['time','days','template'],
        'tefillin' => ['days','fixed_times','interval_minutes','window_start','window_end','sunset_offsets','max_per_day','min_spacing_minutes','chol_hamoed','tisha_bav_policy','sunset_safety_minutes','quiet_start','quiet_end','template','delete_previous','ack_enabled','ack_label'],
        'omer' => ['time','mode','after_nightfall_minutes','nightfall_method','nightfall_value','nusach','format','day_completion','early_policy','after_open_minutes'],
    ];
    private const ENUM_LABELS = ['he' => ['degrees' => 'מעלות', 'minutes' => 'דקות קבועות', 'short' => 'קצר', 'full' => 'מלא', 'fixed' => 'שעה קבועה', 'nightfall' => 'אחרי צאת הכוכבים', 'local_time' => 'שעה מקומית', 'before_close' => 'לפני סגירת הקבוצה', 'ashkenaz' => 'אשכנז', 'sephard' => 'ספרד / עדות המזרח', 'defer' => 'המתנה למועד המתאים', 'skip' => 'דילוג', 'off' => 'כבוי', 'mincha' => 'מנחה'], 'en' => ['degrees' => 'Degrees', 'minutes' => 'Fixed minutes', 'short' => 'Short', 'full' => 'Full', 'fixed' => 'Fixed time', 'nightfall' => 'After nightfall', 'local_time' => 'Local time', 'before_close' => 'Before group closes', 'ashkenaz' => 'Ashkenaz', 'sephard' => 'Sephard', 'defer' => 'Wait until permitted', 'skip' => 'Skip', 'off' => 'Off', 'mincha' => 'Mincha'], 'ru' => ['degrees' => 'Градусы', 'minutes' => 'Фиксированные минуты', 'short' => 'Кратко', 'full' => 'Полностью', 'fixed' => 'Фиксированное время', 'nightfall' => 'После выхода звёзд', 'local_time' => 'Местное время', 'before_close' => 'До закрытия группы', 'ashkenaz' => 'Ашкеназ', 'sephard' => 'Сефард', 'defer' => 'Дождаться подходящего времени', 'skip' => 'Пропустить', 'off' => 'Выключено', 'mincha' => 'Минха'], 'fr' => ['degrees' => 'Degrés', 'minutes' => 'Minutes fixes', 'short' => 'Court', 'full' => 'Complet', 'fixed' => 'Heure fixe', 'nightfall' => 'Après la nuit', 'local_time' => 'Heure locale', 'before_close' => 'Avant la fermeture du groupe', 'ashkenaz' => 'Ashkénaze', 'sephard' => 'Séfarade', 'defer' => 'Attendre le moment permis', 'skip' => 'Ignorer', 'off' => 'Désactivé', 'mincha' => 'Min’ha'], 'es' => ['degrees' => 'Grados', 'minutes' => 'Minutos fijos', 'short' => 'Breve', 'full' => 'Completo', 'fixed' => 'Hora fija', 'nightfall' => 'Después del anochecer', 'local_time' => 'Hora local', 'before_close' => 'Antes del cierre del grupo', 'ashkenaz' => 'Ashkenaz', 'sephard' => 'Sefardí', 'defer' => 'Esperar el momento permitido', 'skip' => 'Omitir', 'off' => 'Desactivado', 'mincha' => 'Minjá'], 'pt-br' => ['degrees' => 'Graus', 'minutes' => 'Minutos fixos', 'short' => 'Curto', 'full' => 'Completo', 'fixed' => 'Horário fixo', 'nightfall' => 'Após o anoitecer', 'local_time' => 'Horário local', 'before_close' => 'Antes do fechamento do grupo', 'ashkenaz' => 'Ashkenaz', 'sephard' => 'Sefardita', 'defer' => 'Aguardar o momento permitido', 'skip' => 'Pular', 'off' => 'Desativado', 'mincha' => 'Minchá']];
    private const DAYS = ['he' => ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'], 'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], 'ru' => ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'], 'fr' => ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'], 'es' => ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'], 'pt-br' => ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado']];

    public static function fields(string $module, bool $group = true): array
    {
        $fields = self::FIELDS[$module] ?? throw new \InvalidArgumentException('Unknown settings module.');
        return $group ? $fields : ($module === 'shabbat' ? ['candle_minutes','havdalah_method','havdalah_value'] : []);
    }

    /** Focused categories retain canonical field indexes and validation. */
    public static function sectionFields(string $section): array
    {
        return match ($section) {
            'shabbat_timing' => ['candle_minutes', 'havdalah_method', 'havdalah_value', 'close_before_candles_minutes', 'open_after_havdalah_minutes'],
            'shabbat_messages' => ['close_message_enabled', 'open_message_enabled', 'quiet_start', 'quiet_end', 'target_topic_id'],
            'announcement' => ['times_alert_mode', 'times_alert_time', 'times_alert_before_minutes'],
            default => throw new \InvalidArgumentException('Unknown settings section.'),
        };
    }

    public static function definition(string $module, string $field, bool $group = true): array
    {
        if (!in_array($field, self::fields($module, $group), true)) {
            throw new \InvalidArgumentException('Unsupported settings field.');
        }
        $base = ['kind' => 'text', 'options' => [], 'min' => null, 'max' => null, 'nullable' => false];
        if (in_array($field, ['close_message_enabled','open_message_enabled','times_alert_enabled','chol_hamoed','delete_previous','day_completion','delete_holy_user_messages','delete_holy_bot_messages','ack_enabled'], true)) {
            return array_replace($base, ['kind' => 'bool','options' => [true,false]]);
        }
        $options = match ($field) {
            'havdalah_method','nightfall_method' => ['degrees','minutes'],
            'format' => ['short','full'],
            'mode' => ['fixed','nightfall'],
            'times_alert_mode' => ['local_time','before_close'],
            'nusach' => ['ashkenaz','sephard'],
            'early_policy' => ['defer','skip'],
            'tisha_bav_policy' => ['off','mincha'],
            default => null,
        };
        if ($options !== null) {
            return array_replace($base, ['kind' => 'enum','options' => $options]);
        }
        if ($field === 'days') {
            return array_replace($base, ['kind' => 'days','options' => range(0, 6),'min' => 0,'max' => 6]);
        }
        if ($field === 'lock_block_flags') {
            return array_replace($base, ['kind' => 'flags','options' => \Shabbat\Scheduling\Permissions::BLOCK_FLAGS]);
        }
        if ($field === 'fixed_times') {
            return array_replace($base, ['kind' => 'times','options' => [['09:00'],['09:00','13:00'],['09:00','12:00','15:00'],[]]]);
        }
        if ($field === 'sunset_offsets') {
            return array_replace($base, ['kind' => 'minutes_list','options' => [[60,30,15],[60,30],[30],[15],[]],'min' => 1,'max' => 360]);
        }
        if (in_array($field, ['havdalah_value','nightfall_value'], true)) {
            return array_replace($base, ['kind' => 'float','options' => [8.5,7.083,42,50,72],'min' => 0.001,'max' => 120]);
        }
        if (str_ends_with($field, '_time') || in_array($field, ['time','window_start','window_end','quiet_start','quiet_end'], true)) {
            $nullable = in_array($field, ['quiet_start','quiet_end'], true);
            $times = $field === 'times_alert_time' ? ['10:00','11:00','12:00','12:30','13:00','13:30','14:00']
                : ['06:00','07:00','08:00','09:00','10:00','12:00','13:30','16:00','18:00','20:00','21:00','22:00'];
            return array_replace($base, ['kind' => 'time','options' => $nullable ? array_merge([null], $times) : $times,'nullable' => $nullable]);
        }
        $integers = [
            'candle_minutes' => [1,120,[null,18,20,30,40,60],true],
            'close_before_candles_minutes' => [0,180,[0,5,10,15,30,60],false],
            'open_after_havdalah_minutes' => [0,180,[0,5,10,15,30,60],false],
            'times_alert_before_minutes' => [0,360,[0,15,30,60,90,120],false],
            'interval_minutes' => [5,360,[null,15,30,60,90,120],true],
            'max_per_day' => [1,24,[1,2,3,4,6,12,24],false],
            'min_spacing_minutes' => [5,360,[5,10,15,30,60,90,120],false],
            'sunset_safety_minutes' => [1,120,[5,10,15,20,30,60],false],
            'after_nightfall_minutes' => [0,240,[0,5,10,15,20,30,60],false],
            'after_open_minutes' => [0,180,[0,5,10,15,30,60],false],
            'target_topic_id' => [1,2147483647,[null],true],
        ];
        if (isset($integers[$field])) {
            [$min,$max,$presets,$nullable] = $integers[$field];
            return array_replace($base, ['kind' => 'integer','options' => $presets,'min' => $min,'max' => $max,'nullable' => $nullable]);
        }
        return $field === 'ack_label' ? array_replace($base, ['max' => 48,'options' => [null],'nullable' => true]) : array_replace($base, ['max' => 4000]);
    }

    public static function key(string $module, string $field): string
    {
        self::definition($module, $field);
        return $module === 'shabbat' || in_array($field, ['quiet_start','quiet_end'], true) ? $field : $module . '_' . $field;
    }

    /** Typed button choices and single-question inputs pass through the existing parser exactly once. */
    public static function changes(string $module, string $field, mixed $input, array $context, callable $parseForm): array
    {
        $definition = self::definition($module, $field, isset($context['group_id']));
        $kind = $definition['kind'];
        if ($input === null) {
            if (!$definition['nullable']) {
                throw new \InvalidArgumentException('This setting cannot be disabled.');
            }
            $serialized = $field === 'ack_label' ? '' : ($field === 'candle_minutes' ? 'city' : 'off');
        } elseif ($kind === 'bool') {
            if (!is_bool($input)) {
                throw new \InvalidArgumentException('Use the on or off button.');
            }
            $serialized = $input ? 'true' : 'false';
        } elseif (in_array($kind, ['days','times','minutes_list','flags'], true)) {
            if (is_string($input)) {
                $input = trim($input) === '' ? [] : array_map('trim', explode(',', $input));
            }
            if (!is_array($input) || !array_is_list($input)) {
                throw new \InvalidArgumentException('Expected a list.');
            }
            foreach ($input as $item) {
                if (!is_scalar($item) || str_contains((string)$item, ',') || str_contains((string)$item, ';')) {
                    throw new \InvalidArgumentException('Invalid list item.');
                }
            }
            $serialized = implode(',', $input);
        } elseif (is_string($input) || is_int($input) || is_float($input)) {
            $serialized = (string)$input;
        } else {
            throw new \InvalidArgumentException('Invalid setting input.');
        }
        $form = $field . '=' . $serialized;
        $methodField = $module === 'shabbat' ? 'havdalah_method' : 'nightfall_method';
        $valueField = $module === 'shabbat' ? 'havdalah_value' : 'nightfall_value';
        if (in_array($field, ['havdalah_method','nightfall_method'], true)) {
            $valueKey = self::key($module, $valueField);
            $value = $context[$valueKey] ?? 8.5;
            if ($input === 'degrees' && !in_array((float)$value, [8.5,7.083], true)) {
                $form .= ';' . $valueField . '=8.5';
            } elseif ($input === 'minutes' && (!is_numeric($value) || floor((float)$value) !== (float)$value)) {
                $form .= ';' . $valueField . '=42';
            }
        } elseif (in_array($field, ['havdalah_value','nightfall_value'], true)) {
            if (!is_numeric($input) || !is_finite((float)$input)) {
                throw new \InvalidArgumentException('Invalid nightfall value.');
            }
            $value = (float)$input;
            $method = in_array($value, [8.5,7.083], true) ? 'degrees' : 'minutes';
            if ($method === 'minutes' && floor($value) !== $value) {
                throw new \InvalidArgumentException('Fixed nightfall minutes must be whole.');
            }
            $form .= ';' . $methodField . '=' . $method;
        }
        $result = $parseForm($module, $form);
        if (!is_array($result)) {
            throw new \LogicException('Settings parser must return normalized changes.');
        }
        return $result;
    }

    public static function current(string $module, string $field, array $context, string $language): string
    {
        $value = $context[self::key($module, $field)] ?? null;
        return self::valueLabel($module, $field, $value, $language);
    }

    public static function valueLabel(string $module, string $field, mixed $value, string $language): string
    {
        $language = Lang::canonical($language);
        $lang = self::$lang ??= new Lang();
        if ($field === 'ack_label' && ($value === null || $value === '')) {
            return $lang->text($language, 'tefillin_ack');
        }
        if ($value === null) {
            return $lang->text($language, $field === 'candle_minutes' ? 'city_custom' : 'off');
        }
        if (is_bool($value)) {
            return $lang->text($language, $value ? 'on' : 'off');
        }
        if ($field === 'template') {
            return $lang->text($language, 'messages');
        }
        if ($field === 'lock_block_flags' && is_array($value)) {
            return $value === [] ? $lang->text($language, 'off')
                : implode(', ', array_map(static fn(string $flag): string => $lang->text($language, 'permission_' . $flag), $value));
        }
        if ($field === 'days' && is_array($value)) {
            return $value === [] ? $lang->text($language, 'off') : implode(', ', array_map(static fn(int $day): string => self::DAYS[$language][$day] ?? throw new \InvalidArgumentException('Invalid weekday.'), $value));
        }
        if (is_array($value)) {
            return $value === [] ? $lang->text($language, 'off') : implode(', ', $value);
        }
        if (in_array($field, ['havdalah_value', 'nightfall_value'], true) && is_numeric($value)) {
            $number = (string) $value;
            if (in_array($language, ['ru', 'fr', 'es', 'pt-br'], true)) {
                $number = str_replace('.', ',', $number);
            }
            return $number . ' ' . self::ENUM_LABELS[$language][in_array((float) $value, [8.5, 7.083], true) ? 'degrees' : 'minutes'];
        }
        if (is_string($value) && isset(self::ENUM_LABELS[$language][$value])) {
            return self::ENUM_LABELS[$language][$value];
        }
        return (string)$value;
    }

    public static function dayLabel(int $day, string $language): string
    {
        return self::DAYS[Lang::canonical($language)][$day] ?? throw new \InvalidArgumentException('Invalid weekday.');
    }
}
