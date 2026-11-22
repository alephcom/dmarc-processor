<?php

namespace Database\Seeders;

use App\Models\KnownSender;
use Illuminate\Database\Seeder;

/**
 * Starter catalogue of common email senders. MSP admins can edit it under Admin → Known senders.
 *
 * Matching (see App\Intel\SenderIdentifier):
 *  - rdns_suffixes: forward-confirmed reverse DNS ends with one of these
 *  - spf_includes: the provider's own SPF include domain; we fetch its published IP ranges from
 *    live DNS and match IPs inside them (never SPF *results* from reports, which can be forged)
 *  - asns: only networks dedicated to the provider (never shared clouds like AWS/Azure/GCP)
 *  - dkim_domains: reference only (shown to admins)
 * All domain lists match on suffix (sendgrid.net matches em123.sendgrid.net).
 */
class KnownSenderSeeder extends Seeder
{
    public const SENDERS = [
        ['Google Workspace', 'mailbox', [15169], ['google.com'], ['_spf.google.com'], ['gappssmtp.com', 'google.com'], 'https://support.google.com/a/answer/174124'],
        ['Microsoft 365', 'mailbox', [8075], ['outlook.com'], ['spf.protection.outlook.com'], ['onmicrosoft.com'], 'https://learn.microsoft.com/defender-office-365/email-authentication-dkim-configure'],
        ['Yahoo', 'mailbox', [], ['yahoo.com', 'yahoo.net'], [], ['yahoo.com'], null],
        ['Zoho Mail', 'mailbox', [], ['zoho.com', 'zohomail.com', 'zoho.eu'], ['zoho.com', 'zoho.eu'], ['zoho.com', 'zoho.eu'], null],
        ['Fastmail', 'mailbox', [], ['messagingengine.com'], ['spf.messagingengine.com'], ['messagingengine.com', 'fastmail.com'], null],
        ['Proton Mail', 'mailbox', [], ['protonmail.ch', 'proton.ch'], ['_spf.protonmail.ch'], ['protonmail.ch'], null],
        ['iCloud Mail', 'mailbox', [], ['icloud.com', 'apple.com'], ['icloud.com'], ['icloud.com'], null],
        ['Amazon SES', 'esp', [], ['amazonses.com'], ['amazonses.com'], ['amazonses.com'], null],
        ['SendGrid', 'esp', [11377], ['sendgrid.net'], ['sendgrid.net'], ['sendgrid.net', 'sendgrid.info'], null],
        ['Mailgun', 'esp', [], ['mailgun.net'], ['mailgun.org'], ['mailgun.org'], null],
        ['Postmark', 'esp', [], ['mtasv.net'], ['spf.mtasv.net'], ['mtasv.net'], null],
        ['SparkPost', 'esp', [], ['sparkpostmail.com'], ['sparkpostmail.com'], ['sparkpostmail.com'], null],
        ['Mailjet', 'esp', [], ['mailjet.com'], ['spf.mailjet.com'], ['mailjet.com'], null],
        ['SMTP2GO', 'esp', [], ['smtp2go.com'], ['spf.smtp2go.com'], ['smtp2go.com', 'smtp2go.net'], null],
        ['Elastic Email', 'esp', [], ['elasticemail.com'], ['_spf.elasticemail.com'], ['elasticemail.com'], null],
        ['Mailchimp', 'marketing', [2635], ['mcsv.net', 'mcdlv.net', 'rsgsv.net'], ['servers.mcsv.net'], ['mcsv.net', 'mcdlv.net', 'mailchimpapp.net'], null],
        ['Mailchimp Transactional', 'esp', [], ['mandrillapp.com'], ['spf.mandrillapp.com'], ['mandrillapp.com'], null],
        ['Brevo', 'marketing', [], ['sendinblue.com', 'brevo.com'], ['spf.sendinblue.com', 'spf.brevo.com'], ['sendinblue.com', 'brevo.com'], null],
        ['Campaign Monitor', 'marketing', [], ['createsend.com'], ['_spf.createsend.com'], ['createsend.com'], null],
        ['Constant Contact', 'marketing', [], ['constantcontact.com', 'ccsend.com'], ['spf.constantcontact.com'], ['constantcontact.com', 'ccsend.com'], null],
        ['Klaviyo', 'marketing', [], ['klaviyomail.com'], [], ['klaviyomail.com'], null],
        ['HubSpot', 'crm', [], ['hubspotemail.net', 'hubspot.com'], [], ['hubspotemail.net', 'hubspot.com'], null],
        ['Salesforce', 'crm', [14340], ['salesforce.com', 'exacttarget.com'], ['_spf.salesforce.com'], ['salesforce.com', 'exacttarget.com'], null],
        ['Zendesk', 'support', [], ['zendesk.com'], ['mail.zendesk.com'], ['zendesk.com'], null],
        ['Freshdesk', 'support', [], ['freshdesk.com'], ['email.freshdesk.com'], ['freshdesk.com'], null],
        ['Help Scout', 'support', [], ['helpscoutemail.com'], ['helpscoutemail.com'], ['helpscoutemail.com'], null],
        ['Mimecast', 'security', [30031], ['mimecast.com'], ['_netblocks.mimecast.com'], ['mimecast.com'], null],
        ['Proofpoint', 'security', [26211], ['pphosted.com', 'ppe-hosted.com'], [], [], null],
        ['Barracuda', 'security', [], ['barracudanetworks.com'], ['_spf.ess.barracudanetworks.com'], [], null],
        ['Intuit QuickBooks', 'other', [], ['intuit.com'], [], ['intuit.com'], null],
        ['Shopify', 'other', [], ['shopify.com'], ['shops.shopify.com'], ['shopify.com', 'shopifyemail.com'], null],
        ['DocuSign', 'other', [], ['docusign.net'], [], ['docusign.net', 'docusign.com'], null],
    ];

    public function run(): void
    {
        foreach (self::SENDERS as [$name, $category, $asns, $rdns, $spf, $dkim, $docs]) {
            KnownSender::updateOrCreate(['name' => $name], [
                'category' => $category,
                'asns' => $asns,
                'rdns_suffixes' => $rdns,
                'spf_includes' => $spf,
                'dkim_domains' => $dkim,
                'docs_url' => $docs,
            ]);
        }
    }
}
