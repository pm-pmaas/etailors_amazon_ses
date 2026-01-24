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
   mautic+ses+api://<AWS_ACCESS_KEY>:<AWS_SECRET_KEY>@default?region=<AWS_REGION>&ratelimit=14
   ```

   - Replace `<AWS_ACCESS_KEY>` and `<AWS_SECRET_KEY>` with your AWS credentials.
   - Replace `<AWS_REGION>` with your AWS region (e.g., `us-east-1`).
   - Optionally set `ratelimit` to override the SES max send rate manually.

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

Your IAM user must have permission to send and get account limits. Here’s an example policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowSending",
      "Effect": "Allow",
      "Action": [
        "ses:SendEmail",
        "ses:SendBulkEmail"
      ],
      "Resource": "arn:aws:ses:*:<account id>:identity/*"
    },
    {
      "Sid": "AllowGetAccount",
      "Effect": "Allow",
      "Action": [
        "ses:GetAccount"
      ],
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
5. Test your callback configuration by sending test emails and reviewing Mautic logs to verify feedback is processed correctly.

---

## Batch Limit & Throttling

To avoid exceeding SES rate limits, this plugin automatically:

- Caches the current `maxSendRate` returned by AWS SES.
- Dynamically adjusts the batch size to match that rate.
- Throttles outgoing email API calls using `usleep()`.
- Allows overriding the send rate via `ratelimit` DSN option.

This prevents common errors like `TooManyRequests` while maximizing throughput.

---

## Debugging

- To troubleshoot email sending or SNS callback issues, check Mautic’s logs:

  ```
  /path/to/mautic/var/logs
  ```

- You can also monitor SES and SNS performance in **Amazon CloudWatch**.

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
