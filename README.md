# Ukraine Health – Hero Support (WordPress plugin)

Connects **Heroes** (patients in rehabilitation) with **Supporters** (hairdressers, nail technicians, clothing donors, mobility-aid lenders, masseurs, translators…).

How it works:

1. A Hero picks a hospital and one or more services. Logged-in accounts are optional. A guest only needs a phone number.
2. Requests for the same **service and hospital** are collected into a **group**. The form shows a live count, for example "3/4, 1 more needed".
3. When a group reaches the service's **minimum size**, every Supporter who offers that service at that hospital is notified by email, Telegram or WhatsApp. The coordinator is notified too.
4. The first Supporter to **accept with a date and time** gets the group. This is an atomic claim, so two Supporters can't take the same group. The Heroes get the appointment with a calendar (.ics) file. The Supporter gets the list of Heroes: name, ward, phone and sizes.
5. A Supporter can mark the group **done** or **give it back** to the queue. A Hero can cancel at any time, from their account or from a private link.

## Invite-only network (who invited whom)

To keep wounded Heroes safe, nobody joins without a connection:

- **Every account comes from an invite.** A member creates a one-time invite link in the Supporter area, under **Invite**. The person who uses it is recorded as invited by that member. WordPress's "Anyone can register" switch can stay off.
- **Who can invite whom.** Heroes can invite Heroes. Supporters can invite Heroes and Supporters. Coordinators can also make multi-use invites, for example one for a hospital ward, valid for up to 90 days. Member invites work once and expire after 14 days. A member can have at most 10 unused invites.
- **Guest requests need an invite code too.** Heroes who request help without an account need a code unless the coordinator switches this off in Settings. The request records who invited them.
- **A member is *connected* when their chain of inviters reaches an administrator or coordinator** through accounts that aren't suspended. Only connected Supporters are notified about groups, can accept them and can see Heroes' details. Accounts made any other way, such as the WordPress sign-up form, get no access until a coordinator reviews them.
- **Ukraine Health → Network** shows:
  - the full tree of who invited whom;
  - a *Needs review* list of members who aren't connected;
  - every invite.

  Coordinators can suspend a member, which logs them out, blocks their login and cancels their invites. They can also suspend a member **with their whole branch**, re-attach someone under another member, or make someone a Supporter. When a member is suspended, everyone they invited is frozen until a coordinator re-attaches them.

## Privacy and safety (1.2.0)

A real name, a hospital and a ward together say where a wounded soldier is. So:

- **Nicknames everywhere.** Heroes and Supporters see each other only by nickname. A real name is optional and visible to coordinators only. Login names are random.
- **Contact details only when needed.** A Supporter sees a Hero's ward and phone only for their assigned visit, from *N* hours before it (48 by default) until it is marked done.
- **No personal details in notifications.** Emails and Telegram messages to coordinators and Supporters carry no names, phones or wards, only a link to log in.
- **Automatic deletion.** Contact details are wiped 30 days after a visit is completed or cancelled, or after a request is withdrawn. Only anonymous counts are kept. WordPress's *Export / Erase Personal Data* tools cover the plugin's data, and the suggested privacy-policy text is in *Settings → Privacy*.
- **CSV export is for administrators only.**
- **Supporter approval.** A new Supporter must accept the code of conduct, which you can edit in Settings. A coordinator then approves them before they see any Hero. Supporters a coordinator invites directly are approved automatically.
- **Report a problem.** After a visit, a Hero can privately report the Supporter. The Supporter is put on hold at once, with no new groups and no Heroes' details, until a coordinator clears the report or suspends them (Network → Problem reports).
- **Two-step login** for administrators and coordinators: a 6-digit code is emailed after the password, and a device can be trusted for 30 days. It is **off by default**. Send yourself the test email in Settings first. Emergency switch: `define( 'UH_DISABLE_2FA', true );` in `wp-config.php`.

## Notifications and hospitals

- **Telegram in one tap.** Create a bot with @BotFather, paste its token in Settings and press *Connect Telegram bot*. Heroes, including guests with only a phone number, and Supporters then tap *Get updates on Telegram*, and the chat is linked automatically. Sending `/stop` unlinks it.
- **Reminders.** An hourly job reminds Heroes and the Supporter within 24 hours before a visit, and alerts coordinators when a ready group has waited longer than 3 days.
- **Finding hospitals on the map.** In *Ukraine Health → Hospitals*, search by name in the hospital form. Google's map of the result appears in the form, with no API key needed. Copy the exact name and address into the fields. For a precise map link, paste the place's Google Maps share link into *Google Maps link*. If the pasted link contains coordinates, the pin is saved too. Map links appear in the hospital list and for Supporters. Hospitals without a pasted link get a Google Maps search for their name and address.
- **Hospitals.** Each hospital has visiting hours, shown on the forms, and a contact person. That contact can be emailed when a visit is planned, with the service, the time, the number of patients, the wards and the Supporter's nickname, but no patient names.

## Languages

The plugin's pages are available in **English, Ukrainian and Latvian**. The language is picked from the visitor's choice (links at the top of each page, remembered in a cookie), then the member's profile language, then the browser's language. Browsers set to Russian get Ukrainian. Built-in service names are translated too. Page content written in the editor, such as the homepage, isn't translated.

To update the translations after changing texts:

```bash
wp i18n make-pot . languages/ukraine-health.pot --domain=ukraine-health --exclude=languages
```

Then update `languages/ukraine-health-{uk,lv}.po` and run `wp i18n make-mo languages && wp i18n make-php languages`.

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[uh_request_form]` | Heroes ask for help |
| `[uh_my_requests]` | Heroes check or cancel requests (login or private link) |
| `[uh_supporter]` | Supporter area: groups to help, my schedule, my profile |
| `[uh_register role="supporter"]` | Sign up as Hero, Supporter or both |
| `[uh_stats]` | Public impact counters |
| `[uh_invite]` | Invite someone (also a tab in the Supporter area) |

**Ukraine Health → Settings → Create pages** creates all four pages in one click.

## Admin

- **Dashboard**: key numbers, ready groups with no Supporter (and how many Supporters match each), and recent activity.
- **Groups**: filter by status, view members, assign a Supporter by hand, change status, remove a Hero, and export to CSV.
- **Hospitals / Services**: add, edit and deactivate. Each service has a minimum group size, an optional sizes question, a chat link and minutes per Hero.
- **Settings**: guest access, rate limit, messengers, consent text, coordinator email and Telegram, and WhatsApp through Twilio or the Meta Cloud API.

Roles: `uh_hero`, `uh_supporter`, `uh_coordinator`. Coordinators can manage everything except Settings.

## Where it came from

This plugin merges 15 earlier prototypes of the same idea that ran on test sites:

| Prototype | Site | Kept from it |
|---|---|---|
| Rehab Coordinator 3.1 | life | Hero/Support/Both registration, group per service+hospital, min people, sizes, WhatsApp (Twilio/Meta) + Telegram, provider accepts with date |
| Rehab Needs Manager 1.0 | life2 | needs / groups concept |
| Care Match 0.1 | life3 | withdrawal, ready-at-minimum logic, ICS calendar, activity log |
| Hero-Supporter 1.2 / 1.4 | life3-5 | custom tables, hospitals by country, categories with min participants, withdraw / complete / reopen |
| Service Request System 3.0 (enhanced / merged) | life6c, life7c | Latvian hospital list, default needs catalogue, hospital stats, CSV export, chat routing |
| Service Request System 5.1 (minimal) | 8c | live queue counter, bulk minimums, guest requests by phone, provider stats |
| HeroBridge, Hero Help Platform, MVP 1/2, RehabConnect, Rehab Need, Awake MVP | lifeloop | Hero / Hero Support naming, group-complete trigger with chat link |

Fixed compared with the prototypes:

- Delete links that worked with a plain GET and no nonce.
- Anonymous AJAX submissions with no rate limit.
- Providers could accept groups without a role check, and two of them could accept the same group.
- Groups stored in options.
- Unescaped output and duplicate sign-ups.
- Data loss on uninstall. Data is now dropped only if `UH_DELETE_DATA` is defined.

Fixed in 1.1.0:

- Any logged-in user could tick "I want to help as a Supporter" and see Heroes' names, wards and phone numbers. Becoming a Supporter now needs a Supporter invite or a coordinator's approval.
- Sign-up let anyone choose the Supporter role. Sign-up is now invite-only and limited to the role the invite was for.
- Supporters kept seeing Heroes' contact details after the visit. Completed groups now show names only.
- After a form was submitted, visitors were sent to the homepage, so the confirmation and the private cancel link never showed. They now go back to the form.
- A member can be suspended. Suspended members can't log in, help or invite.

## Requirements

WordPress 6.0+ and PHP 7.4+.
