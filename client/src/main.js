import './main.css';
import Alpine from 'alpinejs';

/**
 * Progressive enhancement only – every form works without JavaScript.
 */

// --- Conditional fields: <input data-show-if="ApplicantType=Individual">
function initConditionalFields(root = document) {
  root.querySelectorAll('[data-show-if]').forEach((el) => {
    const [name, expected] = String(el.dataset.showIf).split('=');
    const holder = el.closest('.field') ?? el;
    const radios = root.querySelectorAll(`[name="${name}"]`);
    const update = () => {
      const checked = [...radios].find((r) => (r.type === 'radio' ? r.checked : true));
      const visible = checked ? checked.value === expected : true;
      holder.hidden = !visible;
    };
    radios.forEach((r) => r.addEventListener('change', update));
    update();
  });
}

// --- JMBG / ID live checksum hint (server validates authoritatively)
const WEIGHTS = [7, 6, 5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
function checksumValid(digits) {
  if (!/^\d{13}$/.test(digits)) return false;
  const sum = WEIGHTS.reduce((acc, w, i) => acc + Number(digits[i]) * w, 0);
  let m = 11 - (sum % 11);
  if (m === 10) return false;
  if (m === 11) m = 0;
  return m === Number(digits[12]);
}

function initIdFields(root = document) {
  root.querySelectorAll('[data-egov-id-field]').forEach((input) => {
    const typeName = input.dataset.typeField || 'ApplicantType';
    const hint = document.createElement('p');
    hint.className = 'egov-live-hint';
    hint.setAttribute('aria-live', 'polite');
    input.insertAdjacentElement('afterend', hint);

    const check = () => {
      const digits = input.value.replace(/\D+/g, '');
      const type = root.querySelector(`[name="${typeName}"]:checked`)?.value ?? 'Individual';
      if (digits.length === 0) {
        hint.textContent = '';
        input.removeAttribute('aria-invalid');
        return;
      }
      let message = '';
      if (digits.length !== 13) {
        message = `Uneseno ${digits.length} od 13 cifara.`;
      } else if (type === 'Individual' && !checksumValid(digits)) {
        message = 'JMBG izgleda neispravno – provjerite cifre.';
      } else if (type === 'Company' && digits[0] !== '4') {
        message = 'ID broj pravnog lica počinje cifrom 4.';
      }
      hint.textContent = message;
      if (message) input.setAttribute('aria-invalid', 'true');
      else input.removeAttribute('aria-invalid');
    };
    input.addEventListener('input', check);
    root.querySelectorAll(`[name="${typeName}"]`).forEach((r) => r.addEventListener('change', check));
  });
}

// --- File input: show name/size and block oversized files client-side
function initFileInputs(root = document) {
  root.querySelectorAll('.egov-upload-form input[type="file"]').forEach((input) => {
    const info = document.createElement('p');
    info.className = 'egov-live-hint';
    info.setAttribute('aria-live', 'polite');
    input.insertAdjacentElement('afterend', info);
    const max = Number(document.querySelector('[data-egov-max-mb]')?.dataset.egovMaxMb || 10) * 1024 * 1024;
    input.addEventListener('change', () => {
      const file = input.files?.[0];
      if (!file) {
        info.textContent = '';
        return;
      }
      const mb = (file.size / 1024 / 1024).toFixed(1).replace('.', ',');
      if (file.size > max) {
        info.textContent = `Datoteka je prevelika (${mb} MB).`;
        input.value = '';
        return;
      }
      info.textContent = `${file.name} · ${mb} MB`;
    });
  });
}

// --- Prevent double submit (uploads on slow mobile connections)
function initSubmitGuard(root = document) {
  root.querySelectorAll('form.egov-form, form.egov-case-actions').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const button = event.submitter;
      if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
      }
      form.dataset.submitting = '1';
      if (button) {
        button.setAttribute('aria-busy', 'true');
        // keep the clicked button's name/value in the payload
        setTimeout(() => (button.disabled = true), 0);
      }
    });
  });
}

// --- Copy tracking code
Alpine.data('copyCode', () => ({
  copied: false,
  async copy(text) {
    try {
      await navigator.clipboard.writeText(text);
      this.copied = true;
      setTimeout(() => (this.copied = false), 2000);
    } catch {
      /* clipboard not available */
    }
  },
}));

document.addEventListener('DOMContentLoaded', () => {
  initConditionalFields();
  initIdFields();
  initFileInputs();
  initSubmitGuard();
  document.querySelectorAll('[data-egov-copy]').forEach((el) => {
    el.setAttribute('x-data', 'copyCode');
    el.setAttribute('role', 'button');
    el.setAttribute('tabindex', '0');
    el.setAttribute('title', 'Kopiraj');
    el.setAttribute('@click', `copy('${el.textContent.trim()}')`);
    el.setAttribute('@keydown.enter', `copy('${el.textContent.trim()}')`);
  });
  window.Alpine = Alpine;
  Alpine.start();
});
