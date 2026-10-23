# Keyboard compatibility

Shabbat 3.1.1 converts keyboards immediately before transport through
`Shabbat\Telegram\KeyboardMarkup`. MadelineProto 8.11.10 uses Telegram Layer 232:
inline keyboards contain `keyboardInlineButtonRow`, `keyboardInlineButton`
and a nested `InlineButtonType`. Reply keyboards use `keyboardButtonRow`,
`keyboardButton` and `ButtonType`.

## Existing settings and templates

Existing Bot API arrays, native keyboards from MadelineProto 8.7 and current
typed native keyboards are accepted. Stored JSON is read without rewriting it.
The adapter preserves callback bytes, URLs, copy text, switch-inline queries,
peer selection, login/WebApp actions, game/payment actions, background colors
and custom emoji IDs. Unknown or mixed action/context formats fail before a
message is sent. Existing native HTTP URL buttons remain supported; new flat
Bot API URL buttons retain the HTTPS/Telegram URL policy. WebApp and login URLs
require HTTPS. Login buttons can omit the optional bot field, using Telegram's
current-bot default just as the official SDK converter does.

Template buttons accept row arrays or recognized `inline_keyboard` /
`replyInlineMarkup` wrappers. Template validation uses the same action rules
as transport. Existing `inherit`, `custom` and `none` modes keep their meaning.

## Transport and navigation

Initial messages, media messages, edits, group acknowledgement counters,
inline search results and BroadcastManager messages use the same adapter.
Reply keyboards, keyboard removal and forced replies remain separate from
inline keyboards. A callback continues editing its recorded message rather than
sending a new menu.

Colors remain `primary`, `danger`, `success` or the equivalent native
`keyboardButtonStyle` flags. Explicit custom icons remain signed-int64 IDs.
The custom-emoji fallback removes only unsupported animation and keeps the
button action, color, count, callback binding and edit/message identity.

## Offline verification

`KeyboardCompatibilityTest` uses the installed official TL serializer and decoder
without creating a Telegram session or calling Telegram. It covers old/current
formats, supported actions, reply-keyboard separation, persisted templates,
`/start` and callback navigation. Other gateway, emoji and template tests cover
media/inline delivery and fallback behavior. A live Telegram smoke test is still
required after deployment to a separate test bot.
