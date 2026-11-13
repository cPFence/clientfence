<?php

declare(strict_types=1);

/**
 * Writes src/modules/Clientfence/pack-manifest.json, which ClientFence system health compares the
 * install with: the FOSSBilling version it is based on, the hash of every pack file, the markers of the core
 * changes (tools/core-patch-markers.json: text only the changed file contains) and the .htaccess
 * blocks. Run by the Dockerfile (release build) after the themes are built.
 *
 *   php tools/build-pack-manifest.php [root]   root: the tree to scan (default: src)
 */
$root = rtrim($argv[1] ?? dirname(__DIR__) . '/src', '/');
$manifest = 'modules/Clientfence/pack-manifest.json';
// The pack's files: these folders and files, at their install paths.
$paths = [
    'locale/he_IL/LC_MESSAGES/messages.mo',
    'library/Payment/Adapter/CustomEgp.php',
    'library/Payment/Adapter/PayPalAutoPay.php',
    'library/Payment/Adapter/PayTabs.php',
    'library/Payment/Adapter/Eway.php',
    'library/Registrar/Adapter/Dynadot.php',
    'library/Registrar/Adapter/Enom.php',
    'library/Registrar/Adapter/Inwx.php',
    'library/Registrar/Adapter/Namesilo.php',
    'library/Registrar/Adapter/Openprovider.php',
    'library/Registrar/Adapter/Synergywholesale.php',
    'library/Registrar/Adapter/Opensrs.php',
    'library/Registrar/Adapter/Realtimeregister.php',
    'library/Registrar/Adapter/Connectreseller.php',
    'library/Registrar/TldPrices.php',
    'library/Registrar/TransferStatus.php',
    'modules/Servicedomain/PriceSync.php',
    'modules/Servicedomain/DomainSync.php',
    'modules/Servicedomain/templates/email/mod_servicedomain_transfer_completed.html.twig',
    'modules/Servicedomain/templates/admin/mod_servicedomain_prices.html.twig',
    'library/Server/Manager/Enhance.php',
    'library/ClientFence/Twig/Extension/RtlDateExtension.php',
    'library/ClientFence/Doctrine/RowLock.php',
    'library/ClientFence/Http/PaymentReturn.php',
    'library/ClientFence/IpSeats.php',
    'library/Server/PackageExistsException.php',
    'clientfence-security-headers.php',
    'modules/Clientfence',
    'modules/Security/templates/admin/mod_security_settings.html.twig',
    'modules/Order/templates/client/mod_order_addons.html.twig',
    // Moved from the ClientFence module into its home module.
    'modules/Invoice/Receipts.php',
    'modules/Invoice/SavedCards.php',
    'modules/Invoice/templates/admin/mod_invoice_settings_cards.html.twig',
    'modules/Invoice/templates/client/mod_invoice_payment_methods.html.twig',
    'modules/Invoice/templates/email/mod_invoice_card_failed.html.twig',
    'modules/Servicedomain/templates/client/mod_servicedomain_lookup_captcha.html.twig',
    'modules/Invoice/templates/admin/mod_invoice_settings_receipts.html.twig',
    'modules/Support/TicketFiles.php',
    'modules/Support/TicketSecrets.php',
    'modules/Support/templates/admin/mod_support_ticket_secrets.html.twig',
    'modules/Support/templates/admin/mod_support_settings_ticket_files.html.twig',
    'modules/Support/templates/admin/mod_support_ticket_message_files.html.twig',
    'modules/Support/templates/admin/mod_support_ticket_reply_files.html.twig',
    'modules/Support/MailImport.php',
    'modules/Support/TicketMerge.php',
    'modules/Support/TicketBulk.php',
    'modules/Support/TicketPriority.php',
    'modules/Support/TicketAssign.php',
    'modules/Support/TicketEscalation.php',
    'modules/Support/NetworkIssues.php',
    'modules/Support/templates/admin/mod_support_network_issues.html.twig',
    'modules/Support/templates/admin/mod_support_network_issue.html.twig',
    'modules/Support/templates/admin/mod_support_settings_network.html.twig',
    'modules/Support/templates/email/mod_support_network_issue.html.twig',
    'modules/Invoice/Reports.php',
    'modules/Invoice/Statement.php',
    'modules/Invoice/StatementPdf.php',
    'modules/Invoice/RtlPdf.php',
    'modules/Invoice/Quotes.php',
    'modules/Invoice/QuotePdf.php',
    'modules/Invoice/InvoicePdf.php',
    'modules/Invoice/templates/admin/mod_invoice_quotes.html.twig',
    'modules/Invoice/templates/admin/mod_invoice_quote.html.twig',
    'modules/Invoice/templates/email/mod_invoice_quote.html.twig',
    'modules/Invoice/templates/admin/mod_invoice_reports.html.twig',
    'modules/Invoice/templates/admin/mod_invoice_report.html.twig',
    'modules/Support/TicketFeedback.php',
    'modules/Support/HiddenHelpdesks.php',
    'modules/Support/HiddenKbCategories.php',
    'modules/Support/TicketLimit.php',
    'modules/Servicelicense/CheckLog.php',
    'modules/Servicelicense/Bans.php',
    'modules/Servicelicense/WhmcsCheck.php',
    'modules/Servicelicense/Units.php',
    'modules/Servicelicense/Controller/Client.php',
    'modules/Servicelicense/templates/admin/mod_servicelicense_settings_panel.html.twig',
    'modules/Support/templates/admin/mod_support_ratings.html.twig',
    'modules/Support/templates/email/mod_support_ticket_feedback_request.html.twig',
    'modules/Support/templates/admin/mod_support_tickets_bulk.html.twig',
    // The help desks page for staff who manage help desks without the Support settings (QA 2026-10-20).
    'modules/Support/templates/admin/mod_support_helpdesks.html.twig',
    'modules/Support/templates/admin/partial_support_helpdesks.html.twig',
    'modules/Support/templates/admin/mod_support_ticket_priority.html.twig',
    'modules/Email/ReplyTo.php',
    'modules/Email/Translations.php',
    'modules/Email/templates/admin/partial_email_template_version.html.twig',
    'modules/Product/Translations.php',
    'modules/Product/templates/admin/mod_product_translations.html.twig',
    'modules/Support/Mail',
    'modules/Support/templates/admin/mod_support_helpdesk_mail.html.twig',
    'modules/Support/templates/admin/mod_support_settings_mail.html.twig',
    'modules/Support/templates/admin/mod_support_settings_escalation.html.twig',
    'modules/Support/templates/admin/mod_support_mail_log.html.twig',
    'modules/Support/templates/email/mod_support_mail_rejected.html.twig',
    'modules/Servicehosting/Panel',
    'modules/Servicehosting/Controller/Client.php',
    'modules/Servicehosting/templates/admin/mod_servicehosting_settings_panels.html.twig',
    'modules/Servicehosting/templates/admin/mod_servicehosting_settings_plans.html.twig',
    'modules/Servicehosting/templates/client/mod_servicehosting_accounts.html.twig',
    'modules/Servicehosting/templates/client/mod_servicehosting_account.html.twig',
    'modules/Servicehosting/templates/client/mod_servicehosting_account_usage.html.twig',
    'modules/Servicehosting/templates/client/mod_servicehosting_account_domains.html.twig',
    'modules/Servicehosting/templates/client/mod_servicehosting_account_email.html.twig',
    'modules/Client/Users.php',
    'modules/Client/Notes.php',
    'modules/Client/SupportPin.php',
    'modules/Client/Files.php',
    'modules/Client/templates/admin/mod_client_summary_cards.html.twig',
    'modules/Client/TwoFactor.php',
    'modules/Client/Google.php',
    'modules/Client/templates/client/mod_client_users_page.html.twig',
    'modules/Client/templates/client/mod_client_user_profile.html.twig',
    'modules/Client/templates/client/mod_client_user_invite.html.twig',
    'modules/Client/templates/client/mod_client_user_reset.html.twig',
    'modules/Client/templates/client/mod_client_no_access.html.twig',
    'modules/Client/templates/client/mod_client_twofactor_settings.html.twig',
    'modules/Client/templates/client/mod_client_twofactor_code.html.twig',
    'modules/Staff/templates/admin/mod_staff_login_code.html.twig',
    'modules/Client/templates/client/mod_client_google_link.html.twig',
    'modules/Client/templates/client/mod_client_google_profile.html.twig',
    'modules/Client/templates/client/widgets/mod_client_google_button.html.twig',
    'modules/Client/templates/client/widgets/mod_client_google_signup_button.html.twig',
    'modules/Client/templates/admin/mod_client_settings_google.html.twig',
    'modules/Client/templates/admin/widgets/mod_client_users_tab.html.twig',
    'modules/Client/templates/admin/widgets/mod_client_users_pane.html.twig',
    'modules/Client/templates/email/mod_client_user_invite.html.twig',
    'modules/Client/templates/email/mod_client_user_password_reset.html.twig',
    'modules/Order/ActionFailures.php',
    'modules/Order/Proration.php',
    'modules/Order/Upgrades.php',
    'modules/Order/templates/admin/mod_order_upgrades.html.twig',
    'modules/Order/templates/admin/mod_order_failed_actions.html.twig',
    'modules/Order/templates/admin/mod_order_failed_notice.html.twig',
    'modules/Order/BulkPrices.php',
    'modules/Staff/templates/email/mod_staff_clientfence_update.html.twig',
    'modules/Order/templates/admin/mod_order_bulk_prices.html.twig',
    'modules/Order/templates/email/mod_order_price_changed.html.twig',
    'modules/Order/templates/email/mod_order_ip_request.html.twig',
    'modules/Servicelicense/templates/email/mod_servicelicense_usage_missing.html.twig',
    'modules/Order/templates/admin/mod_order_ip_seats.html.twig',
    'modules/Invoice/EuVat.php',
    'modules/Invoice/PendingPayments.php',
    'modules/Invoice/templates/email/mod_invoice_refunded.html.twig',
    'modules/Order/CancelRequests.php',
    'modules/Order/templates/admin/mod_order_cancel_request_notice.html.twig',
    'modules/Order/templates/email/mod_order_cancel_request.html.twig',
    'modules/Order/templates/email/mod_order_subscription_stopped.html.twig',
    'modules/Servicedomain/templates/admin/mod_servicedomain_domain_fields.html.twig',
    'modules/Servicedomain/templates/client/mod_servicedomain_domain_fields.html.twig',
    'modules/Servicehosting/templates/client/mod_servicehosting_account_backups.html.twig',
    'modules/Staff/templates/email/mod_staff_order_cancel_request.html.twig',
    'modules/Staff/templates/email/mod_staff_order_action_failed.html.twig',
    'modules/Staff/templates/email/mod_staff_tld_price_changes.html.twig',
    'modules/Staff/templates/email/mod_staff_ticket_assigned.html.twig',
    'modules/Staff/templates/email/mod_staff_ticket_escalated.html.twig',
    'modules/Staff/templates/email/mod_staff_quote_answered.html.twig',
    'themes/admin_default/html_custom',
    'themes/admin_default/assets/clientfence',
];

$files = [];
foreach ($paths as $path) {
    $full = "{$root}/{$path}";
    if (is_file($full)) {
        $files[$path] = hash_file('sha256', $full);

        continue;
    }
    if (!is_dir($full)) {
        fwrite(STDERR, "{$path}: missing\n");
        exit(1);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if ($relative !== $manifest) {
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
}
ksort($files);

$markers = json_decode((string) file_get_contents(__DIR__ . '/core-patch-markers.json'), true, 512, JSON_THROW_ON_ERROR);
file_put_contents("{$root}/{$manifest}", json_encode([
    'base_version' => '0.8.8',
    'files' => $files,
    'patches' => $markers,
    'htaccess' => ['CLIENTFENCE NO CLIENT DOMAIN CHANGE', 'CLIENTFENCE OLD HOSTING PAGE', 'CLIENTFENCE PRIVATE FILES'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'wrote ' . $manifest . ' (' . count($files) . " files)\n";
