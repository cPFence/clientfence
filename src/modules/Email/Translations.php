<?php

declare(strict_types=1);

namespace ClientFence\Mod\Email;

use ClientFence\i18n;
use ClientFence\InformationException;

/**
 * ClientFence: an email template's language versions, as WHMCS has them. A version is the subject and
 * message of a template in one language. A recipient whose language has a version gets it, everyone
 * else the default text (Email\Service::_parse()). Saved with the template (template_update
 * `translations`) and dropped with it. Reads never fail: before the table exists there are no versions.
 *
 * Rows: email_template_translation (email_template_id, lang, subject, content).
 */
final class Translations
{
    public const TABLE = 'email_template_translation';

    /**
     * A template's versions by language.
     *
     * @return array<string, array{subject: string, content: string}>
     */
    public static function forTemplate(\Pimple\Container $di, int $templateId): array
    {
        try {
            $rows = $di['em']->getConnection()->fetchAllAssociative('SELECT lang, subject, content FROM ' . self::TABLE . ' WHERE email_template_id = ? ORDER BY lang', [$templateId]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['lang']] = ['subject' => (string) $row['subject'], 'content' => (string) $row['content']];
        }

        return $out;
    }

    /**
     * Replaces a template's versions with $versions[lang] = {subject, content}; a language left out
     * loses its version. Each needs an installed language, a subject and a message the email sandbox
     * accepts.
     *
     * @param array<mixed> $versions
     */
    public static function save(\Pimple\Container $di, int $templateId, array $versions): void
    {
        $installed = array_merge(i18n::getLocales(), i18n::getLocales(false, true));
        $system = $di['mod_service']('System');
        $rows = [];
        foreach ($versions as $lang => $version) {
            $lang = (string) $lang;
            if (!in_array($lang, $installed, true)) {
                throw new InformationException('Invalid locale code: :code', [':code' => $lang]);
            }
            $subject = is_array($version) && is_string($version['subject'] ?? null) ? $version['subject'] : '';
            $content = is_array($version) && is_string($version['content'] ?? null) ? $version['content'] : '';
            if (trim($subject) === '' || trim($content) === '') {
                throw new InformationException('Enter a subject and a message for every language version.');
            }
            $system->checkEmailTplSyntax($subject);
            $system->checkEmailTplSyntax($content);
            $rows[$lang] = [$subject, $content];
        }

        $now = date('Y-m-d H:i:s');
        $di['em']->getConnection()->transactional(function ($db) use ($templateId, $rows, $now): void {
            $db->delete(self::TABLE, ['email_template_id' => $templateId]);
            foreach ($rows as $lang => [$subject, $content]) {
                $db->insert(self::TABLE, ['email_template_id' => $templateId, 'lang' => $lang, 'subject' => $subject, 'content' => $content, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
    }

    /** Drops a template's versions (the template was deleted). */
    public static function forget(\Pimple\Container $di, int $templateId): void
    {
        try {
            $di['em']->getConnection()->delete(self::TABLE, ['email_template_id' => $templateId]);
        } catch (\Throwable) {
            // No table yet: nothing to drop.
        }
    }
}
