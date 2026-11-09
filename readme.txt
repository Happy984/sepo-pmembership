=== Sepo Membership ===
Contributors: euneolink
Tags: membership, donations, sponsors, pricing table, members area
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.5
License: GPLv2 or later

Membership plans, donations and sponsorship with payments you confirm by hand, a sponsors page and a members-only area.

== Description ==

Sepo Membership runs on WordPress alone. It uses no payment gateway and no outside service: people pay by bank, mobile money, cash or a payment link, tell you, and you confirm the payment in the dashboard. Nothing opens until you do.

**Plans and joining**

* A pricing table with monthly, quarterly, yearly and one-time prices, a period switch and a highlighted plan.
* Joining opens a dialog with the payment details, the payment link, a reference and proof of payment (screenshot, PDF or document).
* Free plans open at once. Upgrades start when they are paid. Moving to a lower plan is scheduled for the next payment, so members keep what they paid for.
* Members can cancel and keep access until the end of the time they paid for, change their mind, and add proof to a waiting payment.
* A payer who wants a waiting payment back asks to withdraw it and gives a reason. You approve it (the payment is kept, marked withdrawn) or decline it with a reason (it waits again), and the payer is emailed either way.

**Accountability**

* An activity log records every step: payments sent, proof added, confirmed, marked not received, withdrawal asked for, approved or declined, recorded or deleted by hand, and changes made to memberships from the dashboard, with who did it and when.
* Each payment shows its own history in the dashboard, and payers see what has happened to their waiting payments.
* Log entries cannot be edited or removed from the dashboard and keep a short summary of the payment, so the record stays whole after a payment is deleted. Deleting a payment asks for a reason.
* Renewal reminders before a plan runs out and a notice when it has.

**Giving and sponsors**

* A giving form with suggested amounts, any amount, once or monthly (monthly givers get a reminder; nothing is taken automatically).
* Sponsorship projects with a goal, a closing date and progress. Each project page shows its giving form and its sponsors.
* A sponsors page: totals, projects to sponsor, sponsors by level, and members who chose to be listed. Sponsors are added by hand (with a logo, link and the projects they back) or come from gifts whose givers asked to be listed, placed by what they gave.
* Visitors can give without an account (optional), behind a hidden spam trap and a rate limit.

**Members area**

* Downloads uploaded to a private folder, or chosen from the media library, and handed out only to members whose plan covers them. Private uploads cannot be opened by their address at all; media library files keep their own public address, which the resource editor points out.
* Links whose address is never shown in the page: members are sent on after their plan is checked.
* Discussions with replies, and suggestions that members vote for, which you mark planned, done or not planned and can pin.
* Each section and each resource can need a plan; members see what a higher plan adds, marked as locked.
* Everything is checked again on the server for every download, link and post. Without an active, confirmed plan nothing from the area reaches the page.

**Sepo WordPress Bridge**

When Sepo WordPress Bridge is active and has tiers, the plans here use the Bridge's tiers. The Bridge's tier gates then open for members whose plan here is active and paid, its join and account links point at this plugin's pages, and the member's tier is written to their account. A tier you gave by hand in the Bridge is left alone.

== Shortcodes and blocks ==

Each shortcode has a matching block.

* `[sepo_membership_plans tiers="" period="" title="" intro=""]`
* `[sepo_membership_account]`
* `[sepo_members_area tier="" discussions_tier="" suggestions_tier="" collection="" show="downloads,links,discussions,suggestions" title=""]`
* `[sepo_sponsors show="stats,projects,sponsors,members" project="" projects="" title="" intro=""]`
* `[sepo_donate project="" amounts="" title="" intro="" progress="yes"]`
* `[sepo_members_only tier="gold" message=""]…[/sepo_members_only]`

The members area reads its settings from the page it is on, so place the shortcode or block in the page content.

== Setting up ==

1. Activate the plugin. It creates the Membership, My membership, Members area, Sponsors and Donate pages.
2. Membership → Plans: set prices and benefits.
3. Membership → Settings: add your payment methods (bank details, mobile money number, or a payment link address; `{amount}`, `{currency}` and `{reference}` in a link are filled in).
4. Add Projects, Sponsors and Resources from the Membership menu.
5. Confirm payments under Membership → Payments. You get an email for each one.

== Privacy ==

The plugin adds text to the privacy policy guide and works with WordPress's export and erase tools. Erasing removes the membership, votes, proofs, names and contact details; the amounts and dates of confirmed payments are kept as anonymous accounting records. Proof files are only shown to the people who run the membership.

== Filters and actions ==

* `sepo_membership_manage_capability` (default `manage_options`)
* `sepo_membership_payment_methods`, `sepo_membership_tiers`, `sepo_membership_sponsors`
* `sepo_membership_proof_mimes`, `sepo_membership_resource_mimes`
* Actions: `sepo_membership_payment_recorded`, `sepo_membership_payment_confirmed`, `sepo_membership_payment_rejected`, `sepo_membership_member_changed`, `sepo_membership_cancelled`, `sepo_membership_resource_opened`, `sepo_membership_board_created`, `sepo_membership_board_replied`, `sepo_membership_board_removed`, `sepo_membership_withdrawal_requested`, `sepo_membership_withdrawal_decided`, `sepo_membership_logged`

== Screenshots ==

1. Membership plans.
2. The sponsors page.
3. Members and payments in the admin.

== Changelog ==

= 1.2.5 =
* The plugin is by Euneolink Solutions. Its plugin page and author page are edu.sepodesk.com.

= 1.2.4 =
* Readme: screenshots and their captions for the plugin directory.

= 1.2.3 =
* Admin screens: settings shown as cards, lists in a rounded frame, rounded fields and buttons, pill-shaped tabs and filters, and notices on tinted backgrounds.

= 1.2.2 =
* Proofs of payment and member files are stored and served through the WordPress file-system API.
* Numbered placeholders in the file-size message, so translations can reorder them.
* Uninstall clean-up runs inside a function.
* Passes the WordPress Plugin Check: translator comments on every string with placeholders, escaped output, sanitised request data and documented database access to the plugin's own tables.
* Tested up to WordPress 7.1.

= 1.2.1 =
* Resources: "Remove this file" now removes the private file at once, after you confirm, and says so. It no longer waits for the resource to be updated.
* A download with no file is no longer listed in the members area, and its address no longer leads anywhere.

= 1.2.0 =
* Resources: a download can now be a file from the media library, as well as a private upload. Members still get it through the plan check.

= 1.1.0 =
* Withdrawing a payment is now a request with a reason, approved or declined by the people who run the membership, with emails to the payer and to you. Withdrawn payments are kept.
* New activity log (Membership → Activity log), a history under each payment, and a trail for payers on their membership page.
* Deleting a payment asks for a reason, which goes in the log.
* Sponsors page: sections, cards and headings are centred.

= 1.0.0 =
* First release: plans and pricing table, manual and link payments with proof and confirmation, member accounts, giving with goals and projects, sponsors page, members area with gated downloads and links, discussions and suggestions with voting, Sepo WordPress Bridge tiers, blocks, privacy tools.
