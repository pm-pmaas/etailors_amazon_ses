# Mautic AWS SES Plugin with SNS Callback

This plugin provides integration between [Mautic 5, 6 and 7](https://www.mautic.org) and [Amazon SES](https://aws.amazon.com/ses/) for sending emails through AWS. It also integrates with [Amazon SNS](https://aws.amazon.com/sns/) for handling email feedback such as bounces and complaints via callbacks. (PHP >= 8.1 required; Note: Mautic 7 itself requires PHP 8.2)

This is a custom plugin inspired by the structure of:
- [mabumusa1/ses-plugin](https://github.com/mabumusa1/ses-plugin)
- [ts-navghane/sparkpost-plugin](https://github.com/ts-navghane/sparkpost-plugin)

Feel free to suggest improvements or provide feedback — this is my first Mautic plugin and community contributions are welcome.

---

## Features
- Send emails through Amazon SES using the API.
- Support for setting AWS regions and optional custom rate limits in the DSN.
- Integration with Amazon SNS to process bounces and complaints via callback.
- Built-in **rate limiting** to honor SES sending quotas.
- Dynamic **batch size** matching the current SES max send rate.
- Auto-fetch and cache your SES sending quota.
- Manual override of rate limit with `ratelimit` DSN option.
- Optional retry handling for partial email failures.
- Opt-in bulk sending with shared SES templates and a durable delivery outbox.

---

## Installation

### 1. Download and Install the Plugin

#### Install manual

1. Download or clone the repository to your Mautic plugins directory:

   ```bash
   cd /path/to/your/mautic/plugins
   git clone https://github.com/yourusername/mautic-aws-ses-plugin.git AmazonSesBundle
   ```

2. Clear Mautic’s cache:
   
   ```bash
   php /path/to/mautic/bin/console cache:clear
   ```

3. Go to **Mautic Settings > Plugins** and click the “Install/Update Plugins” button.

---

#### Install via Composer

If you prefer installing via Composer, run the following command from your Mautic root directory:

```bash
composer require etailors/mautic-amazon-ses:^1.0 -W 
```
Version number 

When upgrading an existing installation to this bulk feature, follow [Bulk setup](#setup) before reloading plugins:
clear the cache, run `mautic:ses:bulk install`, then reload plugins. Create the outbox before any later release upgrade
that activates its migration as well.

Then clear the cache and reload the plugins:

```bash
rm -rf var/cache/*
php bin/console mautic:plugins:reload
```

> **Note:**  
> The plugin will automatically install into `plugins/AmazonSesBundle`.

---

### 2. Configure Amazon SES Settings

1. In Mautic, navigate to **Settings > Email Settings**.
2. Select the newly installed **AWS SES** option.
3. In the **DSN** field, enter your connection string in the following format:

   ```text
   mautic+ses+api://<AWS_ACCESS_KEY>:<AWS_SECRET_KEY>@default?region=<AWS_REGION>&ratelimit=14&sns_topic_arn=<SNS_TOPIC_ARN>
   ```

   - Replace `<AWS_ACCESS_KEY>` and `<AWS_SECRET_KEY>` with your AWS credentials.
   - Replace `<AWS_REGION>` with your AWS region (e.g., `us-east-1`).
   - Optionally set `ratelimit` to override the SES max send rate manually.
   - Set `sns_topic_arn` to the exact SNS topic ARN used for feedback. The callback
     fails closed when no allowed topic is configured. For multiple topics, set
     `amazon_ses_sns_topic_arns` as an array in Mautic's local configuration.

4. Set the region in the `options` parameter, for example:

   ```text
   Label: region   Value: eu-west-1
   ```

![Amazon SES Integration](https://github.com/pm-pmaas/etailors_amazon_ses/blob/1.0.1/Assets/img/mautic-ses-dsn.png)

---

### 3. AWS SES Configuration

1. Ensure that you have verified your domain and email addresses in AWS SES.
2. Set up the appropriate IAM user with permissions to send emails via SES.  
   **Please make sure you create a new IAM user directly via IAM instead of upgrading an SMTP user with the permission: sending via SES API.**

   There are known issues when upgrading SMTP-only users — see: [issue #28](https://github.com/pm-pmaas/etailors_amazon_ses/issues/28).

Your IAM user must have permission to send and get account limits. This example includes bulk sending; replace the
resource placeholders with your sending identity and configuration set. Configure these permissions before assigning
a default configuration set, because it also applies to raw sends. See the [SES IAM reference](https://docs.aws.amazon.com/service-authorization/latest/reference/list_sesv2.html).

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowSesSending",
      "Effect": "Allow",
      "Action": [
        "ses:SendEmail",
        "ses:SendBulkEmail"
      ],
      "Resource": [
        "arn:aws:ses:<region>:<account id>:identity/*",
        "arn:aws:ses:<region>:<account id>:configuration-set/*"
      ]
    },
    {
      "Sid": "AllowSesAccountLimits",
      "Effect": "Allow",
      "Action": "ses:GetAccount",
      "Resource": "*"
    }
  ]
}
```

---

### 4. Available Regions

```
af-south-1
ap-northeast-1
ap-northeast-2
ap-south-1
ap-southeast-1
ap-southeast-2
ca-central-1
eu-central-1
eu-north-1
eu-west-1
eu-west-2
eu-west-3
sa-east-1
us-east-1
us-east-2
us-gov-west-1
us-west-1
us-west-2
```

---

## SNS Callback Configuration

To receive feedback from Amazon SES regarding bounces and complaints, you need to set up Amazon SNS.

1. Go to your [Amazon SNS Dashboard](https://console.aws.amazon.com/sns/v3/home).
2. Create a new **Topic** (or use an existing one) for SES feedback.
3. Subscribe to this topic with an **HTTPS endpoint** using the following URL:

   ```text
   https://<your-mautic-instance-url>/mailer/callback
   ```

4. Ensure your topic sends notifications for bounces, complaints, and delivery status.
5. Ensure the exact topic ARN is configured as `sns_topic_arn` in the mailer DSN.
   The plugin verifies the AWS signature and topic before processing any callback.
6. Keep **Raw message delivery** disabled on the HTTPS subscription. The plugin requires the signed SNS envelope,
   which [raw delivery removes](https://docs.aws.amazon.com/sns/latest/dg/sns-large-payload-raw-message-delivery.html).
7. For classic identity-based SNS notifications, enable **Include original headers** for bounce and complaint notifications on the verified SES identity. Mautic uses the `X-EMAIL-ID` header to attribute feedback to the email statistics. Configuration-set event publishing can use the `X-EMAIL-ID` message tag added by this plugin instead.
8. Test your callback configuration by sending test emails and reviewing Mautic logs to verify feedback is processed correctly.

When upgrading from 1.0.39 or earlier, configure the allowed topic and disable raw SNS delivery before switching
versions: authenticated callbacks have been required since 1.0.40. For a manual plugin installation, install its
Composer dependencies in the Mautic root, including `aws/aws-php-sns-message-validator:^1.10`.

Bulk outbox feedback requires a configuration-set event destination, described below. If the same events are also
published through identity feedback notifications, duplicate callbacks can occur. Consolidate these routes after
verifying the configuration-set destination; keep working bounce and complaint monitoring throughout the change.

---

## Batch Limit & Throttling

To avoid exceeding SES rate limits, this plugin automatically:

- Caches the current `maxSendRate` returned by AWS SES.
- Dynamically adjusts the queue batch size to `ratelimit` × `batchmultiplier`.
- Charges each recipient against a file in Mautic's cache directory, shared by workers using that directory.
  Raw and bulk submissions, including retries, reserve their capacity and wait before sending. Idle time does not
  accumulate a burst allowance, so pacing also applies to the first request. Workers must share the file and have
  permission to write it; separate cache directories do not coordinate their rates.
- Allows overriding the send rate via `ratelimit` DSN option.

The configured rate controls recipient reservations over time. Requests within a group can arrive together, and
network scheduling or other senders using the same SES account can still cause throttling. Bulk requests reduce API
calls and upload volume, but do not increase SES recipient quotas.

---

## Bulk sending with shared SES templates (experimental)

`bulk=off` is the default and uses raw MIME sending. With `bulk=auto`, eligible messages use `SendBulkEmail`: a shared
inline template plus each recipient's resolved Mautic tokens, including tracked links and unsubscribe links.
Attachments, CC/BCC, custom MIME and unsupported content or headers use the raw path. If only a later recipient is
ineligible, that recipient is saved as a raw fallback in the outbox before submission.

### Setup

1. Install `aws/aws-sdk-php:^3.325.1` or newer within that major version, as required by `composer.json` for inline
   templates and replacement headers. A manual installation must update this dependency in the Mautic root too.
2. For an existing installation, clear the cache and explicitly create the outbox before reloading plugins or
   enabling bulk mode:

   ```bash
   php bin/console cache:clear
   php bin/console mautic:ses:bulk install
   php bin/console mautic:plugins:reload
   ```

   This creates missing `ses_bulk_contents` and `ses_bulk_deliveries` tables with Mautic's table prefix, and adds
   missing indexes. It is safe to rerun. This unreleased feature keeps the plugin version at 1.0.41: existing
   installations must use this command; `Version_1_0_42` becomes an upgrade migration only after a release bump.
3. Give the sending IAM user `ses:SendEmail`, `ses:SendBulkEmail` and `ses:GetAccount` as shown above. If resource
   permissions are restricted, include both the sending identity and configuration-set ARNs before applying the set.
4. Create an SES configuration set in the sending region with an enabled SNS event destination for **Sends**,
   **Deliveries**, **Hard bounces**, **Complaints**, **Rejects** and **Rendering failures**. Use the callback topic
   above and [allow SES to publish to it](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-add-event-destination-sns.html).
   Open and click events are not needed by this callback; Mautic tracks those itself.
5. Apply the set as the default on the sending identity, or add `X-SES-CONFIGURATION-SET` with its name to the email's
   custom headers. Check the most specific verified identity: [email-address settings override domain settings](https://docs.aws.amazon.com/ses/latest/dg/creating-identities.html).
6. Add `bulk=auto` to the DSN, then clear Mautic's cache and restart long-running email workers:

   ```text
   mautic+ses+api://<AWS_ACCESS_KEY>:<AWS_SECRET_KEY>@default?region=<AWS_REGION>&ratelimit=80&sns_topic_arn=<SNS_TOPIC_ARN>&bulk=auto
   ```

| DSN option | Values | Default | Purpose |
| --- | --- | --- | --- |
| `bulk` | `off`, `auto` | `off` | Enable shared templates for eligible messages. |
| `bulk_batch_size` | 1–50 | `50` | Maximum recipients per request. |
| `bulk_concurrency` | 1–10 | `2` | Maximum concurrent requests in each group, per sending process or retry run. |

Invalid options are rejected. Effective concurrency is capped at the recipient send rate; each request is also
limited to the rate divided by that concurrency, rounded down, and 1,000,000 serialized bytes. At rate 1, requests
are sequential. Bulk sending reserves capacity for all recipients in a group, waits, then submits its requests
concurrently. It handles all responses before reserving the next group. The shared pacing file coordinates workers
in addition to these per-process limits.

With `bulk=auto`, an email without its own From address keeps the From address Mautic resolved. A `Return-Path`
header supplies SES's feedback forwarding address and must meet SES identity requirements. With `bulk=off`, the
existing envelope-based From behavior remains.

### Recovery and monitoring

```bash
php bin/console mautic:ses:bulk status --email-id=ID --json
php bin/console mautic:ses:bulk retry --limit=1000
php bin/console mautic:ses:bulk sync-stats --limit=1000
php bin/console mautic:ses:bulk prune --older-than=30
```

`status` counts identified recipients saved by bulk mode, including raw fallback rows; omit its options for a
readable summary across emails. `accepted` means SES accepted the request, not that the message was delivered.
SNS events separately record `sent`, `delivered`, `bounced`, `complained`, `rejected` or `rendering_failed`.

Recipients are saved in one transaction before any request. Once saved, the outbox owns recovery: failures are
logged without asking Mautic to resend the entire message. Queue replays use saved content and skip accepted,
rejected, unknown or already claimed rows. Monitor the outbox as well as Mautic's sent count.

| State | Recovery |
| --- | --- |
| `pending` | Recorded but not submitted; `retry` can recover it. |
| `sending` | Claimed by one worker; claims older than 10 minutes become `unknown` at the next send/retry run. |
| `accepted` | SES accepted it; never automatically submitted again. |
| `retry` | Temporary failure; resubmitted when due. |
| `rejected` | Permanent failure or exhausted retries; not automatically resubmitted. |
| `unknown` | A timeout, malformed response or interruption left acceptance uncertain; never automatically resent. |

Ordinary temporary failures allow three retries, due after 60, 120 and 240 seconds; a fourth failure is rejected.
Local failures before submission spend no attempt and become due after 60 seconds. Daily-quota failures are deferred
hourly without spending attempts for 30 hours after enqueueing, then follow ordinary retry limits. Later SNS events
can resolve an unknown outcome. Actual retry times depend on the cron schedule.

Run recovery every five minutes and pruning daily as the same OS user as Mautic's workers:

```cron
*/5 * * * * php /path/to/mautic/bin/console mautic:ses:bulk retry
30 3 * * * php /path/to/mautic/bin/console mautic:ses:bulk prune --older-than=30
```

`retry` also reconciles failed Mautic email statistics. `sync-stats` does that alone for rejected rows and bounce,
reject or rendering-failure events, without adding Do Not Contact entries. Bounce and complaint callbacks retain
their existing Do Not Contact handling. `--limit` accepts 1–10000 recipients per recovery/reconciliation run
(default 1000); for `prune` it controls batch size, not the total number deleted.

The outbox is scoped by SES region, so access-key rotation preserves it. Sharing one database across different AWS
accounts in the same region is unsupported. Drain pending work and queued replays before changing region. Switching
to `bulk=off` disables `retry` but leaves `sync-stats` and `prune` available; drain queued bulk messages first to avoid
replaying them through the raw path.

### Retention

Active rows store recipient addresses, resolved tokens and headers; raw fallbacks also store rendered MIME. These
payloads are cleared when a row becomes accepted, rejected or unknown. Shared templates and bookkeeping remain
until `prune`, which removes terminal rows after 30 days by default, except failures still awaiting reconciliation.
Active rows are never pruned. Deleting a Mautic contact does not delete its outbox records. Choose a retention period
that meets your data-retention requirements and exceeds your queue-replay window: after pruning, a replay can no
longer recognize a recipient as previously sent.

---

## Debugging

- To troubleshoot email sending or SNS callback issues, check Mautic’s logs:

  ```
  /path/to/mautic/var/logs
  ```

- You can also monitor SES and SNS performance in **Amazon CloudWatch**.
- For `AccessDeniedException`, check the denied action/resource, sender identity, configuration-set permissions and
  any IAM conditions. Missing configuration-set access can affect raw sending as well as bulk sending.
- If an accepted bulk recipient has no SES event, check that the intended configuration set was applied, the event
  destination is enabled, its topic policy permits SES publishing, and the HTTPS subscription is confirmed and
  reaching Mautic. A missing event alone does not establish whether delivery succeeded.
- For callback `400` or `403` responses, inspect the payload/logs, raw-delivery setting, allowed topic ARN and SNS
  signature validation; these responses can have more than one cause.

---

## Contributions & Feedback

This is my first Mautic plugin, so I’m open to any feedback or improvements.  
Feel free to open an issue or submit a pull request if you find a bug or have a feature request!

---

## License

This project is licensed under the MIT License — see the [LICENSE](LICENSE) file for details.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release notes. Version 1.0.31 maintains compatibility with PHP 8.1 for Mautic 6 users, while supporting Mautic 7 (which requires PHP 8.2).
