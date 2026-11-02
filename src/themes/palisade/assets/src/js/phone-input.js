/**
 * International phone field for `.js-phone-input` (profile form).
 * Adapted from FOSSBilling's Huraga theme (Apache-2.0).
 */
import intlTelInput from 'intl-tel-input';

export default async function initPhoneInput() {
  const inputs = document.querySelectorAll('.js-phone-input');
  if (inputs.length === 0) {
    return;
  }

  // Country names and the search box in the page language (English otherwise).
  const arabic = document.documentElement.lang.toLowerCase().startsWith('ar');
  const locale = arabic
    ? { countryNameLocale: 'ar', uiTranslations: (await import('intl-tel-input/locale/ar')).default }
    : {};

  const countries = intlTelInput.getAllCountries();
  const supported = new Set(countries.map((country) => country.iso2));

  inputs.forEach((input) => {
    const form = input.closest('form') || document;
    const countryCodeInput = form.querySelector('input[name="phone_cc"], input[name$="[phone_cc]"]');
    if (!countryCodeInput) {
      return;
    }

    const initialCode = countryCodeInput.value.trim();
    const countrySelect = form.querySelector('select[name="country"], select[name$="[country]"]');
    const selectedCountry = (countrySelect?.value || input.dataset.initialCountry || '').toLowerCase();
    // Several countries share a dial code (+1): the client's country first, then the code's main country (priority 0).
    const sharing = countries.filter((country) => country.dialCode === initialCode);
    const initialCountry = sharing.find((country) => country.iso2 === selectedCountry)?.iso2
      || sharing.find((country) => country.priority === 0)?.iso2
      || (supported.has(selectedCountry) ? selectedCountry : '');

    const iti = intlTelInput(input, {
      initialCountry,
      separateDialCode: true,
      ...locale,
      loadUtils: () => import('intl-tel-input/utils'),
    });

    const syncFields = () => {
      const dialCode = iti.getSelectedCountry()?.dialCode || countryCodeInput.value;
      countryCodeInput.value = (input.value.trim() || initialCode) ? dialCode : '';

      if (input.value.trim().startsWith('+') && dialCode) {
        const number = iti.getNumber();
        if (number.startsWith(`+${dialCode}`)) {
          input.value = number.replace(`+${dialCode}`, '').trim();
        }
      }
    };

    countrySelect?.addEventListener('change', () => {
      const country = countrySelect.value.toLowerCase();
      if (supported.has(country)) {
        iti.setSelectedCountry(country);
        syncFields();
      }
    });

    input.addEventListener('countrychange', syncFields);
    input.addEventListener('input', syncFields);
    input.form?.addEventListener('submit', syncFields, true);
    syncFields();
  });
}
