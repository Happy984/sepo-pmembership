# Sepo Membership

Membership plans, donations and sponsorship for WordPress. Payments are confirmed by you, and the plugin adds a sponsors page and a members area with downloads, links, discussions and suggestions by plan.

![Version](https://img.shields.io/badge/version-1.2.1-0b3d33)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

There is no payment gateway and no outside service. The flow works like this:

1. People pay by bank, mobile money, cash or a payment link.
2. They tell you they have paid.
3. You confirm the payment in the dashboard.

Nothing opens until you confirm.

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Shortcodes and blocks](#shortcodes-and-blocks)
- [Working with Sepo WordPress Bridge](#working-with-sepo-wordpress-bridge)
- [Privacy](#privacy)
- [Uninstalling](#uninstalling)
- [For developers](#for-developers)
- [Changelog](#changelog)
- [License](#license)

## Features

### Plans and joining

- A pricing table with:
  - monthly, quarterly, yearly and one-time prices;
  - a switch between periods;
  - a highlighted plan.
- Joining opens a dialog with the payment details, the payment link, a reference, and a place for proof of payment (a screenshot, PDF or document).
- Free plans open at once. Upgrades start when they are paid.
- Moving to a lower plan is scheduled for the next payment, so members keep what they paid for.
- Members can:
  - cancel and keep access until the end of the time they paid for;
  - change their mind after cancelling;
  - add proof to a waiting payment.
- A payer who wants a waiting payment back asks to withdraw it and gives a reason.
  - You approve the request (the payment is kept, marked withdrawn) or decline it with a reason (the payment waits again).
  - The payer is emailed either way.

### Accountability

- An activity log records every step, with who did it and when:
  - payments sent and proof added;
  - payments confirmed or marked not received;
  - withdrawals asked for, approved or declined;
  - payments recorded or deleted by hand;
  - membership changes made from the dashboard.
- Each payment shows its own history. Payers see what has happened to their waiting payments.
- Log entries cannot be edited or removed. They keep a short summary of the payment, so the record stays whole after a payment is deleted.
- Deleting a payment asks for a reason.
- Members get renewal reminders before a plan runs out, and a notice when it has.

### Giving and sponsors

- A giving form with suggested amounts or any amount, given once or monthly.
  - Monthly givers get a reminder.
  - Nothing is taken automatically.
- **Sponsorship projects** with a goal, a closing date and progress. Each project page shows its giving form and its sponsors.
- A **sponsors page** with:
  - totals;
  - projects to sponsor;
  - sponsors by level;
  - members who chose to be listed.
- Sponsors are added in two ways:
  - by hand, with a logo, a link and the projects they back;
  - from gifts whose givers asked to be listed, placed by what they gave.
- Visitors can give without an account if you allow it. A hidden spam trap and a rate limit protect the form.

### Members area

- **Downloads.** Upload files to a private folder, or choose them from the media library. They are handed out only to members whose plan covers them.
  - Private uploads cannot be opened by their address at all.
  - Media library files keep their own public address, and the editor points this out.
- **Links.** The address is never shown in the page. Members are sent on after their plan is checked.
- **Discussions** with replies.
- **Suggestions** that members vote for. You mark them planned, done or not planned, and can pin them.
- Each section and each resource can need a plan. Members see what a higher plan adds, marked as locked.
- The server checks every download, link and post. Without an active, confirmed plan, nothing from the area reaches the page.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |

## Installation

### Upload the zip (recommended)

1. Download `sepo-membership.zip`.
2. In your WordPress dashboard, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Click **Activate**.

### Upload the folder

1. Unzip the download.
2. Copy the `sepo-membership` folder into `wp-content/plugins/` on your server.
3. Go to **Plugins** and activate **Sepo Membership**.

> [!NOTE]
> GitHub's **Code → Download ZIP** names the folder after the branch, for example `sepo-membership-main`. Rename it to `sepo-membership` before uploading.

## Getting started

1. **Activate the plugin.** It creates these pages:
   - Membership;
   - My membership;
   - Members area;
   - Sponsors;
   - Donate.
2. **Membership → Plans:** set prices and benefits.
3. **Membership → Settings:** add your payment methods, such as bank details, a mobile money number or a payment link address.
   - In a link, `{amount}`, `{currency}` and `{reference}` are filled in for each payment.
4. **Add content.** Add projects, sponsors and resources from the **Membership** menu.
5. **Confirm payments** under **Membership → Payments**. You get an email for each one.

## Shortcodes and blocks

Each shortcode has a matching block.

| Shortcode | What it shows |
|---|---|
| `[sepo_membership_plans tiers="" period="" title="" intro=""]` | The pricing table |
| `[sepo_membership_account]` | The member's own membership page |
| `[sepo_members_area tier="" discussions_tier="" suggestions_tier="" collection="" show="downloads,links,discussions,suggestions" title=""]` | The members area |
| `[sepo_sponsors show="stats,projects,sponsors,members" project="" projects="" title="" intro=""]` | The sponsors page |
| `[sepo_donate project="" amounts="" title="" intro="" progress="yes"]` | A giving form |
| `[sepo_members_only tier="gold" message=""]…[/sepo_members_only]` | Content for members of a plan only |

The members area reads its settings from the page it is on, so place the shortcode or block in the page content.

## Working with Sepo WordPress Bridge

When Sepo WordPress Bridge is also active and has tiers, the plans here use the Bridge's tiers. Then:

- the Bridge's tier gates open for members whose plan here is active and paid;
- its join and account links point at this plugin's pages;
- the member's tier is written to their account.

A tier you gave someone by hand in the Bridge is left alone.

## Privacy

- The plugin adds text to the privacy policy guide.
- It works with WordPress's personal data export and erase tools.
  - Erasing removes the membership, votes, proofs, names and contact details.
  - The amounts and dates of confirmed payments are kept as anonymous accounting records.
- Proof files are shown only to the people who run the membership.

## Uninstalling

**Deactivating** the plugin (**Plugins → Deactivate**) stops it and keeps everything.

**Deleting** the plugin (**Plugins → Deactivate**, then **Delete**) removes the plugin files.

- **By default all data is kept.**
- **To remove it**, first tick **Delete members, payments, board posts, files and settings when the plugin is deleted** under **Membership → Settings** and save, then delete the plugin. This removes:
  - members, payments, discussions, suggestions, votes and the activity log;
  - projects, sponsors, resources and collections;
  - the private files folder;
  - the plans and settings.

The pages the plugin created are left for you to keep or delete.

## For developers

### Filters

| Filter | Purpose |
|---|---|
| `sepo_membership_manage_capability` | Sets the capability for running the membership. Default `manage_options`. |
| `sepo_membership_payment_methods` | Changes the payment methods. |
| `sepo_membership_tiers` | Changes the tiers. |
| `sepo_membership_sponsors` | Changes the sponsors list. |
| `sepo_membership_proof_mimes` | Sets the accepted proof file types. |
| `sepo_membership_resource_mimes` | Sets the accepted resource file types. |

### Actions

- **Payments:**
  - `sepo_membership_payment_recorded`
  - `sepo_membership_payment_confirmed`
  - `sepo_membership_payment_rejected`
- **Memberships:**
  - `sepo_membership_member_changed`
  - `sepo_membership_cancelled`
- **Members area:**
  - `sepo_membership_resource_opened`
  - `sepo_membership_board_created`
  - `sepo_membership_board_replied`
  - `sepo_membership_board_removed`
- **Withdrawals:**
  - `sepo_membership_withdrawal_requested`
  - `sepo_membership_withdrawal_decided`
- **Activity log:** `sepo_membership_logged`

## Changelog

### 1.2.1
- Resources: **Remove this file** removes the private file at once, after you confirm, and says so.
- A download with no file is not listed in the members area, and its address leads nowhere.

### 1.2.0
- Resources: a download can be a file from the media library as well as a private upload. Members still get it through the plan check.

### 1.1.0
- Withdrawing a payment is a request with a reason, approved or declined by the people who run the membership. The payer and you are emailed. Withdrawn payments are kept.
- An activity log (**Membership → Activity log**), a history under each payment, and a trail for payers on their membership page.
- Deleting a payment asks for a reason, which goes in the log.
- Sponsors page: sections, cards and headings are centred.

### 1.0.0
- First release, with:
  - plans and a pricing table;
  - manual and link payments with proof and confirmation;
  - member accounts;
  - giving with goals and projects, and a sponsors page;
  - a members area with gated downloads and links, discussions, and suggestions with voting;
  - Sepo WordPress Bridge tiers;
  - blocks and privacy tools.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
