<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

final class AdminText
{
    private const WORDS = [
        'panel' => ["⚙️ ניהול הבוט","⚙️ Bot administration","⚙️ Управление ботом","⚙️ Administration du bot","⚙️ Administración del bot","⚙️ Administração do bot"],
        'managers' => ["👥 מנהלים והרשאות","👥 Managers and permissions","👥 Менеджеры и права","👥 Gestionnaires et droits","👥 Gestores y permisos","👥 Gestores e permissões"],
        'add' => ["➕ הוספת מנהל","➕ Add manager","➕ Добавить менеджера","➕ Ajouter un gestionnaire","➕ Añadir gestor","➕ Adicionar gestor"],
        'edit' => ["🎛 שינוי הרשאות","🎛 Edit permissions","🎛 Изменить права","🎛 Modifier les droits","🎛 Editar permisos","🎛 Editar permissões"],
        'remove' => ["🗑 הסרת מנהל","🗑 Remove manager","🗑 Удалить менеджера","🗑 Retirer le gestionnaire","🗑 Eliminar gestor","🗑 Remover gestor"],
        'full' => ["הרשאות מלאות","Full permissions","Полные права","Tous les droits","Permisos completos","Permissões completas"],
        'limited' => ["הרשאות נבחרות","Selected permissions","Выбранные права","Droits sélectionnés","Permisos seleccionados","Permissões selecionadas"],
        'view_only' => ["צפייה בלבד","View only","Только просмотр","Lecture seule","Solo lectura","Somente leitura"],
        'immutable' => ["בעלים מוגן מהגדרות השרת","Protected configured owner","Защищённый владелец","Propriétaire protégé","Propietario protegido","Proprietário protegido"],
        'statistics' => ["📊 סטטיסטיקות","📊 Statistics","📊 Статистика","📊 Statistiques","📊 Estadísticas","📊 Estatísticas"],
        'subscribers' => ["👥 מנויים","👥 Subscribers","👥 Подписчики","👥 Abonnés","👥 Suscriptores","👥 Assinantes"],
        'broadcast' => ["📣 מערכת שידור","📣 Broadcasts","📣 Рассылки","📣 Diffusions","📣 Difusiones","📣 Transmissões"],
        'commands' => ["📋 הגדרת תפריט פקודות","📋 Command menu setup","📋 Настройка команд","📋 Configuration des commandes","📋 Configurar comandos","📋 Configurar comandos"],
        'system_links' => ["⚙️ קישורי מערכת","⚙️ System links","⚙️ Системные ссылки","⚙️ Liens système","⚙️ Enlaces del sistema","⚙️ Links do sistema"],
        'tracking_links' => ["ℹ️ קישורי מעקב","ℹ️ Tracking links","ℹ️ Ссылки отслеживания","ℹ️ Liens de suivi","ℹ️ Enlaces de seguimiento","ℹ️ Links de rastreamento"],
        'add_admins' => ["הוספת מנהלים","Add managers","Добавление менеджеров","Ajouter des gestionnaires","Añadir gestores","Adicionar gestores"],
        'manage_admins' => ["ניהול מנהלים והרשאות","Manage managers and permissions","Управление менеджерами и правами","Gérer les gestionnaires et droits","Gestionar gestores y permisos","Gerenciar gestores e permissões"],
        'group_operations' => ["פתיחה וסגירה ידנית","Manual group opening and closing","Ручное открытие и закрытие","Ouverture et fermeture manuelles","Apertura y cierre manual","Abertura e fechamento manual"],
        'system_operations' => ["תפעול ותחזוקת המערכת","System maintenance","Обслуживание системы","Maintenance du système","Mantenimiento del sistema","Manutenção do sistema"],
        'save' => ["💾 שמירה","💾 Save","💾 Сохранить","💾 Enregistrer","💾 Guardar","💾 Salvar"],
        'back' => ["⬅️ חזרה","⬅️ Back","⬅️ Назад","⬅️ Retour","⬅️ Volver","⬅️ Voltar"],
        'cancel' => ["ביטול","Cancel","Отмена","Annuler","Cancelar","Cancelar"],
        'next' => ["הבא ➡️","Next ➡️","Далее ➡️","Suivant ➡️","Siguiente ➡️","Próximo ➡️"],
        'previous' => ["⬅️ הקודם","⬅️ Previous","⬅️ Назад","⬅️ Précédent","⬅️ Anterior","⬅️ Anterior"],
        'refresh' => ["🔄 רענון","🔄 Refresh","🔄 Обновить","🔄 Actualiser","🔄 Actualizar","🔄 Atualizar"],
        'confirm' => ["✅ אישור","✅ Confirm","✅ Подтвердить","✅ Confirmer","✅ Confirmar","✅ Confirmar"],
        'confirm_remove' => ["להסיר את הגישה לניהול הבוט?","Remove access to bot administration?","Удалить доступ к управлению ботом?","Retirer l'accès à l'administration du bot ?","¿Eliminar acceso a la administración del bot?","Remover acesso à administração do bot?"],
        'prompt_admin' => ["שלחו מזהה Telegram מספרי של המנהל החדש, או העבירו הודעה ממנו אם המזהה גלוי. בחרו הרשאות ושמרו.","Send the new manager's numeric Telegram ID, or forward a message with visible sender ID. Select permissions and save.","Отправьте числовой Telegram ID менеджера или перешлите сообщение с видимым ID отправителя. Выберите права и сохраните.","Envoyez l'ID Telegram numérique du gestionnaire ou transférez un message avec un ID visible. Choisissez les droits et enregistrez.","Envía el ID numérico de Telegram del gestor o reenvía un mensaje con ID visible. Elige permisos y guarda.","Envie o ID numérico do Telegram do gestor ou encaminhe uma mensagem com ID visível. Escolha permissões e salve."],
        'prompt_tracking' => ["שלחו פרמטר ייחודי: 1-64 אותיות באנגלית, ספרות, מקף או קו תחתון. אפשר להוסיף שם אחרי רווח. לדוגמה: community קהילת השכונה","Send a unique parameter: 1-64 English letters, digits, hyphens or underscores. Add an optional label after a space. Example: community Neighborhood community","Отправьте уникальный параметр: 1-64 латинских буквы, цифры, дефисы или подчёркивания. После пробела можно добавить название.","Envoyez un paramètre unique : 1-64 lettres latines, chiffres, tirets ou traits de soulignement. Ajoutez un nom après un espace.","Envía un parámetro único: 1-64 letras inglesas, dígitos, guiones o guiones bajos. Puedes añadir un nombre tras un espacio.","Envie um parâmetro único: 1-64 letras em inglês, números, hífens ou sublinhados. Adicione um nome após um espaço."],
        'create_tracking' => ["➕ יצירת קישור","➕ Create link","➕ Создать ссылку","➕ Créer un lien","➕ Crear enlace","➕ Criar link"],
        'destination' => ["תפריט יעד","Destination menu","Меню назначения","Menu de destination","Menú de destino","Menu de destino"],
        'reset' => ["♻️ איפוס נתונים","♻️ Reset statistics","♻️ Сбросить статистику","♻️ Réinitialiser les statistiques","♻️ Reiniciar estadísticas","♻️ Redefinir estatísticas"],
        'delete' => ["🗑 מחיקת קישור","🗑 Delete link","🗑 Удалить ссылку","🗑 Supprimer le lien","🗑 Eliminar enlace","🗑 Excluir link"],
        'confirm_tracking' => ["הפעולה תשנה את קישור המעקב או את הנתונים שלו. להמשיך?","This changes the tracking link or its statistics. Continue?","Это изменит ссылку или её статистику. Продолжить?","Cette action modifie le lien ou ses statistiques. Continuer ?","Esto modifica el enlace o sus estadísticas. ¿Continuar?","Isso modifica o link ou suas estatísticas. Continuar?"],
        'analytics_note' => ["נספרות הפעלות /start תקינות בלבד. מזהי מבקרים נשמרים כחתימות עם מפתח לספירה ייחודית, בלי היסטוריית לחיצות.","Only valid /start activations count. Visitor IDs are stored as keyed hashes for unique counts, without click histories.","Учитываются только запуски /start. ID посетителей сохраняются как хеши с ключом без истории кликов.","Seuls les lancements /start sont comptés. Les ID sont hachés avec une clé, sans historique des clics.","Solo se cuentan activaciones /start. Los ID se guardan como hashes con clave, sin historial de clics.","Apenas ativações /start são contadas. IDs são armazenados como hashes com chave, sem histórico de cliques."],
        'total' => ["סה״כ הפעלות","Total activations","Всего запусков","Activations totales","Activaciones totales","Total de ativações"],
        'unique' => ["משתמשים ייחודיים","Unique users","Уникальные пользователи","Utilisateurs uniques","Usuarios únicos","Usuários únicos"],
        'repeat' => ["הפעלות חוזרות","Repeat activations","Повторные запуски","Activations répétées","Activaciones repetidas","Ativações repetidas"],
        'first' => ["הפעלה ראשונה","First activation","Первый запуск","Première activation","Primera activación","Primeira ativação"],
        'last' => ["הפעלה אחרונה","Last activation","Последний запуск","Dernière activation","Última activación","Última ativação"],
        'created' => ["נוצר","Created","Создано","Créé","Creado","Criado"],
        'days' => ["פעילות ב-7 הימים האחרונים (UTC)","Activity in the last 7 days (UTC)","Активность за 7 дней (UTC)","Activité des 7 derniers jours (UTC)","Actividad de los últimos 7 días (UTC)","Atividade dos últimos 7 dias (UTC)"],
        'empty' => ["אין עדיין נתונים","No data yet","Данных пока нет","Aucune donnée","Aún no hay datos","Ainda não há dados"],
        'home' => ["✡️ תפריט ראשי","✡️ Main menu","✡️ Главное меню","✡️ Menu principal","✡️ Menú principal","✡️ Menu principal"],
        'preferences' => ["⚙️ ההעדפות שלי","⚙️ My preferences","⚙️ Мои настройки","⚙️ Mes préférences","⚙️ Mis preferencias","⚙️ Minhas preferências"],
        'language' => ["🌐 שפה","🌐 Language","🌐 Язык","🌐 Langue","🌐 Idioma","🌐 Idioma"],
        'groups' => ["👥 הקבוצות שלי","👥 My groups","👥 Мои группы","👥 Mes groupes","👥 Mis grupos","👥 Meus grupos"],
        'shabbat' => ["🕯 זמני שבת וחג","🕯 Shabbat and holidays","🕯 Шаббат и праздники","🕯 Chabbat et fêtes","🕯 Shabat y fiestas","🕯 Shabat e festas"],
        'zmanim' => ["☀️ זמני היום","☀️ Daily zmanim","☀️ Времена дня","☀️ Horaires du jour","☀️ Horarios del día","☀️ Horários do dia"],
        'daf' => ["📖 דף יומי","📖 Daf Yomi","📖 Даф йоми","📖 Daf Yomi","📖 Daf Yomi","📖 Daf Yomi"],
        'omer' => ["🌾 ספירת העומר","🌾 Counting the Omer","🌾 Счёт Омера","🌾 Compte de l'Omer","🌾 Cuenta del Ómer","🌾 Contagem do Ômer"],
        'help' => ["ℹ️ מידע ועזרה","ℹ️ Information and help","ℹ️ Информация и помощь","ℹ️ Informations et aide","ℹ️ Información y ayuda","ℹ️ Informações e ajuda"],
        'support' => ["⭐ תמיכה ותרומה","⭐ Support and donate","⭐ Поддержка и пожертвование","⭐ Soutien et don","⭐ Apoyo y donación","⭐ Apoio e doação"],
        'source' => ["💻 קוד מקור","💻 Source code","💻 Исходный код","💻 Code source","💻 Código fuente","💻 Código-fonte"],
        'license' => ["ℹ️ רישיון","ℹ️ License","ℹ️ Лицензия","ℹ️ Licence","ℹ️ Licencia","ℹ️ Licença"],
        'version' => ["ℹ️ גרסת הבוט","ℹ️ Bot version","ℹ️ Версия бота","ℹ️ Version du bot","ℹ️ Versión del bot","ℹ️ Versão do bot"],
        'commands_note' => ["פקודות לפי ההרשאות שלכם. פתיחת קישור אינה משנה הגדרות.","Commands depend on your permissions. Opening a link does not change settings.","Команды зависят от ваших прав. Открытие ссылки не меняет настройки.","Les commandes dépendent de vos droits. Ouvrir un lien ne modifie pas les réglages.","Los comandos dependen de tus permisos. Abrir un enlace no cambia ajustes.","Os comandos dependem das suas permissões. Abrir um link não muda configurações."],
    ];
    public static function text(string $language, string $key): string
    {
        $index = array_search(Lang::canonical($language), Lang::CODES, true);
        return self::WORDS[$key][$index] ?? throw new \InvalidArgumentException('Missing admin translation ' . $key);
    }
    public static function keys(): array
    {
        return array_keys(self::WORDS);
    }
}
