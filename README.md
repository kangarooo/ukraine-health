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
