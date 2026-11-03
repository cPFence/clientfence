# Translations

The translations are GNU gettext files, one folder per locale. Each `LC_MESSAGES/messages.mo`
is built from two catalogues:

- `translations/LANG/fossbilling.po`: FOSSBilling's translation as released with 0.8.8 (see
  `translations.md` and `LICENSE.md`).
- `src/themes/palisade/locale/LANG/clientfence.po`: every text FOSSBilling's catalogue lacks
  (ClientFence's own texts and FOSSBilling texts it never translated), plus fixes. It wins where
  both have a text.

Hebrew (`he_IL`) has a complete standalone ClientFence catalogue, without an upstream catalogue.
The language menu lists installed locales, including regional English, Portuguese and Chinese variants.

`src/themes/palisade/locale/clientfence.pot` lists every text the code translates.

## Translating

Translations are written by people, not by a translation service.

    python3 tools/locales.py extract        # after changing texts in the code
    python3 tools/locales.py status         # how many texts each language still lacks
    python3 tools/locales.py starter LANG   # add the missing texts to LANG's clientfence.po
    python3 tools/locales.py todo LANG 100  # the next 100 untranslated texts, as JSON
    python3 tools/locales.py fill LANG FILE # write translations ({"text": "translation"})
    python3 tools/locales.py check LANG     # placeholders, spacing, nothing left untranslated
    python3 tools/locales.py build LANG     # write LANG's messages.mo; commit it

Keep every placeholder exactly (`%count%`, `:name`, `%s`, HTML tags such as `<strong>`),
keep leading and trailing spaces, and use the words FOSSBilling's catalogue for that language
already uses for the same things. Product names (ClientFence, FOSSBilling, Enhance, Stripe…)
stay as they are. Needs Python 3 and GNU gettext.
