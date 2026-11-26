# Bot commands

Send these **in Telegram**. Menus are shortcuts, not a full permission contract. `[...]` means optional.

## Users

| Command | Where / permission | Effect |
|---|---|---|
| `/start`, `/help` | Private; any user | Home menu/help. |
| `/preferences` | Private; own settings | Personal location/calendar/methods. |
| `/language`, `/lang` | Private; own settings | Personal language; group usage needs group admin. |
| `/groups [search]` | Private; fresh group-admin checks | List/search manageable known groups. |
| `/shabat [YYYY-MM-DD]`, `/shabbat [YYYY-MM-DD]` | Private or registered group; any user | Shabbat/festival times for that context. |
| `/zmanim [YYYY-MM-DD]` | Private or registered group; any user | Daily times. |
| `/daf [YYYY-MM-DD]` | Private or registered group; any user | Daf Yomi. |
| `/omer [YYYY-MM-DD]` | Private or registered group; any user | Count/season for the selected date. |
| `/stats` | Private or group; any user | Public aggregates, without subscriber identities. |
| `/donate` | Private; any user | Optional Stars offers; payment needs Telegram checkout. |
| `/source`, `/license`, `/version` | Private or group; any user | Source offer, licensing/build identity. |

## Group administrators

| Command | Where / permission | Effect |
|---|---|---|
| `/add` | Group; current group admin | Register/reactivate and open setup. |
| `/remove` | Group; current group admin | Deactivate; restoration debt blocks removal. |
| `/settings` | Group; current group admin | Private group settings; see the registration caveat in [group settings](GROUP_SETTINGS.md). |
| `/settings [GROUP_ID]` | Private; current target-group admin | Open target or list groups without ID. |
| `/language`, `/lang` | Group; current group admin | Private selection of group language. |

Advanced **private** forms require the panel's exact token, its owner/scope/revision and current group authority for group targets. Prefer the guided buttons.

| Form | Effect |
|---|---|
| `/city TOKEN QUERY` | Search pending location. |
| `/coordinates TOKEN LATITUDE LONGITUDE IANA_ZONE [NAME]` | Preview explicit coordinates/zone. |
| `/location TOKEN IANA_ZONE` or `/location TOKEN auto` | Prompt for GPS reply; auto requires GeoNames. |
| `/timezone TOKEN QUERY` | Search IANA zones. |
| `/configure TOKEN FORM` | Preview the panel's supported settings form. |
| `/viewdate TOKEN YYYY-MM-DD` | Selected calendar date. |
| `/template TOKEN` + newline + content | Review a group template candidate. |
| `/buttons TOKEN` + newline + keyboard content | Review JSON/legacy keyboard content. |

## Bot managers

All below run **in private**. Bot-manager roles are separate from group administration; view-only roles cannot mutate.

| Command | Required access | Effect |
|---|---|---|
| `/admin` | Bot manager | Administration panel. |
| `/adminstats` | Statistics | Administrative aggregates. |
| `/testzmanim [GROUP_ID]` | Statistics | Paged diagnostics/filter/search and optional private JSON export. |
| `/subscribers` | Subscribers | Known peer/profile directory. |
| `/broadcast` | Broadcast | Drafts/jobs; sending/editing/deleting needs mutation rights and relevant confirmation. |
| `/managers` | Manage managers | Delegated roles within actor's authority. |
| `/systemlinks` | System links | Navigation links, which confer no permissions. |
| `/trackinglinks` | Tracking links | Activation counters/management; changes need mutation access. |
| `/setcommands [LANGUAGE] [SCOPE] [preview]` | Command-menu mutation, even preview | Inspect or update Telegram menus. |
| `/closegroups`, `/opengroups` | Group-operations mutation | Immediately apply a one-hour override to active registered groups; preserve prelocks/report conflicts. Real permission operations. |
| `/restart` | Configured `ADMIN` owner only | Orderly runtime restart. |

Menu languages: `all|he|en|ru|fr|es|pt-br`; scopes: `all|private|operators|groups|group_admins`; defaults: `all all`. Example: `/setcommands ru group_admins preview`. The group administrator menu lists `/settings`. Other supported commands can be typed.

[Administration](ADMIN_PANEL.md) · [Diagnostics](DIAGNOSTICS.md)

## Group command menu upgrade

After installing 3.2.0, an authorized bot operator sends `/setcommands all all` in private. An optional `/setcommands all all preview` shows the operation count without contacting Telegram. Apply again if any updates fail. Restarting the bot alone does not change menus already stored by Telegram.

The group menu publishes `/settings` only to `botCommandScopeChatAdmins`. Telegram includes the group's creator and administrators in this scope. The bot checks current membership when somebody types the command or opens the panel. In private, manually typing `/settings` lists the groups the user can manage. The private command menu keeps its existing `/groups` and `/preferences` entries. The `groups` and `group_admins` selectors both update the administrator menu and clean the old public menus.

For Hebrew, English, Russian, French, Spanish, Portuguese and the unlocalized fallback, the upgrade reads the default and all-group menus. If either contains `/settings`, it removes just that entry, preserves the order and descriptions of other commands, and clears an empty list. Telegram can give precedence to external per-peer or per-member overrides. The project has never published group-specific overrides; independently configured menus in those scopes or other languages need to be managed by their operator. See [Telegram command scopes](https://core.telegram.org/api/bots/commands) and [resetting a scope](https://core.telegram.org/method/bots.resetBotCommands).
